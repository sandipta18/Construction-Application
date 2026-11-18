<?php

namespace Drupal\sthira\Search;

/**
 * Answers supplier questions from the search index.
 *
 * 1. Understand: fix typos, widen with synonyms, spot cities, projects,
 *    materials, categories, time windows and what kind of answer is wanted.
 * 2. Rank: BM25 keyword relevance over every document, fused with vector
 *    similarity from the local embedding model when it is running.
 * 3. Answer: filter the matching documents and write a short answer.
 */
class SearchEngine {

  const GENERIC_NAME_WORDS = ['tower', 'park', 'city', 'annex', 'grade', 'coarse', 'red', 'sqmm', 'sqft', 'cum'];

  const INTENT_LABELS = [
    'list_projects' => 'List projects',
    'project_detail' => 'Project details',
    'list_requirements' => 'What we are buying',
    'count' => 'Count',
    'material_info' => 'Explain a material',
    'category_info' => 'Explain a category',
    'catalog' => 'What we buy',
    'faq' => 'Supplier help',
    'about' => 'About us',
    'place' => 'Everything in a place',
    'best_match' => 'Closest match',
    'help' => 'Help',
    'none' => 'No match',
  ];

  protected ?array $lexicon = NULL;

  public function __construct(
    protected SearchIndex $index,
    protected Embedder $embedder,
  ) {}

  /**
   * Runs a search.
   *
   * @return array
   *   answer, groups, understood, mode, took_ms (and ranking when $debug).
   */
  public function search(string $query, bool $debug = FALSE): array {
    $t0 = microtime(TRUE);
    $query = trim(mb_substr($query, 0, 200));
    $docs = $this->index->docs();
    $lex = $this->lexicon($docs);

    $u = $this->understand($query, $lex);
    $ranking = $this->rank($query, $u, $docs);
    $out = $this->answer($u, $ranking, $docs);

    $out['understood'] = $this->chips($u, $out['intent'], $ranking['mode']);
    $out['mode'] = $ranking['mode'];
    $out['docs'] = count($docs);
    $out['took_ms'] = (int) round((microtime(TRUE) - $t0) * 1000);
    if ($debug) {
      $out['ranking'] = array_slice($ranking['rows'], 0, 15);
      $out['terms'] = $u['qw'];
    }
    return $out;
  }

  /* ---------------------------------------------------------------------
   * 1. Understanding the question.
   * ------------------------------------------------------------------- */

  /**
   * Vocabulary and entity lists taken from the index itself.
   */
  protected function lexicon(array $docs): array {
    if ($this->lexicon !== NULL) {
      return $this->lexicon;
    }
    $vocab = [];
    $cities = [];
    $projectTerms = [];
    $materialTerms = [];
    $categoryTerms = [];
    foreach ($docs as $d) {
      foreach ($d['tf'] as $t => $n) {
        $vocab[$t] = ($vocab[$t] ?? 0) + $n;
      }
      if ($d['type'] === 'project') {
        foreach (Text::terms($d['data']['city']) as $t) {
          $cities[$t] = $d['data']['city'];
        }
        foreach (array_unique(Text::terms($d['title'])) as $t) {
          $projectTerms[$t][] = $d['entity_id'];
        }
      }
      if ($d['type'] === 'material') {
        foreach (array_unique(array_merge(Text::terms($d['title']), Text::terms($d['data']['keywords'] ?? ''))) as $t) {
          $materialTerms[$t][] = $d['entity_id'];
        }
      }
      if ($d['type'] === 'category') {
        foreach (Text::terms($d['data']['label']) as $t) {
          $categoryTerms[$t] = $d['data']['label'];
        }
      }
    }
    $clean = function (array $map) use ($cities) {
      $out = [];
      foreach ($map as $t => $ids) {
        $ids = array_values(array_unique($ids));
        if (count($ids) === 1 && strlen($t) >= 3 && !ctype_digit($t[0]) && !in_array($t, self::GENERIC_NAME_WORDS, TRUE) && !isset($cities[$t])) {
          $out[$t] = $ids[0];
        }
      }
      return $out;
    };
    return $this->lexicon = [
      'vocab' => $vocab,
      'all_words' => array_values(array_unique(array_merge(array_keys($vocab), Thesaurus::vocabulary()))),
      'cities' => $cities,
      'projects' => $clean($projectTerms),
      'materials' => $clean($materialTerms),
      'categories' => $categoryTerms,
    ];
  }

