<?php

namespace Drupal\sthira\Data;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use GuzzleHttp\ClientInterface;

/**
 * "Ask the data": a plain-English request becomes one SQL query.
 *
 * 1. Rules read the request (what to count or total, grouped by what,
 *    filtered by which project, supplier, material, stage, date or amount)
 *    and write the SQL themselves. Fast, and always the same answer.
 * 2. Anything the rules do not understand goes to a small local model in
 *    Ollama, which is given the list of views and their columns.
 * 3. Every query, from either side, passes the same safety check: one
 *    SELECT, only the v_* views, no writes, at most 200 rows.
 */
class DataAsk {

  const LIMIT = 200;

  const NUMBERS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

  const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

  /**
   * Words that usually need more than the rules can do.
   */
  const HARD = ['percentage', 'percent', 'ratio', 'compare', 'comparison', 'versus', 'vs', 'difference', 'without', 'never', 'except', 'neither', 'second', 'between', 'having', 'didnt', 'haven', 'havent', 'same'];

  /**
   * Generic words that cannot name one project or supplier on their own.
   */
  /**
   * Words that mean our own staff (not supplier logins).
   */
  const EMPLOYEE_WORDS = ['employees', 'employee', 'staff', 'staff members', 'our team', 'team', 'team members', 'colleagues', 'workforce', 'headcount', 'head count', 'workers', 'our people', 'internal users', 'in house', 'in-house'];

  const GENERIC = ['tower', 'project', 'traders', 'trader', 'steel', 'cement', 'building', 'materials', 'material', 'pipes', 'fittings', 'tiles', 'paints', 'iron', 'depot', 'industries', 'electricals', 'supplies', 'sanitation', 'stone', 'crushers', 'cables', 'wires', 'plumbing', 'distributors', 'bricks', 'blocks', 'aggregates', 'sand', 'shree', 'park', 'city'];

  protected ?array $entities = NULL;

  public function __construct(
    protected Connection $db,
    protected DataViews $views,
    protected Repository $repo,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $http,
  ) {}

  /**
   * Answers a request.
   *
   * @return array
   *   prompt, sql, source (rules|ai), explain, columns, rows, count, ms,
   *   error, note.
   */
  public function ask(string $prompt): array {
    $t0 = microtime(TRUE);
    $prompt = trim(mb_substr($prompt, 0, 300));
    $out = ['prompt' => $prompt, 'sql' => '', 'source' => '', 'explain' => '', 'columns' => [], 'rows' => [], 'count' => 0, 'ms' => 0, 'error' => NULL, 'note' => NULL, 'blocked' => FALSE];
    if ($prompt === '') {
      return $out;
    }
    $this->views->ensure();

    $hard = (bool) array_intersect($this->words($prompt), self::HARD);
    $plan = $this->rules($prompt);
    if ((!$plan || $hard) && $this->aiAvailable()) {
      $ai = $this->aiSql($prompt);
      if ($ai['sql']) {
        $out = $this->run($ai['sql'], 'ai', 'Written by the local AI model (' . $this->aiModel() . '). Check the query before you rely on it.', $out);
        // One retry when the database reported a mistake (not when the
        // safety check refused the query).
        if ($out['error'] && empty($out['blocked'])) {
          $retry = $this->aiSql($prompt, $ai['sql'], $out['error']);
          if ($retry['sql']) {
            $out = $this->run($retry['sql'], 'ai', 'Written by the local AI model (' . $this->aiModel() . ') on a second try. Check the query before you rely on it.', $out);
          }
        }
        if (!$out['error'] || !$plan) {
          $out = $this->compose($out, NULL);
          $out['next'] = $this->followUps($prompt, $plan);
          $out['ms'] = (int) round((microtime(TRUE) - $t0) * 1000);
          return $out;
        }
      }
    }
    if ($hard && !$this->aiAvailable()) {
      $words = implode('", "', array_intersect(self::HARD, $this->words($prompt)));
      $out['error'] = 'This question needs the local AI model (it uses "' . $words . '", which the built-in rules do not handle).';
      $out['note'] = 'Switch it on with: ddev exec -s ollama ollama pull ' . $this->aiModel() . '. Or ask it in simpler parts, for example "suppliers" and "winning bids".';
      $out['ms'] = (int) round((microtime(TRUE) - $t0) * 1000);
      return $out;
    }
    if (!$plan) {
      $out['error'] = 'I could not turn that into a query.';
      $out['note'] = $this->aiAvailable()
        ? 'The local AI model could not write a query for it either. Try naming what you want: requests, bids, suppliers, projects or materials.'
        : 'Try naming what you want (requests, bids, suppliers, projects, materials) and how (total, count, average, per project, per month). For unusual questions, switch on the local AI model: ddev exec -s ollama ollama pull ' . $this->aiModel();
      $out['ms'] = (int) round((microtime(TRUE) - $t0) * 1000);
      return $out;
    }
    $out = $this->run($plan['sql'], 'rules', $plan['explain'], $out);
    // The breakdown behind a single number.
    if (!$out['error'] && !empty($plan['breakdown'])) {
      $b = $this->run($plan['breakdown']['sql'], 'rules', '', ['sql' => '']);
      if (!$b['error'] && count($b['rows']) > 1) {
        $out['breakdown'] = ['label' => $plan['breakdown']['label'], 'subject' => $plan['subject'], 'sql' => $b['sql'], 'rows' => $b['rows'], 'money' => self::isMoney($out['columns'][0] ?? '')];
      }
    }
    $out = $this->compose($out, $plan);
    $out['next'] = $this->followUps($prompt, $plan);
    $out['ms'] = (int) round((microtime(TRUE) - $t0) * 1000);
    return $out;
  }

  /* ---------------------------------------------------------------------
   * The answer in words.
   * ------------------------------------------------------------------- */

  /**
   * True for columns that hold rupees.
   */
  public static function isMoney(string $col): bool {
    return (bool) preg_match('/(value|amount|estimate|budget|savings|spend|^rate$|_rate$|standard_rate)/', $col)
      && !preg_match('/(rating|lead|quantity|qty|pct|days|count|bids|requests|suppliers)/', $col);
  }

