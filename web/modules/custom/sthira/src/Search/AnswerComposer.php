<?php

namespace Drupal\sthira\Search;

use Drupal\Core\Url;
use Drupal\sthira\Photos;
use Drupal\sthira\Repository;

/**
 * Turns a search result into a written answer for the answer screen.
 *
 * The answer is built from our own index only: a lead paragraph with the key
 * names in bold, a few points, a call-to-action banner, related content cards
 * and three follow-up questions that we know the engine can answer.
 *
 * Text is returned as segments ({t, b, u, ask}) so the browser never has to
 * trust HTML from the server.
 */
class AnswerComposer {

  public function __construct(
    protected SearchEngine $engine,
    protected SearchIndex $index,
    protected Repository $repo,
  ) {}

  /**
   * Search and compose in one call.
   */
  public function ask(string $query): array {
    $res = $this->engine->search($query);
    $res['rich'] = $this->compose($query, $res);
    return $res;
  }

  public function compose(string $query, array $res): array {
    $docs = $this->index->docs();
    $register = Url::fromRoute('sthira.vendor_register')->toString();
    $marks = $this->marks($docs);
    $intent = $res['intent'] ?? 'none';
    $a = $res['answer'] ?? NULL;

    $rich = [
      'question' => $query,
      'lead' => [],
      'more' => [],
      'points' => [],
      'banner' => NULL,
      'closing' => [],
      'related' => [],
      'next' => [],
    ];

    if (!$a) {
      $rich['lead'] = [['t' => 'I could not find an answer to that in our live requirements, projects, materials or supplier help.']];
      $rich['more'][] = [
        ['t' => 'Try a '], ['t' => 'material', 'b' => 1], ['t' => ' (steel, cement, tiles), a '],
        ['t' => 'city', 'b' => 1], ['t' => ' (Pune, Kolkata) or a question such as '],
        ['t' => 'how do I get paid?', 'ask' => 'how do I get paid?'], ['t' => '.'],
      ];
    }
    else {
      $text = trim($a['text'] ?? '');
      // Lists read better with their headline as the first sentence.
      if (in_array($intent, ['list_requirements', 'list_projects', 'place', 'count', 'best_match'], TRUE) && !empty($a['title'])) {
        $text = rtrim($a['title'], '.') . '. ' . $text;
      }
      $rich['lead'] = $this->segments($text, $marks);
      if (!empty($a['extra'])) {
        $rich['more'][] = $this->segments($a['extra'], $marks);
      }
    }

    // Points from the result groups. Related questions become follow-ups.
    $asks = [];
    foreach ($res['groups'] ?? [] as $g) {
      foreach ($g['items'] as $it) {
        if (!empty($it['ask'])) {
          $asks[] = $it['ask'];
          continue;
        }
        if (count($rich['points']) >= 5) {
          continue;
        }
        $rich['points'][] = $this->point($it, $marks);
      }
    }

    $supplierCta = ($res['cta'] ?? NULL) === 'register' || in_array($intent, ['list_requirements', 'list_projects', 'place', 'material_info', 'category_info', 'project_detail'], TRUE);
    if ($supplierCta) {
      $rich['banner'] = [
        'title' => 'Register once and quote on every requirement that matches what you sell',
        'action' => 'Become a supplier',
        'url' => $register,
      ];
      if ($intent !== 'faq' || !str_contains(strtolower($a['title'] ?? ''), 'become')) {
        $rich['closing'][] = [
          ['t' => 'Want to supply these materials? '],
          ['t' => 'Register as a supplier', 'u' => $register, 'b' => 1, 'ext' => 1],
          ['t' => ' and we will tell you the moment we need what you sell.'],
        ];
      }
    }
    $rich['closing'][] = [
      ['t' => 'If you would like more details, we are happy to help. Ask about '],
      ['t' => 'payment terms', 'ask' => 'when do you pay suppliers?'],
      ['t' => ' or '],
      ['t' => 'how suppliers are chosen', 'ask' => 'how do you choose a supplier?'],
      ['t' => '.'],
    ];

    $rich['related'] = $this->related($rich, $docs, $register);
    $rich['next'] = $this->next($query, $res, $docs, $asks);
    return $rich;
  }