  protected function understand(string $query, array $lex): array {
    $raw = Text::words($query);
    $first = $raw[0] ?? '';
    $u = [
      'query' => $query,
      'question' => in_array($first, ['how', 'when', 'where', 'why', 'can', 'do', 'does', 'is', 'are', 'what', 'whatis', 'which', 'who', 'will', 'should'], TRUE) || str_ends_with($query, '?'),
      'orig' => [],
      'qw' => [],
      'corrections' => [],
      'raw' => [],
      'synonyms' => [],
      'concepts' => [],
      'cities' => [],
      'projects' => [],
      'materials' => [],
      'materials_loose' => [],
      'categories' => [],
    ];

    foreach ($raw as $w) {
      $u['raw'][Text::stem($w)] = $w;
    }
    foreach (Text::terms($query) as $t) {
      $fixed = $t;
      $known = isset($lex['vocab'][$t]) || Thesaurus::concepts($t) || Thesaurus::expand($t) || ctype_digit($t[0]);
      if (!$known) {
        // Unfinished word: "kolk" -> "kolkata".
        $prefix = NULL;
        if (strlen($t) >= 4) {
          foreach ($lex['all_words'] as $w) {
            if (str_starts_with($w, $t) && ($prefix === NULL || strlen($w) < strlen($prefix))) {
              $prefix = $w;
            }
          }
        }
        $fixed = $prefix ?? Text::closest($t, $lex['all_words']) ?? $t;
        if ($fixed !== $t) {
          $u['corrections'][$t] = $fixed;
        }
      }
      $u['orig'][$fixed] = TRUE;
      $u['qw'][$fixed] = 1.0;
      foreach (Thesaurus::concepts($fixed) as $c) {
        $u['concepts'][$c] = TRUE;
      }
    }
    foreach (array_keys($u['orig']) as $t) {
      foreach (Thesaurus::expand($t) as $s => $w) {
        if (!isset($u['qw'][$s])) {
          $u['qw'][$s] = $w;
          if (isset($lex['vocab'][$s]) || isset($lex['cities'][$s])) {
            $u['synonyms'][$t][] = $s;
          }
        }
      }
    }

    // Entities. Words typed by the person count first; synonyms count too.
    foreach ($u['qw'] as $t => $w) {
      if (isset($lex['cities'][$t])) {
        $u['cities'][$lex['cities'][$t]] = TRUE;
      }
      if (isset($lex['projects'][$t])) {
        $u['projects'][$lex['projects'][$t]] = TRUE;
      }
      if (isset($lex['materials'][$t])) {
        if (isset($u['orig'][$t])) {
          $u['materials'][$lex['materials'][$t]] = TRUE;
        }
        $u['materials_loose'][$lex['materials'][$t]] = TRUE;
      }
      if (isset($lex['categories'][$t])) {
        $u['categories'][$lex['categories'][$t]] = TRUE;
      }
    }
    if (!$u['materials'] && count($u['materials_loose']) === 1) {
      $u['materials'] = $u['materials_loose'];
    }
    foreach (['cities', 'projects', 'materials', 'materials_loose', 'categories'] as $k) {
      $u[$k] = array_keys($u[$k]);
    }
    $u['concepts'] = array_keys($u['concepts']);
    return $u;
  }

  /* ---------------------------------------------------------------------
   * 2. Ranking: BM25 + vectors, fused by reciprocal rank.
   * ------------------------------------------------------------------- */

  protected function rank(string $query, array $u, array $docs): array {
    $n = max(1, count($docs));
    $avg = max(1, array_sum(array_column($docs, 'length')) / $n);
    $df = [];
    foreach ($docs as $d) {
      foreach ($d['tf'] as $t => $c) {
        $df[$t] = ($df[$t] ?? 0) + 1;
      }
    }
    $k1 = 1.2;
    $b = 0.75;
    $bm = [];
    foreach ($docs as $id => $d) {
      $s = 0.0;
      foreach ($u['qw'] as $t => $w) {
        if (!empty($d['tf'][$t])) {
          $tf = $d['tf'][$t];
          $idf = log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
          $s += $w * $idf * ($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * $d['length'] / $avg));
        }
      }
      if ($s > 0) {
        $bm[$id] = $s;
      }
    }
    arsort($bm);