  /**
   * A value as people read it: ₹12.5 lakh, 4.3, Nov 2026.
   */
  public function fmt(string $col, $v): string {
    if ($v === NULL || $v === '') {
      return '–';
    }
    if (is_numeric($v) && self::isMoney($col)) {
      return Format::short($v);
    }
    if (is_numeric($v)) {
      $f = (float) $v;
      return floor($f) == $f ? number_format($f) : rtrim(rtrim(number_format($f, 2), '0'), '.') . (str_ends_with($col, 'pct') ? '%' : '');
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', (string) $v, $m)) {
      return date('M Y', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]));
    }
    return (string) $v;
  }

  /**
   * "a, b and c".
   */
  protected function join(array $items): string {
    $items = array_values($items);
    return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : (string) ($items[0] ?? '');
  }

  /**
   * Adds 'headline' (a big number, or NULL) and 'answer' (one sentence)
   * from whatever the query returned, rules or AI.
   */
  public function compose(array $out, ?array $plan): array {
    $out['headline'] = NULL;
    $out['answer'] = NULL;
    $rows = $out['rows'] ?? [];
    $cols = $out['columns'] ?? [];
    if (!empty($out['error']) || !$rows || !$cols) {
      return $out;
    }
    [$one, $many] = $plan['noun'] ?? ['row', 'rows'];
    $numeric = fn($c) => is_numeric($rows[0][$c] ?? NULL) && !in_array($c, ['id', 'ref', 'code', 'username'], TRUE);

    // One number.
    if (count($rows) === 1 && count($cols) === 1 && $numeric($cols[0])) {
      $c = $cols[0];
      $v = $rows[0][$c];
      $out['headline'] = $this->fmt($c, $v);
      $parts = [];
      foreach ($out['breakdown']['rows'] ?? [] as $r) {
        $vals = array_values($r);
        $parts[] = $this->fmt('x', $vals[0]) . ' (' . $this->fmt($c, $vals[1]) . ')';
      }
      if (($plan['agg'] ?? 'count') === 'count' && !str_starts_with($c, 'total_') && !str_starts_with($c, 'average_')) {
        $n = (float) $v;
        $what = $out['headline'] . ' ' . ($n == 1 ? $one : $many);
        $lead = rtrim(preg_replace('/^Number of [\w ]+?(,|\.$)/', '', (string) $out['explain']), '.');
        $out['answer'] = ($plan && ($plan['subject'] ?? '') === 'people' ? 'We have ' : 'There ' . ($n == 1 ? 'is ' : 'are ')) . $what
          . ($lead !== '' && ($plan['subject'] ?? '') !== 'people' ? ' (' . trim($lead, ' ,') . ')' : '')
          . ($parts ? ': ' . $this->join($parts) : '') . '.';
      }
      else {
        $label = $plan ? str_replace(', ', ' ', rtrim((string) $out['explain'], '.')) : ucfirst(str_replace('_', ' ', $c));
        $out['answer'] = $label . ' is ' . $out['headline'] . ($parts ? (str_starts_with($c, 'average_') ? '. Highest: ' : '. Biggest: ') . $this->join(array_slice($parts, 0, 3)) : '') . '.';
      }
      return $out;
    }

    // A grouped table: a label column and a number column.
    if (count($cols) >= 2 && count($cols) <= 3 && !$numeric($cols[0]) && $numeric($cols[1]) && (count($cols) === 2 || !$numeric($cols[2]))) {
      [$g, $c] = $cols;
      $n = count($rows);
      $sum = array_sum(array_map(fn($r) => (float) $r[$c], $rows));
      $isTime = (bool) preg_match('/(_month|_on)$/', $g);
      $sorted = $rows;
      usort($sorted, fn($a, $b) => (float) $b[$c] <=> (float) $a[$c]);
      $top = $sorted[0];
      $groupWord = str_replace(['_label', '_'], ['', ' '], $g);
      $s = $isTime
        ? 'Highest in ' . $this->fmt($g, $top[$g]) . ' (' . $this->fmt($c, $top[$c]) . ')' . ($n > 1 ? '; latest ' . $this->fmt($g, end($rows)[$g]) . ' (' . $this->fmt($c, end($rows)[$c]) . ')' : '')
        : $this->fmt($g, $top[$g]) . ' is highest with ' . $this->fmt($c, $top[$c]);
      $same = $n > 1 && (float) $sorted[0][$c] === (float) end($sorted)[$c];
      if ($same) {
        $label = $plan['group'] ?? $groupWord;
        $s = 'All ' . $n . ' ' . $label . (str_ends_with($label, 's') ? '' : 's') . ' have ' . $this->fmt($c, $top[$c]) . ' ' . ((float) $top[$c] == 1 ? $one : $many) . ' each';
      }
      elseif (!$isTime && $n > 1) {
        $next = array_map(fn($r) => $this->fmt($g, $r[$g]) . ' (' . $this->fmt($c, $r[$c]) . ')', array_slice($sorted, 1, 2));
        $s .= ', followed by ' . $this->join($next);
      }
      if ($n > 1 && !$same && !str_starts_with($c, 'average_')) {
        $s .= '. ' . ($isTime ? 'Total ' : 'Total across all ' . $n . ': ') . $this->fmt($c, $sum);
      }
      $out['answer'] = $s . '.';
      return $out;
    }

    // A list: name the first few.
    $nameCol = NULL;
    foreach (['name', 'ref', 'request_ref', 'project', 'supplier', 'message'] as $try) {
      if ($try === 'project' && in_array('manager', $cols, TRUE) && $cols[0] === 'manager') {
        $try = 'manager';
      }
      if (in_array($try, $cols, TRUE)) {
        $nameCol = $try;
        break;
      }
    }
    $nameCol = $nameCol ?? $cols[0];
    $n = count($rows);
    $names = array_values(array_unique(array_map(fn($r) => $this->fmt($nameCol, $r[$nameCol] ?? ''), $rows)));
    // A project with two managers is still one project.
    if (in_array($nameCol, ['project', 'manager'], TRUE)) {
      $n = count($names);
    }
    $count = $n >= 200 ? '200+' : (string) $n;
    if ($nameCol === 'message') {
      $out['answer'] = $count . ' ' . ($n == 1 ? $one : $many) . '. Latest: ' . rtrim($names[0], '.') . '.';
      return $out;
    }
    $out['answer'] = $n === 1
      ? 'One ' . $one . ': ' . $names[0] . '.'
      : $count . ' ' . $many . ($n <= 5 ? ': ' . $this->join($names) : ', for example ' . $this->join(array_slice($names, 0, 3))) . '.';
    return $out;
  }

  /* ---------------------------------------------------------------------
   * Questions to try: built from the live data, so they change with it.
   * ------------------------------------------------------------------- */

  /**
   * Names to put into example questions.
   */
  protected function samples(): array {
    $e = $this->entities();
    $short = fn($name) => trim(explode('–', $name)[0]);
    $first = [];
    try {
      foreach ($this->db->query("SELECT DISTINCT p.name FROM v_people p JOIN v_activity a ON a.username = p.username WHERE p.kind = 'Employee'")->fetchCol() as $name) {
        $first[] = mb_strtolower(explode(' ', $name)[0]);
      }
    }
    catch (\Throwable $ex) {
    }
    $materials = array_map(fn($m) => mb_strtolower(trim(preg_replace('/\s*\(.*$/', '', $m))), array_keys($e['materials']));
    return [
      'project' => array_map($short, array_keys($e['projects'])),
      'supplier' => array_keys($e['suppliers']),
      'category' => array_map('mb_strtolower', array_keys($e['categories'])),
      'material' => $materials,
      'city' => array_values($e['cities']),
      'person' => array_values(array_unique($first)),
      'pm' => $this->firstNames("role = 'Project Manager'"),
      'role' => array_values(array_map(fn($r) => mb_strtolower($r) . 's', array_diff(array_keys($this->roleMap()), ['Supplier', 'Administrator']))),
    ];
  }

  protected function firstNames(string $where): array {
    try {
      return array_values(array_unique(array_map(fn($n) => mb_strtolower(explode(' ', $n)[0]), $this->db->query('SELECT name FROM v_people WHERE ' . $where)->fetchCol())));
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Fills {project}, {category} ... with real names. $pick chooses which.
   */
  protected function fill(string $template, array $samples, int $seed, array $prefer = []): ?string {
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($samples, $seed, $prefer) {
      if (!empty($prefer[$m[1]])) {
        return $prefer[$m[1]];
      }
      $list = array_values($samples[$m[1]] ?? []);
      return $list ? $list[abs($seed + crc32($m[1])) % count($list)] : $m[0];
    }, $template);
  }

  const TRY_QUESTIONS = [
    'how many employees do we have', 'who are our {role}', 'how many projects does each pm have', 'which projects does {pm} manage', 'total spend per project', 'total spend for {project}',
    'top 5 suppliers by order value', 'how many requests in each stage', 'monthly spend', 'which suppliers sell {category}',
    'average bid rate per supplier for {material}', 'requests waiting for delivery', 'bills that do not match',
    'savings from bidding per project', 'suppliers in {city}', 'suppliers with rating above 4.3', 'how many bids did each supplier win',
    'what did {person} do', 'urgent requests', 'material budget per project',
  ];

  const NEXT = [
    'people' => ['employees per role', 'list all employees', 'how many projects does each pm have', 'who are our {role}', 'how many supplier logins', 'who joined in the last 30 days'],
    'requests' => ['how many requests in each stage', 'total spend per project', 'monthly spend', 'total spend for {project}', 'requests waiting for delivery', 'top 5 suppliers by order value', 'urgent requests'],
    'bids' => ['how many bids did each supplier win', 'average bid rate per supplier for {material}', 'bids in the last 30 days', 'how many bids per supplier'],
    'suppliers' => ['suppliers per city', 'which suppliers sell {category}', 'suppliers in {city}', 'suppliers with rating above 4.3', 'top 5 suppliers by order value'],
    'supplier_categories' => ['which suppliers sell {category}', 'suppliers per city', 'suppliers in {city}', 'suppliers with rating above 4.3'],
    'projects' => ['projects per city', 'material budget per project', 'total spend per project', 'savings from bidding per project', 'total spend for {project}'],
    'materials' => ['materials per category', 'most expensive materials', 'which suppliers sell {category}', 'average bid rate per supplier for {material}'],
    'team' => ['how many projects does each pm have', 'which projects does {pm} manage', 'who manages {project}', 'list all projects with their managers'],
    'activity' => ['what did {person} do', 'activity today', 'activity per person', 'activity in the last 7 days'],
  ];

  /**
   * A few example questions, different on each visit.
   */
  public function examples(int $n = 12): array {
    $samples = $this->samples();
    $seed = random_int(0, 100000);
    $list = self::TRY_QUESTIONS;
    shuffle($list);
    $out = [];
    foreach ($list as $t) {
      $q = $this->fill($t, $samples, $seed + count($out));
      if ($q && !str_contains($q, '{') && !in_array($q, $out, TRUE)) {
        $out[] = $q;
      }
      if (count($out) >= $n) {
        break;
      }
    }
    return $out;
  }

  /**
   * Questions to ask next, on the same topic and the same names.
   */
  public function followUps(string $prompt, ?array $plan, int $n = 4): array {
    $subject = $plan['subject'] ?? 'requests';
    $f = $plan['filters'] ?? [];
    $prefer = array_filter([
      'project' => isset($f['projects'][0]) ? trim(explode('–', $f['projects'][0])[0]) : NULL,
      'category' => isset($f['categories'][0]) ? mb_strtolower($f['categories'][0]) : NULL,
      'city' => $f['cities'][0] ?? NULL,
      'material' => isset($f['materials'][0]) ? mb_strtolower(trim(preg_replace('/\s*\(.*$/', '', $f['materials'][0]))) : NULL,
    ]);
    $samples = $this->samples();
    $seed = crc32($prompt);
    $have = mb_strtolower(trim($prompt));
    $out = [];
    foreach (self::NEXT[$subject] ?? self::NEXT['requests'] as $t) {
      $q = $this->fill($t, $samples, $seed, $prefer);
      if ($q && !str_contains($q, '{') && $q !== $have && !in_array($q, $out, TRUE)) {
        $out[] = $q;
      }
      if (count($out) >= $n) {
        break;
      }
    }
    return $out;
  }

  /* ---------------------------------------------------------------------
   * Running a query safely.
   * ------------------------------------------------------------------- */

  /**
   * Checks a query. Returns the query to run, or throws with the reason.
   */
  public function guard(string $sql): string {
    $sql = trim($sql);
    $sql = preg_replace('/;\s*$/', '', $sql);
    if ($sql === '') {
      throw new \InvalidArgumentException('The query is empty.');
    }
    if (str_contains($sql, ';')) {
      throw new \InvalidArgumentException('Only one query at a time.');
    }
    if (preg_match('/--|\/\*|#/', $sql)) {
      throw new \InvalidArgumentException('Comments are not allowed in the query.');
    }
    if (!preg_match('/^\s*(select|with)\b/i', $sql)) {
      throw new \InvalidArgumentException('Only SELECT queries can run here.');
    }
    if (preg_match('/\b(insert|update|delete|drop|alter|create|replace|truncate|grant|revoke|attach|detach|pragma|vacuum|reindex|call|exec|execute|handler|lock|unlock|set|load_file|outfile|dumpfile|sleep|benchmark|into|rename|shutdown|kill)\b/i', $sql, $m)) {
      throw new \InvalidArgumentException('The word "' . strtoupper($m[1]) . '" is not allowed: this page only reads data.');
    }
    if (preg_match('/\b(information_schema|performance_schema|mysql|sys|sqlite_master|sqlite_schema|sqlite_temp_master)\b/i', $sql)) {
      throw new \InvalidArgumentException('System tables cannot be queried here.');
    }
    // Every table after FROM / JOIN must be one of our views or a name the
    // query defines itself (WITH x AS ...).
    $allowed = array_keys(DataViews::catalog());
    preg_match_all('/\bwith\s+([a-z_][a-z0-9_]*)\s+as\b|,\s*([a-z_][a-z0-9_]*)\s+as\s*\(/i', $sql, $ctes);
    $allowed = array_merge($allowed, array_map('strtolower', array_filter(array_merge($ctes[1], $ctes[2]))));
    preg_match_all('/\b(?:from|join)\s+(?!\()`?([a-z_][a-z0-9_.]*)`?/i', $sql, $m);
    foreach ($m[1] as $table) {
      if (!in_array(strtolower($table), $allowed, TRUE)) {
        throw new \InvalidArgumentException('"' . $table . '" is not one of the views this page can read (' . implode(', ', array_keys(DataViews::catalog())) . ').');
      }
    }
    // No real table may be named anywhere (catches "FROM a, b" and
    // subqueries too); only the views.
    $bare = strtolower(preg_replace("/'(?:[^']|'')*'/", "''", $sql));
    preg_match_all('/[a-z_][a-z0-9_]*/', $bare, $idents);
    $tables = $this->realTables();
    foreach (array_unique($idents[0]) as $ident) {
      if (isset($tables[$ident])) {
        throw new \InvalidArgumentException('"' . $ident . '" is a system table; this page can only read the views (' . implode(', ', array_keys(DataViews::catalog())) . ').');
      }
    }
    // At most 200 rows.
    if (preg_match('/\blimit\s+(\d+)\s*$/i', $sql, $lm)) {
      if ((int) $lm[1] > self::LIMIT) {
        $sql = preg_replace('/\blimit\s+\d+\s*$/i', 'LIMIT ' . self::LIMIT, $sql);
      }
    }
    elseif (!preg_match('/\blimit\s+\d+\s*(,|offset)\s*\d+\s*$/i', $sql)) {
      $sql .= "\nLIMIT " . self::LIMIT;
    }
    return $sql;
  }

  /**
   * Names of the real tables in the database (views excluded).
   */
  protected function realTables(): array {
    static $tables = NULL;
    if ($tables === NULL) {
      $tables = [];
      try {
        $names = $this->views->isMysql()
          ? $this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchCol()
          : $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchCol();
        foreach ($names as $t) {
          $tables[strtolower($t)] = TRUE;
        }
      }
      catch (\Throwable $e) {
        // Fall back to the FROM / JOIN check alone.
      }
      foreach (array_keys(DataViews::catalog()) as $view) {
        unset($tables[$view]);
      }
    }
    return $tables;
  }

  protected function run(string $sql, string $source, string $explain, array $out): array {
    $out['source'] = $source;
    $out['explain'] = $explain;
    $out['error'] = NULL;
    $out['blocked'] = FALSE;
    try {
      $sql = $this->guard($sql);
      $out['sql'] = $sql . ';';
      $exec = $sql;
      // A time limit, on MariaDB.
      if (method_exists($this->db, 'isMariaDb') && $this->db->isMariaDb()) {
        $exec = 'SET STATEMENT max_statement_time=5 FOR ' . $sql;
      }
      $rows = $this->db->query($exec)->fetchAll(\PDO::FETCH_ASSOC);
      $out['rows'] = $rows;
      $out['count'] = count($rows);
      $out['columns'] = $rows ? array_keys($rows[0]) : $this->columnsOf($sql);
    }
    catch (\InvalidArgumentException $e) {
      $out['sql'] = rtrim(trim($sql), ';') . ';';
      $out['error'] = 'Not run: ' . $e->getMessage();
      $out['blocked'] = TRUE;
    }
    catch (\Throwable $e) {
      $out['sql'] = $out['sql'] ?: rtrim(trim($sql), ';') . ';';
      $msg = $e->getMessage();
      $out['error'] = 'The database could not run this query: ' . trim(preg_replace('/^SQLSTATE\[\w+\]:?\s*/', '', explode(': SELECT', explode(': WITH', $msg)[0])[0]));
    }
    return $out;
  }

  /**
   * Column names from "SELECT a, b AS c" when there are no rows.
   */
  protected function columnsOf(string $sql): array {
    if (!preg_match('/^\s*select\s+(.*?)\s+from\s/is', $sql, $m)) {
      return [];
    }
    $cols = [];
    foreach (preg_split('/,(?![^(]*\))/', $m[1]) as $part) {
      $part = trim($part);
      $cols[] = preg_match('/\bas\s+([a-z_][a-z0-9_]*)$/i', $part, $a) ? $a[1] : preg_replace('/^.*\./', '', $part);
    }
    return $cols;
  }

  /* ---------------------------------------------------------------------
   * The rules.
   * ------------------------------------------------------------------- */

  protected function words(string $text): array {
    $text = mb_strtolower($text);
    $text = str_replace(["'", '’'], '', $text);
    return preg_split('/[^a-z0-9.\-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
  }

  protected function has(string $text, array $phrases): bool {
    foreach ($phrases as $p) {
      if (preg_match('/(?<![a-z])' . preg_quote($p, '/') . '(?![a-z])/', $text)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Names the rules can recognise, read from the data.
   */
  protected function entities(): array {
    if ($this->entities !== NULL) {
      return $this->entities;
    }
    $e = ['projects' => [], 'suppliers' => [], 'materials' => [], 'categories' => [], 'cities' => [], 'people' => []];
    $count = function (array $names) {
      $n = [];
      foreach ($names as $name) {
        foreach (array_unique($this->words($name)) as $w) {
          $n[$w] = ($n[$w] ?? 0) + 1;
        }
      }
      return $n;
    };
    $projects = $this->repo->projects();
    $pWords = $count(array_column($projects, 'name'));
    foreach ($projects as $p) {
      $aliases = [mb_strtolower($p['name']), mb_strtolower(trim(explode('–', $p['name'])[0])), mb_strtolower($p['code'])];
      foreach ($this->words($p['name']) as $w) {
        if (strlen($w) >= 5 && ($pWords[$w] ?? 0) === 1 && !in_array($w, self::GENERIC, TRUE)) {
          $aliases[] = $w;
        }
      }
      $e['projects'][$p['name']] = array_values(array_unique($aliases));
      $e['cities'][mb_strtolower($p['location'])] = $p['location'];
    }
    $vendors = $this->repo->vendors();
    $vWords = $count(array_column($vendors, 'name'));
    foreach ($vendors as $v) {
      $aliases = [mb_strtolower($v['name'])];
      foreach ($this->words($v['name']) as $w) {
        if (strlen($w) >= 3 && ($vWords[$w] ?? 0) === 1 && !in_array($w, self::GENERIC, TRUE) && !in_array($w, ['and', '&'], TRUE)) {
          $aliases[] = $w;
        }
      }
      $e['suppliers'][$v['name']] = array_values(array_unique($aliases));
      if (!empty($v['city'])) {
        $e['cities'][mb_strtolower($v['city'])] = $v['city'];
      }
    }
    foreach (Repository::CATEGORIES as $label) {
      $e['categories'][$label] = [mb_strtolower($label), str_replace(' & ', ' and ', mb_strtolower($label))];
    }
    foreach ($this->repo->items() as $i) {
      $aliases = [mb_strtolower($i['name']), mb_strtolower(trim(preg_replace('/\s*\(.*$/', '', $i['name'])))];
      foreach (array_map('trim', explode(',', (string) ($i['keywords'] ?? ''))) as $k) {
        if ($k !== '' && !in_array($k, ['steel', 'cement', 'plumbing'], TRUE)) {
          $aliases[] = mb_strtolower($k);
        }
      }
      $e['materials'][$i['name']] = ['aliases' => array_values(array_unique($aliases)), 'category' => Repository::CATEGORIES[$i['category']] ?? $i['category']];
    }
    foreach ((array) \Drupal::state()->get('sthira.people', []) as $info) {
      if (!empty($info['username']) && !empty($info['name'])) {
        $first = mb_strtolower(explode(' ', $info['name'])[0]);
        // Role-like login names (procurement, pm2, director) are not names.
        $aliases = [mb_strtolower($info['name']), $first];
        if (!preg_match('/^(procurement|director|pm|admin|manager)\d*$/', mb_strtolower($info['username']))) {
          $aliases[] = mb_strtolower($info['username']);
        }
        $e['people'][$info['username']] = array_values(array_unique($aliases));
      }
    }
    return $this->entities = $e;
  }

  /**
   * Finds which of the named things appear in the text (longest first).
   */
  protected function find(string $text, array $map): array {
    $found = [];
    foreach ($map as $key => $aliases) {
      $aliases = $aliases['aliases'] ?? $aliases;
      usort($aliases, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
      foreach ($aliases as $a) {
        if ($a !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($a, '/') . '(?![a-z0-9])/u', $text)) {
          $found[] = $key;
          break;
        }
      }
    }
    return $found;
  }

  protected function q(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  protected function list(array $values): string {
    return implode(', ', array_map([$this, 'q'], $values));
  }

  /**
   * Reads the request and writes SQL, or returns NULL.
   */
  public function rules(string $prompt): ?array {
    $text = ' ' . mb_strtolower(str_replace(['’', "'"], '', $prompt)) . ' ';
    $text = preg_replace('/\s+/', ' ', $text);
    // "savings from bidding" is about money saved, not the bidding stage.
    $text = preg_replace('/\b(from|through|by|via|thanks to) bidding\b/', 'bidsaving', $text);
    $text = preg_replace('/\bdo not\b|\bdont\b|\bdoesnt\b|\bdoes not\b/', 'not', $text);
    $words = $this->words($prompt);
    $e = $this->entities();
    $signals = 0;

    // Named things.
    $ref = preg_match('/\bmr-?\s?(\d{3,6})\b/i', $prompt, $rm) ? 'MR-' . $rm[1] : NULL;
    $projects = $this->find($text, $e['projects']);
    $suppliers = $this->find($text, $e['suppliers']);
    $people = $this->find($text, $e['people']);
    $cities = array_values(array_intersect_key($e['cities'], array_flip($this->find($text, array_map(fn($c) => [$c], array_combine(array_keys($e['cities']), array_keys($e['cities'])))))));
    $categories = $this->find($text, $e['categories']);
    // A bare category word ("steel", "cement") is the category, not one item.
    foreach (['steel' => 'Steel', 'cement' => 'Cement', 'aggregates' => 'Aggregates', 'electrical' => 'Electrical', 'plumbing' => 'Plumbing', 'finishing' => 'Finishing'] as $w => $label) {
      if ($this->has($text, [$w]) && !in_array($label, $categories, TRUE)) {
        $categories[] = $label;
      }
    }
    $materials = $this->find($text, $e['materials']);
    // Do not read a supplier's own name as a material ("Prism Tiles & Paints").
    if ($suppliers) {
      $supplierText = implode(' ', array_map('mb_strtolower', $suppliers));
      $materials = array_values(array_filter($materials, function ($m) use ($e, $supplierText, $text) {
        foreach ($e['materials'][$m]['aliases'] as $a) {
          if (str_contains($text, $a) && !str_contains($supplierText, $a)) {
            return TRUE;
          }
        }
        return FALSE;
      }));
    }
    // A person who is also a supplier contact is read as the supplier.
    $people = array_values(array_filter($people, fn($u) => !($suppliers && in_array($u, array_map(fn($s) => $e['suppliers'][$s][1] ?? '', $suppliers), TRUE))));

    // What is being asked about.
    $moneyWords = ['spend', 'spent', 'spending', 'paid', 'payment', 'payments', 'order value', 'orders', 'ordered', 'purchase', 'purchases', 'business', 'cost', 'value', 'amount', 'bill', 'bills', 'invoice', 'invoices', 'saving', 'savings', 'saved', 'estimate'];
    $subject = 'requests';
    $roles = [];
    foreach ($this->roleMap() as $label => $phrases) {
      if ($this->has($text, $phrases)) {
        $roles[] = $label;
      }
    }
    // Groups of people by meaning: our own staff, or supplier logins.
    $employees = !$roles && $this->has($text, self::EMPLOYEE_WORDS);
    $peopleWords = array_merge(self::EMPLOYEE_WORDS, ['people', 'users', 'user', 'members', 'logins', 'login', 'accounts', 'roles', 'role', 'who works', 'everyone', 'persons', 'person', 'joined']);
    $isPeople = ($roles || $this->has($text, $peopleWords) || ($people && $this->has($text, ['who is', 'whos', 'tell me about', 'details of', 'role of', 'what is the role'])))
      && !$this->has($text, ['request', 'requests', 'bid', 'bids', 'spend', 'spent', 'paid', 'order', 'orders', 'activity', 'action', 'actions', 'log', 'did', 'raised', 'approved', 'delivered']);
    // Who runs which project: the project team.
    $team = $this->team($text, $roles, $people, $projects, $cities, $moneyWords);
    if ($team) {
      return $team;
    }
    $bidStage = $this->has($text, ['open for bids', 'out for bids', 'collecting bids', 'for bidding', 'in bidding', 'still bidding', 'ready for bids']) && !$this->has($text, ['bids from', 'bid rate', 'bids by', 'how many bids', 'number of bids']);
    if ($isPeople) {
      $subject = 'people';
    }
    elseif ($this->has($text, ['activity', 'log', 'logs', 'history', 'audit', 'actions', 'what did', 'who did', 'happened'])) {
      $subject = 'activity';
    }
    elseif (!$bidStage && $this->has($text, ['bid', 'bids', 'quote', 'quotes', 'quotation', 'quotations', 'offer', 'offers', 'bidder', 'bidders'])) {
      $subject = 'bids';
    }
    elseif ($this->has($text, ['sell', 'sells', 'selling', 'supply', 'supplies', 'supplying', 'provide', 'provides', 'deal in', 'deals in']) && !$this->has($text, $moneyWords)) {
      $subject = 'supplier_categories';
    }
    elseif ($this->has($text, ['supplier', 'suppliers', 'vendor', 'vendors', 'seller', 'sellers', 'companies', 'company']) && !$this->has($text, $moneyWords) && !$this->has($text, ['request', 'requests'])) {
      $subject = 'suppliers';
    }
    elseif ($this->has($text, ['project', 'projects', 'site', 'sites']) && !$this->has($text, $moneyWords) && !$this->has($text, ['request', 'requests', 'needed', 'need', 'requirement', 'requirements'])
      && ($this->has($text, ['budget', 'budgets', 'progress', 'client', 'clients', 'contract', 'phase', 'finish', 'deadline', 'start', 'started', 'list', 'all', 'show', 'which', 'what', 'how many', 'count', 'in']) || count($words) <= 3)) {
      $subject = 'projects';
    }
    elseif ($this->has($text, ['material', 'materials', 'item', 'items', 'catalog', 'catalogue', 'standard rate', 'price list', 'what we buy']) && !$this->has($text, $moneyWords) && !$this->has($text, ['request', 'requests', 'needed', 'need'])) {
      $subject = 'materials';
    }
    if ($subject === 'suppliers' && $this->has($text, ['lead', 'lead days', 'lead time', 'delivery days', 'bid rate', 'rate', 'rates', 'price', 'prices', 'quoted'])) {
      $subject = 'bids';
    }
    if ($this->has($text, ['budget', 'budgets', 'progress', 'contract value']) && $subject === 'requests' && !$this->has($text, ['request', 'requests', 'spend', 'spent', 'order'])) {
      $subject = 'projects';
    }
    if ($subject !== 'requests' || $this->has($text, ['request', 'requests', 'order', 'orders', 'purchase', 'purchases', 'spend', 'spent', 'paid', 'bill', 'bills', 'deliver', 'delivered', 'delivery', 'deliveries', 'requirement', 'requirements', 'need', 'needed'])) {
      $signals++;
    }

    // How: count, total, average, highest, lowest.
    $agg = NULL;
    if ($this->has($text, ['how many', 'count', 'number of', 'no of', 'no. of'])) {
      $agg = 'count';
    }
    elseif ($this->has($text, ['average', 'avg', 'mean'])) {
      $agg = 'avg';
    }
    elseif ($this->has($text, ['total', 'sum', 'how much', 'spend', 'spent', 'spending', 'overall'])) {
      $agg = 'sum';
    }
    $direction = NULL;
    if ($this->has($text, ['highest', 'largest', 'biggest', 'most expensive', 'maximum', 'max', 'costliest', 'top', 'most', 'best', 'greatest'])) {
      $direction = 'DESC';
    }
    if ($this->has($text, ['lowest', 'smallest', 'cheapest', 'minimum', 'min', 'least', 'bottom', 'worst', 'fewest'])) {
      $direction = 'ASC';
    }
    $limit = NULL;
    if (preg_match('/\b(?:top|first|bottom|last)\s+(\d+|' . implode('|', array_keys(self::NUMBERS)) . ')\b(?!\s+(?:day|days|week|weeks|month|months|year|years)\b)/', $text, $tm) || preg_match('/\b(\d+|' . implode('|', array_keys(self::NUMBERS)) . ')\s+(?:highest|lowest|biggest|largest|cheapest|most|best|latest|recent|newest|oldest)\b/', $text, $tm)) {
      $limit = self::NUMBERS[$tm[1]] ?? (int) $tm[1];
      $direction = $direction ?? (preg_match('/\b(bottom|lowest|cheapest|oldest)\b/', $text) ? 'ASC' : 'DESC');
    }
    if ($agg || $direction || $limit) {
      $signals++;
    }

    // The number being measured.
    $metric = $this->metric($subject, $text);

    // Grouped by. "raised by project managers" is a filter, not a grouping.
    $groupText = preg_replace('/\b(raised|requested|created|made|placed) by (the )?(project managers?|pms?|procurement( officers?| team)?|directors?|admins?)\b/', '$1', $text);
    $groupText = str_replace(['project managers', 'project manager'], ['pms', 'pm'], $groupText);
    $group = $this->group($subject, $groupText);
    if ($group) {
      $signals++;
    }
    // "Top 5 suppliers by order value" = group by supplier, top 5.
    if (!$group && preg_match('/\b(?:top|bottom|first|best|worst)\s+(?:\d+\s+|' . implode('\s+|', array_keys(self::NUMBERS)) . '\s+)?(project|site|supplier|vendor|material|item|category|categories|city|cities)s?\b/', $text, $dm) && in_array($subject, ['requests', 'bids'], TRUE)) {
      $group = $this->group($subject, ' by ' . rtrim(str_replace(['categories', 'cities'], ['category', 'city'], $dm[1]), 's') . ' ');
      $direction = $direction ?? 'DESC';
      if ($group) {
        $signals++;
      }
    }
    // "Which supplier has the most orders" = group by supplier, top 1.
    if (!$group && $direction && preg_match('/\b(?:which|what)\s+(project|site|supplier|vendor|material|item|category|city|month)s?\b/', $text, $wm) && in_array($subject, ['requests', 'bids'], TRUE)) {
      $group = $this->group($subject, ' by ' . $wm[1] . ' ');
      $limit = $limit ?? 1;
      $signals++;
    }
    if ($group && !$agg) {
      $agg = $this->has($text, ['spend', 'spent', 'value', 'amount', 'paid', 'cost', 'budget', 'savings', 'saving']) || ($direction && !$this->has($text, ['orders', 'requests', 'bids', 'suppliers', 'count'])) ? 'sum' : 'count';
      if ($subject === 'bids' && $this->has($text, ['rate', 'price', 'cheapest', 'lowest', 'highest'])) {
        $agg = 'avg';
      }
      if (in_array($subject, ['suppliers', 'projects', 'materials', 'supplier_categories', 'activity', 'people'], TRUE) && !$this->has($text, ['total', 'sum', 'average', 'avg'])) {
        $agg = 'count';
      }
    }

    if ($subject === 'suppliers' && $group && $group[0] === 'category') {
      $subject = 'supplier_categories';
    }

    // Filters.
    $where = [];
    $explain = [];
    $view = [
      'requests' => 'v_requests', 'bids' => 'v_bids', 'suppliers' => 'v_suppliers', 'supplier_categories' => 'v_supplier_categories',
      'projects' => 'v_projects', 'materials' => 'v_materials', 'activity' => 'v_activity', 'people' => 'v_people',
    ][$subject];
    if (in_array($subject, ['requests', 'activity'], TRUE) && $roles && !$people) {
      $col = $subject === 'requests' ? 'raised_by' : 'username';
      $where[] = $col . ' IN (SELECT username FROM v_people WHERE role' . (count($roles) > 1 ? ' IN (' . $this->list($roles) . ')' : ' = ' . $this->q($roles[0])) . ')';
      $explain[] = ($subject === 'requests' ? 'raised by ' : 'by ') . implode(' and ', $roles) . 's';
    }
    if ($subject === 'people' && $roles) {
      $where[] = 'role' . (count($roles) > 1 ? ' IN (' . $this->list($roles) . ')' : ' = ' . $this->q($roles[0]));
      $explain[] = implode(' and ', $roles) . 's';
    }
    if ($subject === 'people' && $employees) {
      $where[] = "kind = 'Employee'";
      $explain[] = 'employees only (not supplier logins or admins)';
    }
    if ($subject === 'people' && $this->has($text, ['inactive', 'blocked', 'disabled', 'deactivated'])) {
      $where[] = 'active = 0';
      $explain[] = 'blocked logins';
    }
    elseif ($subject === 'people' && $this->has($text, ['active'])) {
      $where[] = 'active = 1';
      $explain[] = 'active logins';
    }
    $join = NULL;

    if ($ref && in_array($subject, ['requests', 'bids', 'activity'], TRUE)) {
      $where[] = ($subject === 'requests' ? 'ref' : 'request_ref') . ' = ' . $this->q($ref);
      $explain[] = 'for ' . $ref;
    }
    if ($projects) {
      $col = ['requests' => 'project', 'bids' => 'project', 'projects' => 'name'][$subject] ?? NULL;
      if ($col) {
        $where[] = $col . (count($projects) > 1 ? ' IN (' . $this->list($projects) . ')' : ' = ' . $this->q($projects[0]));
        $explain[] = 'for ' . implode(' and ', $projects);
      }
    }
    if ($suppliers) {
      $col = ['requests' => 'supplier', 'bids' => 'supplier', 'suppliers' => 'name', 'supplier_categories' => 'supplier'][$subject] ?? NULL;
      if ($col) {
        $where[] = $col . (count($suppliers) > 1 ? ' IN (' . $this->list($suppliers) . ')' : ' = ' . $this->q($suppliers[0]));
        $explain[] = 'from ' . implode(' and ', $suppliers);
      }
    }
    if ($materials && !($categories && count($materials) > 1)) {
      $col = ['requests' => 'material', 'bids' => 'material', 'materials' => 'name'][$subject] ?? NULL;
      if ($col) {
        $where[] = $col . (count($materials) > 1 ? ' IN (' . $this->list($materials) . ')' : ' = ' . $this->q($materials[0]));
        $explain[] = 'for ' . implode(' and ', $materials);
        $categories = [];
      }
      elseif (in_array($subject, ['suppliers', 'supplier_categories'], TRUE)) {
        $categories = array_values(array_unique(array_merge($categories, array_map(fn($m) => $e['materials'][$m]['category'], $materials))));
      }
    }
    if ($categories) {
      if ($subject === 'requests' || $subject === 'materials') {
        $where[] = 'category' . (count($categories) > 1 ? ' IN (' . $this->list($categories) . ')' : ' = ' . $this->q($categories[0]));
        $explain[] = 'in ' . implode(' and ', $categories);
      }
      elseif ($subject === 'bids') {
        $where[] = 'material IN (SELECT name FROM v_materials WHERE category' . (count($categories) > 1 ? ' IN (' . $this->list($categories) . ')' : ' = ' . $this->q($categories[0])) . ')';
        $explain[] = 'for ' . implode(' and ', $categories);
      }
      elseif ($subject === 'supplier_categories') {
        $where[] = 'category' . (count($categories) > 1 ? ' IN (' . $this->list($categories) . ')' : ' = ' . $this->q($categories[0]));
        $explain[] = 'selling ' . implode(' and ', $categories);
      }
      elseif ($subject === 'suppliers') {
        $join = 'JOIN v_supplier_categories sc ON sc.supplier = s.name';
        $where[] = 'sc.category' . (count($categories) > 1 ? ' IN (' . $this->list($categories) . ')' : ' = ' . $this->q($categories[0]));
        $explain[] = 'selling ' . implode(' and ', $categories);
      }
    }
    if ($cities && in_array($subject, ['requests', 'projects', 'suppliers'], TRUE)) {
      $where[] = ($join ? 's.' : '') . 'city' . (count($cities) > 1 ? ' IN (' . $this->list($cities) . ')' : ' = ' . $this->q($cities[0]));
      $explain[] = 'in ' . implode(' and ', $cities);
    }
    if ($people && in_array($subject, ['requests', 'activity', 'people'], TRUE)) {
      $where[] = ($subject === 'requests' ? 'raised_by' : 'username') . (count($people) > 1 ? ' IN (' . $this->list($people) . ')' : ' = ' . $this->q($people[0]));
      $info = (array) \Drupal::state()->get('sthira.people', []);
      $names = [];
      foreach ($people as $u) {
        foreach ($info as $p) {
          if (($p['username'] ?? '') === $u) {
            $names[] = $p['name'] . ' (' . $u . ')';
          }
        }
      }
      $explain[] = ($subject === 'requests' ? 'raised by ' : ($subject === 'people' ? '' : 'by ')) . implode(' and ', $names ?: $people);
    }
    [$stageWhere, $stageWords] = $this->stage($subject, $text, $metric);
    if ($stageWhere) {
      $where[] = $stageWhere;
      $explain[] = $stageWords;
    }
    if ($subject === 'requests' && $this->has($text, ['urgent', 'emergency', 'priority'])) {
      $where[] = "priority = 'urgent'";
      $explain[] = 'urgent only';
    }
    [$timeWhere, $timeWords] = $this->time($subject, $text, $metric);
    if ($timeWhere) {
      $where[] = $timeWhere;
      $explain[] = $timeWords;
    }
    [$numWhere, $numWords] = $this->threshold($text, $metric);
    if ($numWhere) {
      $where[] = $numWhere;
      $explain[] = $numWords;
    }
    $filtered = count($where);
    $signals += $filtered;
    if ($signals === 0) {
      return NULL;
    }

    // Build it.
    $alias = $join ? 's.' : '';
    $from = $view . ($join ? ' s' : '');
    $m = $metric['expr'];
    if ($agg && !$group) {
      // One number.
      $col = match ($agg) {
        'count' => 'COUNT(*) AS ' . ($subject === 'supplier_categories' ? 'suppliers' : str_replace('supplier_categories', 'suppliers', $subject)),
        'avg' => 'ROUND(AVG(' . $m . '), 2) AS average_' . $metric['name'],
        default => 'SUM(' . $m . ') AS total_' . $metric['name'],
      };
      if ($agg !== 'count') {
        $where[] = $metric['notnull'];
      }
      $sql = "SELECT $col\nFROM $from" . ($join ? "\n$join" : '') . $this->whereSql($where);
      $what = match ($agg) {
        'count' => 'Number of ' . ($subject === 'people' ? $this->noun($subject, $roles, $employees)[1] : str_replace('_', ' ', $subject)),
        'avg' => 'Average ' . $metric['label'],
        default => 'Total ' . $metric['label'],
      };
      $plan = ['sql' => $sql, 'explain' => $this->sentence($what, $explain), 'kind' => 'value', 'agg' => $agg, 'noun' => $this->noun($subject, $roles, $employees), 'subject' => $subject, 'filters' => compact('projects', 'suppliers', 'materials', 'categories', 'cities', 'people')];
      // A breakdown, so one number also says what it is made of.
      $dim = $agg === 'count'
        ? ['people' => 'role', 'requests' => 'stage_label', 'suppliers' => 'city', 'projects' => 'city', 'bids' => 'status', 'materials' => 'category', 'supplier_categories' => 'category', 'activity' => 'action'][$subject] ?? NULL
        : ['requests' => $projects ? 'material' : 'project', 'bids' => 'supplier', 'projects' => 'city', 'suppliers' => 'city', 'materials' => 'category'][$subject] ?? NULL;
      if ($dim === 'role' && $roles && count($roles) === 1) {
        $dim = NULL;
      }
      if ($dim) {
        $dsql = $alias . $dim;
        $bwhere = array_merge($where, [$dsql . ' IS NOT NULL']);
        $plan['breakdown'] = [
          'label' => str_replace('_label', '', $dim),
          'sql' => "SELECT $dsql,\n       " . preg_replace('/ AS \w+$/', ' AS value', $col) . "\nFROM $from" . ($join ? "\n$join" : '') . $this->whereSql($bwhere) . "\nGROUP BY $dsql\nORDER BY value DESC\nLIMIT 8",
        ];
      }
      return $plan;
    }
    if ($group) {
      [$gcol, $glabel] = $group;
      $gsql = $alias . $gcol;
      $col = match ($agg) {
        'avg' => 'ROUND(AVG(' . $m . '), 2) AS average_' . $metric['name'],
        'sum' => 'SUM(' . $m . ') AS total_' . $metric['name'],
        default => 'COUNT(*) AS ' . (in_array($subject, ['suppliers', 'supplier_categories'], TRUE) ? 'suppliers' : $subject),
      };
      $order = preg_replace('/^.* AS /', '', $col);
      if ($agg !== 'count') {
        $where[] = $metric['notnull'];
      }
      $where[] = $gsql . ' IS NOT NULL';
      $orderBy = in_array($gcol, ['order_month', 'raised_month', 'paid_month'], TRUE) || $gcol === 'bid_on' || $gcol === 'happened_on'
        ? "$gsql ASC" : "$order " . ($direction ?? 'DESC');
      if ($direction && in_array($gcol, ['order_month', 'raised_month', 'paid_month'], TRUE)) {
        $orderBy = "$order $direction";
      }
      $sql = "SELECT $gsql,\n       $col\nFROM $from" . ($join ? "\n$join" : '') . $this->whereSql($where) . "\nGROUP BY $gsql\nORDER BY $orderBy" . ($limit ? "\nLIMIT $limit" : '');
      $what = match ($agg) {
        'avg' => 'Average ' . $metric['label'],
        'sum' => 'Total ' . $metric['label'],
        default => 'Number of ' . str_replace('_', ' ', $subject === 'supplier_categories' ? 'suppliers' : $subject),
      };
      return ['sql' => $sql, 'explain' => $this->sentence($what . ' per ' . $glabel, $explain) . ($limit ? ' Top ' . $limit . '.' : ''), 'kind' => 'group', 'agg' => $agg, 'noun' => $this->noun($subject, $roles, $employees), 'group' => $glabel, 'subject' => $subject, 'filters' => compact('projects', 'suppliers', 'materials', 'categories', 'cities', 'people')];
    }

    // A list.
    $cols = $this->listColumns($subject, $metric, $stageWhere);
    if ($join) {
      $cols = array_map(fn($c) => $c === 'category' ? 'sc.category' : 's.' . $c, $cols);
    }
    $orderBy = ($direction || $limit) && $metric['sortable']
      ? $alias . $metric['expr'] . ' ' . ($direction ?? 'DESC')
      : $alias . $this->defaultOrder($subject);
    if (($direction || $limit) && $metric['sortable']) {
      $where[] = $metric['notnull'];
    }
    if (!$limit && ($direction === 'DESC' || $direction === 'ASC') && (preg_match('/\b(the|which|what)\s+(highest|lowest|biggest|largest|cheapest|most expensive|costliest|smallest)\b/', $text) || preg_match('/\b(highest|lowest|biggest|largest|cheapest|most expensive|costliest|smallest)\s+(bid|order|request|supplier|project|material|quote|rate|price|bill|invoice|payment|value|budget)\b(?!s)/', $text))) {
      $limit = 1;
    }
    if (!$limit && $direction && preg_match('/\bwhich\b/', $text) && !preg_match('/\b(which|what)\s+\w+s\b/', $text)) {
      $limit = 1;
    }
    if (!$limit && $subject === 'activity') {
      $limit = 50;
    }
    $sql = "SELECT " . implode(",\n       ", $cols) . "\nFROM $from" . ($join ? "\n$join" : '') . $this->whereSql($where) . "\nORDER BY $orderBy" . ($limit ? "\nLIMIT $limit" : '');
    $what = ucfirst(str_replace('_', ' ', $subject === 'supplier_categories' ? 'suppliers and what they sell' : $subject));
    if ($direction && $metric['sortable']) {
      $what .= ', ' . ($direction === 'DESC' ? 'highest' : 'lowest') . ' ' . $metric['label'] . ' first';
    }
    return ['sql' => $sql, 'explain' => $this->sentence($what, $explain) . ($limit ? ' ' . ($limit === 1 ? 'Only the first one.' : 'First ' . $limit . '.') : ''), 'kind' => 'list', 'agg' => NULL, 'noun' => $this->noun($subject, $roles, $employees), 'subject' => $subject, 'filters' => compact('projects', 'suppliers', 'materials', 'categories', 'cities', 'people')];
  }

  /**
   * Questions about which project manager runs which project.
   */
  protected function team(string $text, array $roles, array $people, array $projects, array $cities, array $moneyWords): ?array {
    // "project managers" alone is about people, not projects.
    $bare = preg_replace('/\bproject managers?\b/', 'pm', $text);
    $aboutProjects = $this->has($bare, ['project', 'projects', 'site', 'sites']) || $projects;
    $aboutManaging = $roles === ['Project Manager'] || $this->has($text, ['manage', 'manages', 'managed', 'managing', 'manager', 'managers', 'handle', 'handles', 'handling', 'handled', 'assigned', 'in charge', 'runs', 'run by', 'looks after', 'look after', 'responsible', 'team', 'work on', 'works on', 'working on']);
    $aboutManaging = $aboutManaging || ($people && !$this->has($text, ['did', 'do', 'done', 'activity', 'actions']));
    // Words inside a project's own name ("Business Park") do not count.
    $clean = $text;
    foreach ($projects as $name) {
      foreach ($this->entities()['projects'][$name] ?? [] as $alias) {
        $clean = str_replace($alias, ' ', $clean);
      }
    }
    if (!$aboutProjects || !$aboutManaging || ($roles && $roles !== ['Project Manager'])
      || $this->has($clean, $moneyWords) || $this->has($clean, ['request', 'requests', 'bid', 'bids', 'budget', 'budgets', 'progress', 'stock'])) {
      return NULL;
    }
    $filters = compact('projects', 'cities', 'people') + ['suppliers' => [], 'materials' => [], 'categories' => []];
    $base = ['subject' => 'team', 'filters' => $filters];
    $where = [];
    $explain = [];
    if ($people) {
      $where[] = 'username' . (count($people) > 1 ? ' IN (' . $this->list($people) . ')' : ' = ' . $this->q($people[0]));
      $info = [];
      foreach ((array) \Drupal::state()->get('sthira.people', []) as $p) {
        $info[$p['username'] ?? ''] = $p['name'] ?? '';
      }
      $explain[] = 'managed by ' . $this->join(array_map(fn($u) => $info[$u] ?? $u, $people));
    }
    if ($projects) {
      $where[] = 'project' . (count($projects) > 1 ? ' IN (' . $this->list($projects) . ')' : ' = ' . $this->q($projects[0]));
      $explain[] = 'for ' . implode(' and ', $projects);
    }
    if ($cities) {
      $where[] = 'city' . (count($cities) > 1 ? ' IN (' . $this->list($cities) . ')' : ' = ' . $this->q($cities[0]));
      $explain[] = 'in ' . implode(' and ', $cities);
    }
    $count = $this->has($text, ['how many', 'count', 'number of', 'no of']);
    $perProject = (bool) preg_match('/\b(each|per|every|by) (project|site)\b|\b(project|site) wise\b/', $text);
    $perManager = !$perProject && !$people && (preg_match('/\b(each|per|every|by|which|what) (pm|project manager|manager|person)s?\b|\bpm wise\b|\b(most|fewest|least) projects\b/', $text) || ($count && !$projects));
    if ($perProject) {
      $sql = "SELECT project,\n       COUNT(*) AS managers\nFROM v_project_team" . $this->whereSql($where) . "\nGROUP BY project\nORDER BY managers DESC, project";
      return $base + ['sql' => $sql, 'explain' => $this->sentence('Number of project managers per project', $explain), 'kind' => 'group', 'agg' => 'count', 'noun' => ['project manager', 'project managers'], 'group' => 'project'];
    }
    if ($perManager) {
      $sql = "SELECT manager,\n       COUNT(*) AS projects,\n       GROUP_CONCAT(code) AS project_codes\nFROM v_project_team" . $this->whereSql($where) . "\nGROUP BY manager\nORDER BY projects DESC, manager";
      if (!$this->views->isMysql()) {
        $sql = str_replace('GROUP_CONCAT(code)', "GROUP_CONCAT(code, ', ')", $sql);
      }
      else {
        $sql = str_replace('GROUP_CONCAT(code)', "GROUP_CONCAT(code ORDER BY code SEPARATOR ', ')", $sql);
      }
      return $base + ['sql' => $sql, 'explain' => $this->sentence('Number of projects per project manager', $explain), 'kind' => 'group', 'agg' => 'count', 'noun' => ['project', 'projects'], 'group' => 'project manager'];
    }
    if ($count) {
      $what = $projects ? ['project manager', 'project managers', 'managers'] : ['project', 'projects', 'projects'];
      $sql = "SELECT COUNT(*) AS {$what[2]}\nFROM v_project_team" . $this->whereSql($where);
      return $base + ['sql' => $sql, 'explain' => $this->sentence('Number of ' . $what[1], $explain), 'kind' => 'value', 'agg' => 'count', 'noun' => [$what[0], $what[1]]];
    }
    if ($people || !$projects) {
      $sql = "SELECT code,\n       project,\n       city,\n       manager\nFROM v_project_team" . $this->whereSql($where) . "\nORDER BY " . ($people ? 'code' : 'manager, code');
      return $base + ['sql' => $sql, 'explain' => $this->sentence('Projects and their project managers', $explain), 'kind' => 'list', 'agg' => NULL, 'noun' => ['project', 'projects']];
    }
    $sql = "SELECT manager,\n       username,\n       project\nFROM v_project_team" . $this->whereSql($where) . "\nORDER BY manager";
    return $base + ['sql' => $sql, 'explain' => $this->sentence('Project managers', $explain), 'kind' => 'list', 'agg' => NULL, 'noun' => ['project manager', 'project managers']];
  }

  /**
   * Words for one and many of what a question is about.
   */
  protected function noun(string $subject, array $roles, bool $employees): array {
    if ($subject === 'people') {
      if ($roles === ['Supplier']) {
        return ['supplier login', 'supplier logins'];
      }
      if (count($roles) === 1) {
        $one = mb_strtolower($roles[0]);
        return [$one, preg_match('/(s|sh|ch|x)$/', $one) ? $one . 'es' : $one . 's'];
      }
      return $employees ? ['employee', 'employees'] : ['person', 'people'];
    }
    return [
      'requests' => ['request', 'requests'],
      'bids' => ['bid', 'bids'],
      'suppliers' => ['supplier', 'suppliers'],
      'supplier_categories' => ['supplier', 'suppliers'],
      'projects' => ['project', 'projects'],
      'materials' => ['material', 'materials'],
      'activity' => ['action', 'actions'],
    ][$subject] ?? ['row', 'rows'];
  }

  /**
   * Role name => words that mean it. Roles are read from the data, so a new
   * role ("Site Engineer") is understood without changing this code.
   */
  protected function roleMap(): array {
    $extra = [
      'Procurement Officer' => ['procurement officer', 'procurement officers', 'procurement team', 'procurement staff', 'buyers', 'purchase officer', 'purchase officers', 'purchasing team'],
      'Project Manager' => ['pm', 'pms', 'site manager', 'site managers'],
      'Director' => ['management', 'boss', 'head'],
      'Supplier' => ['supplier login', 'supplier logins', 'supplier users', 'supplier accounts', 'vendor logins', 'vendor accounts', 'supplier contacts', 'vendor contacts', 'supplier people'],
      'Administrator' => ['admin', 'admins'],
    ];
    try {
      $labels = $this->db->query('SELECT DISTINCT role FROM v_people WHERE role IS NOT NULL')->fetchCol();
    }
    catch (\Throwable $e) {
      $labels = array_keys($extra);
    }
    $map = [];
    foreach ($labels as $label) {
      $low = mb_strtolower($label);
      // "supplier" alone means the supplier companies, not their logins.
      $own = $label === 'Supplier' ? [] : [$low, preg_match('/(s|sh|ch|x)$/', $low) ? $low . 'es' : $low . 's'];
      $map[$label] = array_values(array_unique(array_merge($own, $extra[$label] ?? [])));
    }
    return $map;
  }

  protected function whereSql(array $where): string {
    $where = array_values(array_unique(array_filter($where)));
    return $where ? "\nWHERE " . implode("\n  AND ", $where) : '';
  }

  protected function sentence(string $what, array $explain): string {
    return rtrim($what . ($explain ? ', ' . implode(', ', $explain) : ''), ', ') . '.';
  }

  /**
   * The number a question is about, per view.
   */
  protected function metric(string $subject, string $text): array {
    $m = fn($expr, $name, $label, $sortable = TRUE) => ['expr' => $expr, 'name' => $name, 'label' => $label, 'notnull' => (str_contains($expr, ' - ') ? 'order_value IS NOT NULL' : $expr . ' IS NOT NULL'), 'sortable' => $sortable];
    switch ($subject) {
      case 'bids':
        return $this->has($text, ['lead', 'days', 'delivery time', 'fastest', 'quickest', 'slowest']) ? $m('lead_days', 'lead_days', 'delivery days') : $m('rate', 'rate', 'bid rate');

      case 'suppliers':
        if ($this->has($text, ['on time', 'on-time', 'punctual', 'delay', 'late'])) {
          return $m('on_time_pct', 'on_time_pct', 'on-time %');
        }
        return $this->has($text, ['past orders', 'experience']) ? $m('past_orders', 'past_orders', 'past orders') : $m('rating', 'rating', 'rating');

      case 'projects':
        if ($this->has($text, ['progress', 'complete', 'completed', 'done', 'built'])) {
          return $m('progress_pct', 'progress_pct', 'progress %');
        }
        return $this->has($text, ['contract']) ? $m('contract_value', 'contract_value', 'contract value') : $m('material_budget', 'material_budget', 'material budget');

      case 'materials':
        return $m('standard_rate', 'standard_rate', 'standard rate');

      case 'supplier_categories':
      case 'activity':
      case 'people':
        return $m('id', 'id', 'entries', FALSE);

      default:
        if ($this->has($text, ['saving', 'savings', 'saved'])) {
          return $m('estimate - order_value', 'savings', 'savings from bidding');
        }
        if ($this->has($text, ['paid', 'payment', 'payments']) && !$this->has($text, ['unpaid', 'not paid', 'to pay', 'pending payment']) && $this->has($text, ['total', 'sum', 'how much', 'amount', 'average', 'avg', 'paid to', 'paid for', 'payments', 'per', 'by', 'each', 'monthly', 'highest', 'lowest', 'most', 'top'])) {
          return $m('paid_amount', 'paid_amount', 'amount paid');
        }
        if ($this->has($text, ['bill', 'bills', 'invoice', 'invoices', 'billed'])) {
          return $m('bill_amount', 'bill_amount', 'bill amount');
        }
        if ($this->has($text, ['estimate', 'estimated'])) {
          return $m('estimate', 'estimate', 'estimate');
        }
        if ($this->has($text, ['quantity', 'qty', 'how much material'])) {
          return $m('quantity', 'quantity', 'quantity');
        }
        if ($this->has($text, ['rate', 'price per', 'unit price'])) {
          return $m('order_rate', 'order_rate', 'order rate');
        }
        return $m('order_value', 'order_value', 'order value');
    }
  }

  /**
   * "per project", "by month", "each supplier", "project wise".
   */
  protected function group(string $subject, string $text): ?array {
    $dims = [
      'project' => ['project', 'projects', 'site', 'sites'],
      'supplier' => ['supplier', 'suppliers', 'vendor', 'vendors', 'seller', 'sellers'],
      'material' => ['material', 'materials', 'item', 'items'],
      'category' => ['category', 'categories', 'type', 'types', 'material type', 'material types'],
      'stage' => ['stage', 'stages', 'status', 'statuses', 'step', 'steps'],
      'month' => ['month', 'months'],
      'city' => ['city', 'cities', 'location', 'locations', 'place', 'places'],
      'priority' => ['priority', 'priorities'],
      'person' => ['person', 'people', 'user', 'users', 'manager', 'managers', 'pm', 'pms', 'raiser'],
      'action' => ['action', 'actions'],
      'role' => ['role', 'roles', 'job', 'jobs'],
      'request' => ['request', 'requests', 'order', 'orders', 'requirement'],
      'day' => ['day', 'days', 'date', 'dates'],
    ];
    $found = NULL;
    foreach ($dims as $dim => $ws) {
      $alts = implode('|', array_map(fn($w) => preg_quote($w, '/'), $ws));
      if (preg_match('/\b(?:per|by|each|every|for each|for every|across|grouped by|group by|split by|broken down by|breakdown by)\s+(?:' . $alts . ')\b/', $text)
        || preg_match('/\b(?:' . $alts . ')[\s-]?wise\b/', $text)) {
        $found = $dim;
        break;
      }
    }
    if (!$found && $this->has($text, ['monthly', 'month wise', 'month-wise', 'every month', 'each month', 'per month', 'trend'])) {
      $found = 'month';
    }
    if (!$found) {
      return NULL;
    }
    $metricText = $text;
    return match ([$subject, $found]) {
      ['requests', 'project'], ['bids', 'project'] => ['project', 'project'],
      ['requests', 'supplier'], ['bids', 'supplier'] => ['supplier', 'supplier'],
      ['requests', 'material'], ['bids', 'material'] => ['material', 'material'],
      ['requests', 'category'], ['materials', 'category'], ['supplier_categories', 'category'] => ['category', 'material type'],
      ['suppliers', 'category'] => ['category', 'material type'],
      ['requests', 'stage'] => ['stage_label', 'stage'],
      ['bids', 'stage'] => ['status', 'bid status'],
      ['suppliers', 'stage'] => ['status', 'status'],
      ['requests', 'month'] => match (TRUE) {
        $this->has($metricText, ['paid', 'payment', 'payments']) => ['paid_month', 'month paid'],
        $this->has($metricText, ['spend', 'spent', 'value', 'order', 'orders', 'ordered', 'purchase', 'purchases', 'savings', 'cost']) => ['order_month', 'month ordered'],
        default => ['raised_month', 'month raised'],
      },
      ['requests', 'city'], ['projects', 'city'], ['suppliers', 'city'] => ['city', 'city'],
      ['requests', 'priority'] => ['priority', 'priority'],
      ['requests', 'person'] => ['raised_by', 'person who raised it'],
      ['activity', 'person'] => ['username', 'person'],
      ['activity', 'action'] => ['action', 'action'],
      ['activity', 'day'] => ['happened_on', 'day'],
      ['bids', 'day'] => ['bid_on', 'day'],
      ['supplier_categories', 'supplier'] => ['supplier', 'supplier'],
      ['bids', 'request'] => ['request_ref', 'request'],
      ['people', 'role'] => ['role', 'role'],
      default => NULL,
    };
  }

  /**
   * Stage words: "waiting for delivery", "unpaid", "paid", "open"...
   */
  protected function stage(string $subject, string $text, array $metric): array {
    if ($subject === 'bids') {
      if ($this->has($text, ['won', 'win', 'wins', 'winning', 'winner', 'winners', 'successful'])) {
        return ["status = 'won'", 'winning bids only'];
      }
      if ($this->has($text, ['lost', 'losing', 'unsuccessful', 'rejected'])) {
        return ["status = 'lost'", 'losing bids only'];
      }
      if ($this->has($text, ['open', 'pending', 'submitted', 'waiting'])) {
        return ["status = 'submitted'", 'not decided yet'];
      }
      return [NULL, ''];
    }
    if ($subject === 'suppliers') {
      if ($this->has($text, ['pending', 'waiting', 'unverified', 'not verified', 'new', 'to approve', 'awaiting'])) {
        return ["status = 'pending'", 'waiting for approval'];
      }
      if ($this->has($text, ['approved', 'verified', 'active'])) {
        return ["status = 'approved'", 'approved only'];
      }
      if ($this->has($text, ['rejected'])) {
        return ["status = 'rejected'", 'rejected only'];
      }
      return [NULL, ''];
    }
    if ($subject !== 'requests') {
      return [NULL, ''];
    }
    $map = [
      [['not delivered', 'waiting for delivery', 'awaiting delivery', 'pending delivery', 'pending deliveries', 'deliveries pending', 'awaiting deliveries', 'upcoming deliveries', 'in transit', 'yet to arrive', 'not arrived', 'on order', 'undelivered'], "stage = 'ordered'", 'waiting for delivery'],
      [['unpaid', 'not paid', 'to pay', 'to be paid', 'pending payment', 'payment pending', 'outstanding', 'due', 'bills to pay', 'awaiting payment'], "stage IN ('received', 'invoiced')", 'delivered but not paid yet'],
      [['mismatch', 'mismatched', 'not match', 'not matching', 'wrong bill', 'wrong bills', 'short delivery', 'short delivered', 'short supplied'], "bill_check = 'mismatch'", 'bill does not match the delivery'],
      [['bill to check', 'bills to check', 'check the bill', 'unchecked'], "stage = 'received'", 'bill not checked yet'],
      [['ready to pay'], "stage = 'invoiced'", 'ready to pay'],
      [['order approval', 'awaiting order approval', 'winning bid waiting', 'to approve the order'], "stage = 'order_review'", 'waiting for the PM to approve the order'],
      [['collecting bids', 'open for bids', 'bidding', 'out for bids', 'in bidding'], "stage = 'bidding'", 'collecting bids'],
      [['ready for bids', 'not yet invited', 'to invite'], "stage = 'approved'", 'ready for bids'],
      [['pending approvals', 'approvals pending', 'waiting approvals', 'approvals', 'need approval', 'to be approved'], "stage IN ('pm_review', 'order_review')", 'waiting for a project manager to approve'],
      [['needs approval', 'pm review', 'waiting for approval', 'awaiting approval', 'pending approval'], "stage = 'pm_review'", 'waiting for PM approval'],
      [['rejected', 'not approved', 'declined'], "stage = 'rejected'", 'rejected'],
      [['delivered', 'received', 'arrived', 'deliveries'], "stage IN ('received', 'invoiced', 'paid')", 'delivered'],
      [['completed', 'complete', 'closed', 'finished', 'done', 'fully paid'], "stage = 'paid'", 'completed and paid'],
      [['open', 'active', 'in progress', 'ongoing', 'pending', 'current', 'live', 'running'], "stage NOT IN ('paid', 'rejected')", 'still in progress'],
      [['ordered', 'placed', 'confirmed orders'], "stage IN ('ordered', 'received', 'invoiced', 'paid')", 'order placed'],
    ];
    foreach ($map as [$phrases, $sql, $words]) {
      if ($this->has($text, $phrases)) {
        return [$sql, $words];
      }
    }
    // "paid requests" (not "total paid").
    if ($this->has($text, ['paid']) && $metric['name'] !== 'paid_amount') {
      return ["stage = 'paid'", 'paid'];
    }
    return [NULL, ''];
  }

  /**
   * Time words: this month, last month, this year, last 30 days, in June.
   */
  protected function time(string $subject, string $text, array $metric): array {
    $col = match ($subject) {
      'bids' => 'bid_on',
      'activity' => 'happened_on',
      'people' => 'joined_on',
      'requests' => match (TRUE) {
        $metric['name'] === 'paid_amount' => 'paid_on',
        $this->has($text, ['needed', 'need', 'due', 'required by', 'needed by', 'deadline']) => 'needed_by',
        $this->has($text, ['delivered', 'received', 'arrived', 'deliveries', 'delivery']) => 'delivered_on',
        $this->has($text, ['raised', 'requested', 'created', 'new requests']) => 'raised_on',
        in_array($metric['name'], ['order_value', 'savings', 'order_rate'], TRUE) && ($this->has($text, ['spend', 'spent', 'order', 'orders', 'ordered', 'value', 'purchase', 'purchases', 'savings', 'saved'])) => 'ordered_on',
        default => 'raised_on',
      },
      default => NULL,
    };
    if (!$col) {
      return [NULL, ''];
    }
    $today = strtotime('today');
    $range = NULL;
    if ($this->has($text, ['today'])) {
      $range = [date('Y-m-d', $today), date('Y-m-d', $today + 86400), 'today'];
    }
    elseif ($this->has($text, ['yesterday'])) {
      $range = [date('Y-m-d', $today - 86400), date('Y-m-d', $today), 'yesterday'];
    }
    elseif ($this->has($text, ['this week'])) {
      $start = strtotime('monday this week', $today);
      $range = [date('Y-m-d', $start), date('Y-m-d', $start + 7 * 86400), 'this week'];
    }
    elseif ($this->has($text, ['last week', 'previous week'])) {
      $start = strtotime('monday last week', $today);
      $range = [date('Y-m-d', $start), date('Y-m-d', $start + 7 * 86400), 'last week'];
    }
    elseif ($this->has($text, ['this month', 'current month'])) {
      $range = [date('Y-m-01', $today), date('Y-m-01', strtotime('first day of next month', $today)), 'this month'];
    }
    elseif ($this->has($text, ['last month', 'previous month'])) {
      $range = [date('Y-m-01', strtotime('first day of last month', $today)), date('Y-m-01', $today), 'last month'];
    }
    elseif ($this->has($text, ['this year', 'current year'])) {
      $range = [date('Y-01-01', $today), (date('Y', $today) + 1) . '-01-01', 'this year'];
    }
    elseif ($this->has($text, ['last year', 'previous year'])) {
      $range = [(date('Y', $today) - 1) . '-01-01', date('Y-01-01', $today), 'last year'];
    }
    elseif (preg_match('/\b(?:last|past|previous)\s+(\d+|' . implode('|', array_keys(self::NUMBERS)) . ')\s+(day|days|week|weeks|month|months)\b/', $text, $m)) {
      $n = self::NUMBERS[$m[1]] ?? (int) $m[1];
      $unit = rtrim($m[2], 's');
      $start = strtotime("-$n $unit", $today);
      $range = [date('Y-m-d', $start), date('Y-m-d', $today + 86400), 'in the last ' . $n . ' ' . $m[2]];
    }
    else {
      foreach (self::MONTHS as $i => $name) {
        if ($this->has($text, [$name, substr($name, 0, 3)]) && !($name === 'may' && !$this->has($text, ['in may', 'during may', 'may ' . date('Y')]))) {
          $year = preg_match('/\b(20\d\d)\b/', $text, $ym) ? (int) $ym[1] : (int) date('Y', $today);
          if (!isset($ym[1]) && $i + 1 > (int) date('n', $today)) {
            $year--;
          }
          $start = sprintf('%04d-%02d-01', $year, $i + 1);
          $range = [$start, date('Y-m-01', strtotime($start . ' +1 month')), 'in ' . ucfirst($name) . ' ' . $year];
          break;
        }
      }
    }
    if (!$range) {
      return [NULL, ''];
    }
    return ["$col >= " . $this->q($range[0]) . "\n  AND $col < " . $this->q($range[1]), $range[2]];
  }

  /**
   * "above 10 lakh", "under 5000", "rating over 4.3".
   */
  protected function threshold(string $text, array $metric): array {
    if (!$metric['sortable'] || !preg_match('/\b(above|over|more than|greater than|exceeding|at least|minimum of|below|under|less than|at most|maximum of|>=|<=|>|<)\s*(?:rs\.?|inr|₹)?\s*([\d,]+(?:\.\d+)?)\s*(lakhs?|lacs?|crores?|cr|k|thousand|l)?\b/u', $text, $m)) {
      return [NULL, ''];
    }
    $n = (float) str_replace(',', '', $m[2]);
    $mult = match ($m[3] ?? '') {
      'lakh', 'lakhs', 'lac', 'lacs', 'l' => 100000,
      'crore', 'crores', 'cr' => 10000000,
      'k', 'thousand' => 1000,
      default => 1,
    };
    $n *= $mult;
    $op = match ($m[1]) {
      'above', 'over', 'more than', 'greater than', 'exceeding', '>' => '>',
      'at least', 'minimum of', '>=' => '>=',
      'below', 'under', 'less than', '<' => '<',
      default => '<=',
    };
    $value = floor($n) == $n ? (string) (int) $n : (string) $n;
    $expr = str_contains($metric['expr'], ' - ') ? '(' . $metric['expr'] . ')' : $metric['expr'];
    return ["$expr $op $value", $metric['label'] . ' ' . $op . ' ' . $value];
  }

  protected function listColumns(string $subject, array $metric, ?string $stage): array {
    switch ($subject) {
      case 'bids':
        return ['request_ref', 'project', 'material', 'supplier', 'rate', 'lead_days', 'status', 'bid_on'];

      case 'suppliers':
        return ['name', 'city', 'contact', 'rating', 'on_time_pct', 'status'];

      case 'supplier_categories':
        return ['supplier', 'category'];

      case 'projects':
        return ['code', 'name', 'city', 'client', 'phase', 'progress_pct', 'material_budget'];

      case 'materials':
        return ['name', 'category', 'unit', 'standard_rate'];

      case 'activity':
        return ['happened_at', 'username', 'action', 'message'];

      case 'people':
        return ['name', 'username', 'role', 'title'];

      default:
        $cols = ['ref', 'project', 'material', 'quantity', 'unit', 'stage_label', 'supplier', 'order_value', 'needed_by'];
        if ($metric['name'] === 'paid_amount' || $stage === "stage = 'paid'") {
          $cols = ['ref', 'project', 'material', 'supplier', 'order_value', 'paid_amount', 'paid_on'];
        }
        elseif ($metric['name'] === 'savings') {
          $cols = ['ref', 'project', 'material', 'supplier', 'estimate', 'order_value', 'estimate - order_value AS savings'];
        }
        elseif ($metric['name'] === 'bill_amount') {
          $cols = ['ref', 'project', 'material', 'supplier', 'order_value', 'bill_amount', 'bill_check', 'stage_label'];
        }
        return $cols;
    }
  }

  protected function defaultOrder(string $subject): string {
    return match ($subject) {
      'bids' => 'bid_on DESC',
      'suppliers' => 'name',
      'supplier_categories' => 'category, supplier',
      'projects' => 'code',
      'materials' => 'category, name',
      'activity' => 'happened_at DESC',
      'people' => 'role, name',
      default => 'raised_on DESC',
    };
  }

  /* ---------------------------------------------------------------------
   * The local AI model (Ollama).
   * ------------------------------------------------------------------- */

  public function aiModel(): string {
    return (string) ($this->configFactory->get('sthira.settings')->get('search.sql_model') ?: 'qwen2.5-coder:1.5b');
  }

  protected function aiUrl(): string {
    return rtrim((string) ($this->configFactory->get('sthira.settings')->get('search.ollama_url') ?: 'http://ollama:11434'), '/');
  }

  /**
   * Whether Ollama is up and has the SQL model.
   */
  public function aiAvailable(): bool {
    static $ok = NULL;
    if ($ok !== NULL) {
      return $ok;
    }
    try {
      $res = $this->http->request('GET', $this->aiUrl() . '/api/tags', ['timeout' => 2, 'connect_timeout' => 1]);
      $data = json_decode((string) $res->getBody(), TRUE);
      $want = $this->aiModel();
      foreach ($data['models'] ?? [] as $m) {
        $name = $m['name'] ?? $m['model'] ?? '';
        if ($name === $want || $name === $want . ':latest' || str_starts_with($name, $want)) {
          return $ok = TRUE;
        }
      }
    }
    catch (\Throwable $e) {
      // Not running.
    }
    return $ok = FALSE;
  }

  /**
   * What the model is told: the views, the rules, a few examples.
   */
  public function aiPrompt(string $question, ?string $badSql = NULL, ?string $error = NULL): string {
    $dialect = $this->views->isMysql() ? 'MariaDB (MySQL syntax)' : 'SQLite';
    $schema = [];
    foreach (DataViews::catalog() as $view => $info) {
      $cols = [];
      foreach ($info['columns'] as $c => $about) {
        $cols[] = "  $c: $about";
      }
      $schema[] = "$view: {$info['about']}\n" . implode("\n", $cols);
    }
    $names = [];
    $e = $this->entities();
    $names[] = 'Projects: ' . implode('; ', array_keys($e['projects']));
    $names[] = 'Suppliers: ' . implode('; ', array_keys($e['suppliers']));
    $names[] = 'Materials: ' . implode('; ', array_keys($e['materials']));
    $today = date('Y-m-d');
    $p = "You write one SQL query for $dialect that answers the user's question about a construction procurement system.\n\n"
      . "Use ONLY these views and columns:\n\n" . implode("\n\n", $schema) . "\n\n"
      . "Exact names in the data:\n" . implode("\n", $names) . "\n\n"
      . "Rules:\n- One SELECT statement. No comments. Never change data.\n- Use only the views and columns above; join views on names (for example v_bids.request_ref = v_requests.ref).\n- Text values must match the exact names above, or use LIKE '%word%'.\n- Dates are text 'YYYY-MM-DD'; today is $today.\n- Money is in rupees.\n- Give columns readable names with AS.\n\n"
      . "Examples:\nQuestion: total order value per project\nSQL: SELECT project, SUM(order_value) AS total_order_value FROM v_requests WHERE order_value IS NOT NULL GROUP BY project ORDER BY total_order_value DESC;\n"
      . "Question: suppliers that never won a bid\nSQL: SELECT s.name FROM v_suppliers s WHERE s.name NOT IN (SELECT supplier FROM v_bids WHERE status = 'won');\n"
      . "Question: average bid rate per supplier for steel\nSQL: SELECT supplier, ROUND(AVG(rate), 2) AS average_rate FROM v_bids WHERE material IN (SELECT name FROM v_materials WHERE category = 'Steel') GROUP BY supplier ORDER BY average_rate;\n\n";
    if ($badSql) {
      $p .= "Your previous query failed.\nQuery: $badSql\nError: $error\nWrite a corrected query.\n\n";
    }
    return $p . "Question: $question\nSQL:";
  }

  /**
   * Asks the model for SQL.
   *
   * @return array
   *   sql (or ''), raw.
   */
  public function aiSql(string $question, ?string $badSql = NULL, ?string $error = NULL): array {
    try {
      $res = $this->http->request('POST', $this->aiUrl() . '/api/generate', [
        'json' => [
          'model' => $this->aiModel(),
          'prompt' => $this->aiPrompt($question, $badSql, $error),
          'stream' => FALSE,
          'options' => ['temperature' => 0, 'num_predict' => 400],
        ],
        'timeout' => 90,
        'connect_timeout' => 2,
      ]);
      $raw = (string) (json_decode((string) $res->getBody(), TRUE)['response'] ?? '');
    }
    catch (\Throwable $e) {
      return ['sql' => '', 'raw' => ''];
    }
    $sql = $raw;
    if (preg_match('/```(?:sql)?\s*(.*?)```/is', $raw, $m)) {
      $sql = $m[1];
    }
    $sql = trim(preg_replace('/^\s*SQL:\s*/i', '', $sql));
    // Keep the first statement only.
    if (preg_match('/^(.*?;)/s', $sql, $m)) {
      $sql = $m[1];
    }
    if (!preg_match('/^\s*(select|with)\b/i', $sql) && preg_match('/\b(select|with)\b.*$/is', $sql, $m)) {
      $sql = $m[0];
    }
    return ['sql' => trim($sql), 'raw' => $raw];
  }

}