  /**
   * Names worth bolding, with a link where the homepage has a section.
   */
  protected function marks(array $docs): array {
    $marks = [];
    foreach ($docs as $d) {
      if ($d['type'] === 'project') {
        $url = $d['url'];
        $marks[$d['data']['name']] = ['b' => 1, 'u' => $url];
        $marks[$d['data']['short']] = ['b' => 1, 'u' => $url];
      }
      elseif ($d['type'] === 'material') {
        $marks[$d['data']['name']] = ['b' => 1];
      }
    }
    foreach (['open for bids now', 'Urgent', 'urgent'] as $w) {
      $marks[$w] = ['b' => 1];
    }
    // Longest first, so "Skyline Residency – Tower B" wins over "Skyline Residency".
    uksort($marks, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
    return $marks;
  }

  /**
   * Splits text into segments, bolding and linking known names.
   */
  protected function segments(string $text, array $marks): array {
    $hits = [];
    foreach ($marks as $phrase => $m) {
      if ($phrase === '') {
        continue;
      }
      $offset = 0;
      while (($pos = mb_strpos($text, $phrase, $offset)) !== FALSE) {
        $end = $pos + mb_strlen($phrase);
        $clash = FALSE;
        foreach ($hits as $h) {
          if ($pos < $h[1] && $end > $h[0]) {
            $clash = TRUE;
            break;
          }
        }
        if (!$clash) {
          $hits[] = [$pos, $end, $m];
        }
        $offset = $end;
      }
    }
    usort($hits, fn($x, $y) => $x[0] <=> $y[0]);
    $out = [];
    $at = 0;
    foreach ($hits as [$s, $e, $m]) {
      if ($s > $at) {
        $out[] = ['t' => mb_substr($text, $at, $s - $at)];
      }
      $out[] = ['t' => mb_substr($text, $s, $e - $s)] + $m;
      $at = $e;
    }
    if ($at < mb_strlen($text)) {
      $out[] = ['t' => mb_substr($text, $at)];
    }
    return $out;
  }

  /**
   * One bullet: a bold lead-in, then the detail.
   */
  protected function point(array $it, array $marks): array {
    $title = $it['title'] ?? '';
    $meta = $it['meta'] ?? '';
    if (str_contains($title, ' · ')) {
      // A requirement: "120 tonnes · TMT Steel Bars".
      [$qty, $material] = explode(' · ', $title, 2);
      $parts = explode(' · ', $meta);
      $where = array_shift($parts);
      $body = $qty . ' for ' . $where . ', ' . implode(', ', $parts) . '.';
      $badge = $it['badge'] ?? '';
      if ($badge) {
        $body .= ' ' . ($badge === 'Urgent' ? 'Urgent.' : rtrim($badge, '.') . '.');
      }
      return ['head' => $material, 'body' => $this->segments($body, $marks)];
    }
    $body = str_replace(' · ', ', ', $meta);
    return ['head' => $title, 'body' => $this->segments(rtrim($body, '.') . '.', $marks), 'url' => $it['url'] ?? NULL];
  }

  /**
   * Three cards: projects named in the answer first, then supplier guides.
   */
  protected function related(array $rich, array $docs, string $register): array {
    $flat = '';
    foreach (array_merge([$rich['lead']], $rich['more'], array_column($rich['points'], 'body')) as $segs) {
      $flat .= ' ' . implode('', array_column($segs, 't'));
    }
    $flat .= ' ' . implode(' ', array_column($rich['points'], 'head'));

    $photos = [];
    foreach ($this->repo->projects() as $p) {
      $photos[$p['id']] = $p['photo'] ?: Photos::remote($p['code']);
    }

    $cards = [];
    foreach ($docs as $d) {
      if ($d['type'] !== 'project') {
        continue;
      }
      if (str_contains($flat, $d['data']['short']) || str_contains($flat, $d['data']['name'])) {
        $cards[] = [
          'title' => $d['data']['name'],
          'sub' => $d['data']['city'] . ' · ' . $d['data']['progress'] . '% built',
          'image' => $photos[$d['entity_id']] ?? NULL,
          'url' => $d['url'],
          'action' => 'View project',
          'kind' => 'project',
        ];
      }
    }
    $guides = [
      ['title' => 'Become a supplier', 'sub' => 'Register once, quote on every match', 'url' => $register, 'action' => 'Register', 'kind' => 'register'],
      ['title' => 'How we choose suppliers', 'sub' => 'Price, on-time delivery and quality', 'ask' => 'how do you choose a supplier?', 'action' => 'Learn more', 'kind' => 'guide'],
      ['title' => 'When suppliers get paid', 'sub' => 'Invoices matched, paid on time', 'ask' => 'when do you pay suppliers?', 'action' => 'Learn more', 'kind' => 'guide'],
    ];
    // With no project in the answer, show the sites anyway: they have photos.
    if (!$cards) {
      foreach ($docs as $d) {
        if ($d['type'] === 'project' && count($cards) < 2) {
          $cards[] = [
            'title' => $d['data']['name'],
            'sub' => $d['data']['city'] . ' · ' . $d['data']['progress'] . '% built',
            'image' => $photos[$d['entity_id']] ?? NULL,
            'url' => $d['url'],
            'action' => 'View project',
            'kind' => 'project',
          ];
        }
      }
    }
    $cards = array_slice($cards, 0, 2);
    foreach ($guides as $g) {
      if (count($cards) >= 3) {
        break;
      }
      $q = strtolower($rich['question']);
      if (isset($g['ask']) && similar_text($q, $g['ask']) > 0.8 * strlen($g['ask'])) {
        continue;
      }
      $cards[] = $g;
    }
    return $cards;
  }

  /**
   * Follow-up questions that we have checked give a useful answer.
   */
  protected function next(string $query, array $res, array $docs, array $asks): array {
    $cand = $asks;
    $u = $this->understood($res);
    foreach ($u['materials'] as $m) {
      $cand[] = 'Which projects need ' . $this->plain($m) . '?';
      $cand[] = 'What is ' . $this->plain($m) . '?';
    }
    foreach ($u['projects'] as $p) {
      $cand[] = 'What does ' . $p . ' need?';
      $cand[] = 'How far along is ' . $p . '?';
    }
    foreach ($u['cities'] as $c) {
      $cand[] = 'What is needed in ' . $c . '?';
      $cand[] = 'Projects in ' . $c;
    }
    // Projects named in the answer.
    foreach ($docs as $d) {
      if ($d['type'] === 'project' && str_contains(json_encode($res['answer'] ?? []), $d['data']['short'])) {
        $cand[] = 'What does ' . $d['data']['short'] . ' need?';
      }
    }
    $cand = array_merge($cand, [
      'What is needed urgently?',
      'Which projects are active right now?',
      'How do I become a supplier?',
      'When do you pay suppliers?',
      'How do you choose a supplier?',
    ]);

    $seen = [mb_strtolower(trim($query, " ?")) => TRUE];
    $title = mb_strtolower($res['answer']['title'] ?? '');
    $out = [];
    $tried = 0;
    foreach ($cand as $q) {
      $key = mb_strtolower(trim($q, " ?"));
      if (isset($seen[$key]) || count($out) >= 3 || $tried >= 10) {
        continue;
      }
      $seen[$key] = TRUE;
      $tried++;
      $r = $this->engine->search($q);
      $t = mb_strtolower($r['answer']['title'] ?? '');
      if ($r['intent'] === 'none' || !$t || $t === $title || str_starts_with($t, 'not ') || str_starts_with($t, 'no ')) {
        continue;
      }
      $out[] = ucfirst($q);
    }
    return $out;
  }

  /**
   * Reads the "Understood" chips back into lists.
   */
  protected function understood(array $res): array {
    $u = ['materials' => [], 'projects' => [], 'cities' => []];
    foreach ($res['understood'] ?? [] as $chip) {
      $vals = array_map('trim', explode(',', $chip['value']));
      match ($chip['label']) {
        'Material' => $u['materials'] = $vals,
        'Project' => $u['projects'] = $vals,
        'Place' => $u['cities'] = $vals,
        default => NULL,
      };
    }
    return $u;
  }

  /**
   * "OPC 53 Grade Cement" reads fine; drop bracketed sizes from long names.
   */
  protected function plain(string $name): string {
    return trim(preg_replace('/\s*\(.*\)\s*/', ' ', $name));
  }

}