    // Vectors, when the local model is up and the index has them.
    $vec = [];
    $mode = 'keyword';
    $hasVectors = (bool) array_filter(array_column($docs, 'vector'));
    if ($hasVectors && $this->embedder->available()) {
      $qv = $this->embedder->embed([$query], 'query', 4)[0] ?? NULL;
      if ($qv) {
        $mode = 'hybrid';
        foreach ($docs as $id => $d) {
          if ($d['vector']) {
            $vec[$id] = Embedder::dot($qv, $d['vector']);
          }
        }
        arsort($vec);
      }
    }

    // How far each vector score stands out from the rest (z-score). Raw
    // similarity differs by model; standing out works for any model.
    $z = [];
    if (count($vec) >= 8) {
      $mean = array_sum($vec) / count($vec);
      $sd = sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $vec)) / count($vec)) ?: 1.0;
      foreach ($vec as $id => $x) {
        $z[$id] = ($x - $mean) / $sd;
      }
    }

    // Reciprocal rank fusion. Vector-only hits must clearly stand out.
    $rrf = [];
    $r = 0;
    foreach ($bm as $id => $s) {
      $rrf[$id] = 1 / (60 + ++$r);
    }
    if ($vec) {
      $top = reset($vec);
      $r = 0;
      foreach ($vec as $id => $s) {
        $r++;
        if ($r > 12 || (!isset($bm[$id]) && (($z[$id] ?? 0) < 2.0 || $s < $top - 0.12))) {
          continue;
        }
        $rrf[$id] = ($rrf[$id] ?? 0) + 1 / (60 + $r);
      }
    }
    arsort($rrf);

    $rows = [];
    foreach ($rrf as $id => $s) {
      $rows[] = [
        'id' => $id,
        'type' => $docs[$id]['type'],
        'title' => $docs[$id]['title'],
        'bm25' => round($bm[$id] ?? 0, 3),
        'vector' => isset($vec[$id]) ? round($vec[$id], 3) : NULL,
        'z' => isset($z[$id]) ? round($z[$id], 2) : NULL,
        'score' => round($s * 1000, 2),
      ];
    }
    return ['rows' => $rows, 'bm25' => $bm, 'vector' => $vec, 'z' => $z, 'order' => array_keys($rrf), 'mode' => $mode];
  }

  /* ---------------------------------------------------------------------
   * 3. Answering.
   * ------------------------------------------------------------------- */

  protected function answer(array $u, array $ranking, array $docs): array {
    $c = array_flip($u['concepts']);
    $byType = fn(string $type) => array_filter($docs, fn($d) => $d['type'] === $type);
    $ranked = fn(string $type) => array_values(array_filter(array_map(fn($id) => $docs[$id], $ranking['order']), fn($d) => $d['type'] === $type));
    $top = $ranking['order'][0] ?? NULL;
    $topDoc = $top ? $docs[$top] : NULL;
    $timeAsk = (bool) array_intersect_key($c, array_flip(['urgent', 'soon', 'thisweek', 'nextweek', 'thismonth', 'nextmonth']));
    $hasEntities = $u['cities'] || $u['projects'] || $u['materials'] || $u['categories'];
    $projectAsk = isset($c['project']) || isset($c['progress']);
    $reqAsk = isset($c['requirement']) || $timeAsk || $u['materials'] || $u['categories'];
    $supplierWord = (bool) array_intersect(array_keys($u['orig']), ['supplier', 'vendor', 'seller', 'dealer']);

    // Help / greetings.
    if (!$u['orig'] || ((isset($c['hello']) || isset($c['help'])) && !$hasEntities && count($u['orig']) <= 2 && !$reqAsk && !$projectAsk)) {
      return $this->out('help', [
        'title' => 'Ask me about what we are buying',
        'text' => 'I can find open requirements, our project sites, materials we buy and answers about registering, documents and payment. Try “active projects”, “rebar in Bhiwandi”, “what is M-Sand?” or “when do you pay suppliers?”.',
      ]);
    }

    // Explain a material or category.
    if (isset($c['info']) && ($u['materials'] || $u['categories']) && !$u['projects'] && !$timeAsk) {
      if ($u['materials']) {
        return $this->materialInfo($u, $docs);
      }
      return $this->categoryInfo($u, $docs);
    }

    // Counting.
    if (isset($c['count'])) {
      if ($supplierWord && !$reqAsk) {
        $about = $docs['about:network']['data'] ?? [];
        return $this->out('count', ['title' => ($about['suppliers'] ?? 0) . ' verified suppliers', 'text' => 'We work with ' . ($about['suppliers'] ?? 0) . ' verified suppliers today, and new suppliers can register any time.'], [], ['cta' => 'register']);
      }
      if ($projectAsk && !$reqAsk) {
        $projects = $this->filterProjects($u, $byType('project'));
        $where = $this->where($u);
        return $this->out('count', [
          'title' => count($projects) . ' active project' . (count($projects) === 1 ? '' : 's') . $where,
          'text' => $projects ? 'We are building ' . $this->join(array_map(fn($p) => $p['data']['name'] . ' in ' . $p['data']['city'], $projects)) . '.' : 'We have no project' . $where . ' right now.',
        ], [$this->projectGroup($projects)]);
      }
      $reqs = $this->filterRequirements($u, $byType('requirement'), $c);
      return $this->out('count', [
        'title' => count($reqs) . ' open requirement' . (count($reqs) === 1 ? '' : 's') . $this->what($u, $docs) . $this->where($u) . $this->when($c),
        'text' => $reqs ? $this->liveText($reqs) : 'Nothing matches right now. Registered suppliers are notified when we need their materials.',
      ], [$this->requirementGroup($reqs)], ['cta' => 'register']);
    }

    // FAQ: a question about how things work, with nothing specific in it.
    $faqTop = $ranked('faq')[0] ?? NULL;
    // Words that say what about, beyond "need", "buy", "now" and the like.
    $content = array_filter(array_keys($u['orig']), fn($t) => !Thesaurus::concepts($t));
    if ($faqTop && !$hasEntities && !$timeAsk && !$projectAsk && ($topDoc['type'] ?? '') === 'faq' && (!isset($c['requirement']) || $content)) {
      $res = $this->faq($faqTop, $ranked('faq'));
      if (isset($c['requirement'])) {
        $res['groups'][] = $this->requirementGroup($this->filterRequirements($u, $byType('requirement'), $c), 'What we are buying now');
      }
      return $res;
    }

    // Catalog: what do you buy?
    if (isset($c['catalog']) && !$u['materials'] && !$u['categories'] && !$projectAsk) {
      $cats = array_values($byType('category'));
      return $this->out('catalog', [
        'title' => 'We buy ' . count($cats) . ' kinds of material',
        'text' => $this->join(array_map(fn($d) => $d['data']['label'] . ' (' . $this->join($d['data']['items']) . ')', $cats)) . '.',
      ], [$this->requirementGroup($this->filterRequirements($u, $byType('requirement'), $c))], ['cta' => 'register']);
    }

    // Projects.
    if (($projectAsk && !$reqAsk) || ($u['projects'] && !$reqAsk && !$u['cities'])) {
      $projects = $this->filterProjects($u, $byType('project'));
      if (count($projects) === 1 && ($u['projects'] || isset($c['progress']))) {
        return $this->projectDetail(reset($projects), $docs);
      }
      if (!$projects && $u['cities']) {
        return $this->out('list_projects', [
          'title' => 'No project in ' . $this->join($u['cities']) . ' right now',
          'text' => 'We are building in ' . $this->join(array_unique(array_map(fn($p) => $p['data']['city'], $byType('project')))) . '.',
        ], [$this->projectGroup($byType('project'))]);
      }
      $where = $this->where($u);
      $reqs = $this->filterRequirements(['cities' => [], 'materials' => [], 'categories' => [], 'projects' => array_map(fn($p) => $p['entity_id'], $projects)] + $u, $byType('requirement'), []);
      return $this->out('list_projects', [
        'title' => 'We are building ' . count($projects) . ' active project' . (count($projects) === 1 ? '' : 's') . $where,
        'text' => $this->join(array_map(fn($p) => $p['data']['name'] . ' in ' . $p['data']['city'] . ' (' . $p['data']['progress'] . '% built, ' . lcfirst($p['data']['phase']) . ')', $projects)) . '. Together they have ' . count($reqs) . ' open requirement' . (count($reqs) === 1 ? '' : 's') . ' for suppliers.',
      ], [$this->projectGroup($projects), $this->requirementGroup($reqs, 'What these sites need')]);
    }

    // Requirements.
    if ($reqAsk) {
      return $this->requirements($u, $c, $docs, $byType('requirement'));
    }

    // A place on its own: everything there.
    if ($u['cities']) {
      $projects = $this->filterProjects($u, $byType('project'));
      $reqs = $this->filterRequirements($u, $byType('requirement'), $c);
      return $this->out('place', [
        'title' => 'In ' . $this->join($u['cities']) . ': ' . count($projects) . ' project' . (count($projects) === 1 ? '' : 's') . ', ' . count($reqs) . ' open requirement' . (count($reqs) === 1 ? '' : 's'),
        'text' => $projects ? $this->join(array_map(fn($p) => $p['data']['name'] . ' is ' . $p['data']['progress'] . '% built', $projects)) . '.' : 'We have no site there right now.',
      ], [$this->requirementGroup($reqs), $this->projectGroup($projects)], ['cta' => 'register']);
    }

    if ($faqTop && $topDoc && $topDoc['type'] === 'faq') {
      return $this->faq($faqTop, $ranked('faq'));
    }

    // Best single match by type.
    if ($topDoc) {
      $strong = ($ranking['bm25'][$top] ?? 0) >= 1.0 || ($ranking['z'][$top] ?? 0) >= 3.0;
      if ($strong) {
        switch ($topDoc['type']) {
          case 'material':
            return $this->materialInfo(['materials' => [$topDoc['entity_id']]] + $u, $docs, 'best_match');

          case 'project':
            return $this->projectDetail($topDoc, $docs, 'best_match');

          case 'faq':
            return $this->faq($topDoc, $ranked('faq'));

          case 'category':
            return $this->categoryInfo(['categories' => [$topDoc['data']['label']]] + $u, $docs, 'best_match');
        }
        $closest = array_map(fn($id) => $docs[$id], array_slice($ranking['order'], 0, 5));
        return $this->out('best_match', [
          'title' => 'Closest matches for “' . $u['query'] . '”',
          'text' => 'I could not find an exact answer, but these look related.',
        ], [$this->mixedGroup($closest)]);
      }
    }

    return $this->out('none', NULL);
  }

  protected function requirements(array $u, array $c, array $docs, array $all): array {
    $reqs = $this->filterRequirements($u, $all, $c);
    $what = $this->what($u, $docs);
    $where = $this->where($u);
    $when = $this->when($c);
    $extra = NULL;
    if (count($u['materials']) === 1 && ($m = $docs['material:' . $u['materials'][0]] ?? NULL)) {
      $extra = $m['data']['description'];
    }
    // Not this exact material, but others in its category?
    if (!$reqs && $u['materials'] && $u['categories']) {
      $wider = $this->filterRequirements(['materials' => []] + $u, $all, $c);
      if ($wider) {
        $res = $this->requirements(['materials' => []] + $u, $c, $docs, $all);
        $res['answer']['text'] = 'No' . $what . ' right now, but similar items are open. ' . $res['answer']['text'];
        $res['answer']['extra'] = $extra;
        return $res;
      }
    }
    if (!$reqs) {
      // Same material elsewhere?
      $elsewhere = ($u['cities'] || $u['projects'] || $when) ? $this->filterRequirements(['cities' => [], 'projects' => []] + $u, $all, []) : [];
      $groups = [$this->requirementGroup($elsewhere, 'Needed elsewhere')];
      if (!$elsewhere && ($u['cities'] || $u['projects'])) {
        $groups[] = $this->requirementGroup($this->filterRequirements(['materials' => [], 'categories' => []] + $u, $all, []), 'Other things we need' . $where);
      }
      return $this->out('list_requirements', [
        'title' => 'Not buying' . ($what ?: ' anything') . $where . $when . ' right now',
        'text' => $elsewhere ? 'But we need it at ' . $this->join(array_unique(array_map(fn($r) => $r['data']['project'] . ', ' . $r['data']['city'], $elsewhere))) . '.' : 'Register as a supplier and we will notify you when we need it.',
        'extra' => $extra,
      ], $groups, ['cta' => 'register']);
    }
    $sites = array_values(array_unique(array_map(fn($r) => $r['data']['project'] . ' (' . $r['data']['city'] . ')', $reqs)));
    $live = count(array_filter($reqs, fn($r) => $r['data']['live']));
    $soonest = min(array_map(fn($r) => $r['data']['needed'], $reqs));
    $n = count($reqs);
    if ($what) {
      $title = 'We are buying' . $what . $where . $when;
    }
    else {
      $title = (isset($c['urgent']) ? 'Urgent requirements' : 'Open requirements') . $where . (isset($c['urgent']) ? '' : ($when ? ' needed' . $when : ''));
    }
    $status = $this->liveText($reqs);
    return $this->out('list_requirements', [
      'title' => $title,
      'text' => sprintf(
        '%d open requirement%s at %s. %s %s needed by %s.',
        $n, $n === 1 ? '' : 's', $this->join($sites), $status, $n === 1 ? 'It is' : 'The earliest is',
        \Drupal\sthira\Format::day($soonest)
      ),
      'extra' => $extra,
    ], [$this->requirementGroup($reqs)], ['cta' => 'register']);
  }

  protected function materialInfo(array $u, array $docs, string $intent = 'material_info'): array {
    $m = $docs['material:' . $u['materials'][0]] ?? NULL;
    if (!$m) {
      return $this->out('none', NULL);
    }
    if (count($u['materials']) === 2 && ($m2 = $docs['material:' . $u['materials'][1]] ?? NULL)) {
      $reqs = array_filter($docs, fn($d) => $d['type'] === 'requirement' && in_array($d['data']['material_id'], $u['materials'], TRUE));
      return $this->out($intent, [
        'title' => $m['title'] . ' vs ' . $m2['title'],
        'text' => $m['title'] . ': ' . $m['data']['description'] . ' ' . $m2['title'] . ': ' . $m2['data']['description'],
        'extra' => count($reqs) ? 'Open right now: ' . $this->join(array_map(fn($r) => $r['data']['qty'] . ' ' . $r['data']['material'] . ' (' . $r['data']['city'] . ')', $reqs)) . '.' : 'No open requirement for either right now.',
      ], [$this->requirementGroup($reqs)], ['cta' => 'register']);
    }
    $reqs = array_filter($docs, fn($d) => $d['type'] === 'requirement' && $d['data']['material_id'] === $m['entity_id']);
    $d = $m['data'];
    return $this->out($intent, [
      'title' => $d['name'],
      'text' => $d['description'] ?: $d['name'] . ' is in our ' . $d['category'] . ' category.',
      'extra' => $d['open'] ? 'We have ' . $d['open'] . ' open requirement' . ($d['open'] === 1 ? '' : 's') . ' for it, at ' . $this->join($d['sites']) . '.' : 'No open requirement for it right now; registered suppliers are notified when we need it.',
    ], [$this->requirementGroup($reqs)], ['cta' => 'register']);
  }

  protected function categoryInfo(array $u, array $docs, string $intent = 'category_info'): array {
    $cat = NULL;
    foreach ($docs as $d) {
      if ($d['type'] === 'category' && in_array($d['data']['label'], $u['categories'], TRUE)) {
        $cat = $d;
        break;
      }
    }
    if (!$cat) {
      return $this->out('none', NULL);
    }
    $reqs = array_filter($docs, fn($d) => $d['type'] === 'requirement' && $d['data']['category'] === $cat['data']['label']);
    $mats = array_values(array_filter($docs, fn($d) => $d['type'] === 'material' && $d['data']['category'] === $cat['data']['label']));
    return $this->out($intent, [
      'title' => $cat['data']['label'],
      'text' => 'In ' . strtolower($cat['data']['label']) . ' we buy ' . $this->join($cat['data']['items']) . '. ' . implode(' ', array_map(fn($m) => $m['data']['description'], array_slice($mats, 0, 2))),
      'extra' => count($reqs) ? count($reqs) . ' open requirement' . (count($reqs) === 1 ? '' : 's') . ' right now.' : 'No open requirement right now.',
    ], [$this->requirementGroup($reqs)], ['cta' => 'register']);
  }

  protected function projectDetail(array $p, array $docs, string $intent = 'project_detail'): array {
    $d = $p['data'];
    $reqs = array_filter($docs, fn($x) => $x['type'] === 'requirement' && $x['data']['project_id'] === $p['entity_id']);
    return $this->out($intent, [
      'title' => $d['name'] . ', ' . $d['city'],
      'text' => trim($d['description'] . ' Now ' . $d['progress'] . '% built (' . lcfirst($d['phase']) . '), completion ' . $d['until'] . '.'),
      'extra' => $reqs ? 'We are buying ' . $this->join(array_map(fn($r) => $r['data']['qty'] . ' ' . $r['data']['material'], $reqs)) . ' for this site.' : 'No open requirement for this site right now.',
    ], [$this->requirementGroup($reqs, 'What this site needs'), $this->projectGroup([$p])], ['cta' => 'register']);
  }

  protected function faq(array $faq, array $more): array {
    $related = array_slice(array_filter($more, fn($d) => $d['id'] !== $faq['id']), 0, 2);
    return $this->out('faq', [
      'title' => $faq['data']['question'],
      'text' => $faq['data']['answer'],
    ], [$related ? ['label' => 'Related questions', 'items' => array_map(fn($d) => ['title' => $d['data']['question'], 'meta' => $d['data']['answer'], 'action' => 'Ask', 'ask' => $d['data']['question']], $related)] : NULL], ['cta' => 'register']);
  }

  /* ---------- Filters ---------- */

  protected function filterRequirements(array $u, array $reqs, array $c): array {
    $today = strtotime('today');
    $endOfWeek = strtotime('sunday this week', $today);
    $out = [];
    foreach ($reqs as $id => $r) {
      $d = $r['data'];
      if ($u['materials'] ?? []) {
        if (!in_array($d['material_id'], $u['materials'], TRUE)) {
          continue;
        }
      }
      elseif (($u['categories'] ?? []) && !in_array($d['category'], $u['categories'], TRUE)) {
        continue;
      }
      if (($u['cities'] ?? []) || ($u['projects'] ?? [])) {
        if (!in_array($d['city'], $u['cities'] ?? [], TRUE) && !in_array($d['project_id'], $u['projects'] ?? [], TRUE)) {
          continue;
        }
      }
      $due = strtotime($d['needed']);
      if (isset($c['urgent']) && !$d['urgent'] && $due > $today + 5 * 86400) {
        continue;
      }
      if (isset($c['thisweek']) && $due > $endOfWeek) {
        continue;
      }
      if (isset($c['nextweek']) && ($due <= $endOfWeek || $due > $endOfWeek + 7 * 86400)) {
        continue;
      }
      if (isset($c['thismonth']) && date('Y-m', $due) !== date('Y-m', $today)) {
        continue;
      }
      if (isset($c['nextmonth']) && date('Y-m', $due) !== date('Y-m', strtotime('first day of next month', $today))) {
        continue;
      }
      if (isset($c['soon']) && $due > $today + 14 * 86400) {
        continue;
      }
      $out[$id] = $r;
    }
    uasort($out, fn($a, $b) => [$b['data']['live'], $a['data']['needed']] <=> [$a['data']['live'], $b['data']['needed']]);
    return $out;
  }

  protected function liveText(array $reqs): string {
    $n = count($reqs);
    $live = count(array_filter($reqs, fn($r) => $r['data']['live']));
    if ($live === $n) {
      return $n === 1 ? 'It is sourcing now.' : 'All are sourcing now.';
    }
    if (!$live) {
      return $n === 1 ? 'It is coming soon, awaiting final approval.' : 'All are coming soon, awaiting final approval.';
    }
    return $live . ' ' . ($live === 1 ? 'is' : 'are') . ' sourcing now; the rest are coming soon.';
  }

  protected function filterProjects(array $u, array $projects): array {
    if (!$u['cities'] && !$u['projects']) {
      return $projects;
    }
    return array_filter($projects, fn($p) => in_array($p['data']['city'], $u['cities'], TRUE) || in_array($p['entity_id'], $u['projects'], TRUE));
  }

  /* ---------- Output helpers ---------- */

  protected function out(string $intent, ?array $answer, array $groups = [], array $extra = []): array {
    return [
      'intent' => $intent,
      'answer' => $answer,
      'groups' => array_values(array_filter($groups, fn($g) => $g && !empty($g['items']))),
    ] + $extra;
  }

  protected function requirementGroup(array $reqs, string $label = 'Open requirements'): array {
    return ['label' => $label, 'items' => array_values(array_map(fn($r) => [
      'title' => $r['data']['qty'] . ' · ' . $r['data']['material'],
      'meta' => $r['data']['project'] . ', ' . $r['data']['city'] . ' · needed by ' . $r['data']['needed_label'],
      'badge' => $r['data']['urgent'] ? 'Urgent' : $r['data']['status'],
      'hot' => $r['data']['live'] || $r['data']['urgent'],
      'action' => 'Quote',
      'cta' => 'register',
    ], array_slice($reqs, 0, 8)))];
  }

  protected function projectGroup(array $projects): array {
    return ['label' => 'Projects', 'items' => array_values(array_map(fn($p) => [
      'title' => $p['data']['name'],
      'meta' => $p['data']['city'] . ' · ' . $p['data']['phase'] . ' · ' . $p['data']['progress'] . '% built · ' . $p['data']['open'] . ' open requirement' . ($p['data']['open'] === 1 ? '' : 's'),
      'action' => 'View',
      'url' => $p['url'],
    ], $projects))];
  }

  protected function mixedGroup(array $docs): array {
    return ['label' => 'Closest matches', 'items' => array_values(array_map(fn($d) => [
      'title' => $d['title'],
      'meta' => (SearchIndex::TYPE_LABELS[$d['type']] ?? ucfirst($d['type'])) . ' · ' . mb_strimwidth($d['body'], 0, 110, '…'),
      'action' => 'View',
      'url' => $d['url'],
    ], $docs))];
  }

  protected function what(array $u, array $docs): string {
    if ($u['materials']) {
      return ' ' . $this->join(array_map(fn($id) => $docs['material:' . $id]['title'] ?? '', $u['materials']));
    }
    if ($u['categories']) {
      return ' ' . strtolower($this->join($u['categories']));
    }
    return '';
  }

  protected function where(array $u): string {
    if ($u['cities']) {
      return ' in ' . $this->join($u['cities']);
    }
    if ($u['projects']) {
      $names = [];
      foreach ($this->index->docs() as $d) {
        if ($d['type'] === 'project' && in_array($d['entity_id'], $u['projects'], TRUE)) {
          $names[] = $d['data']['short'];
        }
      }
      return ' for ' . $this->join($names);
    }
    return '';
  }

  protected function when(array $c): string {
    foreach (['urgent' => ' urgently', 'thisweek' => ' this week', 'nextweek' => ' next week', 'thismonth' => ' this month', 'nextmonth' => ' next month', 'soon' => ' soon'] as $k => $label) {
      if (isset($c[$k])) {
        return $label;
      }
    }
    return '';
  }

  protected function join(array $list): string {
    $list = array_values(array_filter($list, fn($x) => $x !== '' && $x !== NULL));
    if (count($list) <= 1) {
      return (string) ($list[0] ?? '');
    }
    $last = array_pop($list);
    return implode(', ', $list) . ' and ' . $last;
  }

  /**
   * "Understood as" chips shown under the search box.
   */
  protected function chips(array $u, string $intent, string $mode): array {
    $chips = [['label' => 'Looking for', 'value' => self::INTENT_LABELS[$intent] ?? $intent]];
    $docs = $this->index->docs();
    foreach ($u['corrections'] as $from => $to) {
      $chips[] = ['label' => 'Read', 'value' => $from . ' → ' . $to];
    }
    if ($u['cities']) {
      $chips[] = ['label' => 'Place', 'value' => implode(', ', $u['cities'])];
    }
    if ($u['projects'] && !$u['cities']) {
      $chips[] = ['label' => 'Project', 'value' => implode(', ', array_map(fn($id) => $docs['project:' . $id]['data']['short'] ?? '', $u['projects']))];
    }
    if ($u['materials']) {
      $chips[] = ['label' => 'Material', 'value' => implode(', ', array_map(fn($id) => $docs['material:' . $id]['title'] ?? '', $u['materials']))];
    }
    elseif ($u['categories']) {
      $chips[] = ['label' => 'Category', 'value' => implode(', ', $u['categories'])];
    }
    $when = trim($this->when(array_flip($u['concepts'])));
    if ($when) {
      $chips[] = ['label' => 'When', 'value' => $when];
    }
    if (in_array('active', $u['concepts'], TRUE)) {
      $chips[] = ['label' => 'Status', 'value' => 'active'];
    }
    $syn = array_slice($u['synonyms'], 0, 2, TRUE);
    foreach ($syn as $word => $list) {
      $readable = Thesaurus::display($word) ?: $list;
      $chips[] = ['label' => 'Also searched', 'value' => ($u['raw'][$word] ?? $word) . ' → ' . implode(', ', array_slice($readable, 0, 3))];
    }
    return $chips;
  }

}
