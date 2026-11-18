<?php

namespace Drupal\sthira\Search;

use Drupal\Core\Database\Connection;
use Drupal\Core\DestructableInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sthira\Assistant;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;

/**
 * The search index: one document per public thing a supplier can ask about.
 *
 * Built from the site's content (projects, open requirements, materials,
 * categories, supplier FAQ). Each document stores its words for keyword
 * ranking and, when the local model is running, a vector for meaning-based
 * ranking. Only public information goes in: no prices, budgets or vendor data.
 *
 * The index refreshes itself after content is saved (at the end of the
 * request), and can be rebuilt with "drush sthira:search-reindex".
 */
class SearchIndex implements DestructableInterface {

  const TABLE = 'sthira_search';

  /**
   * Requirement stages shown publicly, with their public label.
   */
  const PUBLIC_STAGES = ['approved' => 'Sourcing now', 'director_review' => 'Coming soon', 'pm_review' => 'Coming soon'];

  /**
   * Content types that feed the index.
   */
  const SOURCE_TYPES = ['project', 'material', 'material_request', 'faq'];

  const TYPE_LABELS = [
    'requirement' => 'Open requirements',
    'project' => 'Projects',
    'material' => 'Materials',
    'category' => 'Categories',
    'faq' => 'Supplier help',
    'about' => 'About us',
  ];

  protected bool $pending = FALSE;

  protected ?array $docs = NULL;

  public function __construct(
    protected Connection $db,
    protected Repository $repo,
    protected Assistant $assistant,
    protected Embedder $embedder,
    protected StateInterface $state,
  ) {}

  /**
   * Notes that content changed; the index is rebuilt at the end of the request.
   */
  public function markDirty(): void {
    $this->pending = TRUE;
    $this->state->set('sthira.search.dirty', TRUE);
  }

  public function isDirty(): bool {
    return (bool) $this->state->get('sthira.search.dirty', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if ($this->pending) {
      $this->pending = FALSE;
      try {
        $this->rebuild();
      }
      catch (\Throwable $e) {
        // The next search rebuilds it.
      }
    }
  }

  /**
   * Rebuilds the index. Only changed documents are re-embedded.
   *
   * @return array
   *   Counts: docs, added, updated, removed, embedded.
   */
  public function rebuild(bool $withVectors = TRUE): array {
    $this->pending = FALSE;
    $this->repo->clearMemo();
    $docs = $this->collect();
    $existing = $this->db->select(self::TABLE, 's')->fields('s', ['id', 'hash'])->execute()->fetchAllKeyed();
    $stats = ['docs' => count($docs), 'added' => 0, 'updated' => 0, 'removed' => 0, 'embedded' => 0];

    foreach ($docs as $id => $d) {
      $text = $d['title'] . "\n" . $d['body'];
      $hash = hash('sha256', $text . json_encode($d['data']));
      if (($existing[$id] ?? NULL) === $hash) {
        continue;
      }
      $terms = array_merge(Text::terms($d['title']), Text::terms($d['title']), Text::terms($d['body']));
      $fields = [
        'type' => $d['type'],
        'entity_id' => $d['entity_id'],
        'title' => mb_substr($d['title'], 0, 255),
        'body' => $d['body'],
        'url' => $d['url'],
        'data' => json_encode($d['data']),
        'terms' => json_encode(Text::tf($terms)),
        'length' => count($terms),
        'hash' => $hash,
        'changed' => time(),
      ];
      if (isset($existing[$id])) {
        // Text changed: the old vector no longer describes it.
        $fields['vector'] = NULL;
        $fields['model'] = '';
        $this->db->update(self::TABLE)->fields($fields)->condition('id', $id)->execute();
        $stats['updated']++;
      }
      else {
        $this->db->insert(self::TABLE)->fields(['id' => $id] + $fields + ['vector' => NULL, 'model' => ''])->execute();
        $stats['added']++;
      }
    }
    $gone = array_diff(array_keys($existing), array_keys($docs));
    if ($gone) {
      $this->db->delete(self::TABLE)->condition('id', $gone, 'IN')->execute();
      $stats['removed'] = count($gone);
    }

    $this->docs = NULL;
    $this->state->set('sthira.search.dirty', FALSE);
    $this->state->set('sthira.search.built', time());
    if ($withVectors) {
      $stats['embedded'] = $this->embedMissing();
    }
    return $stats;
  }

  /**
   * Adds vectors to documents that have none (or were made by another model).
   */
  public function embedMissing(): int {
    if (!$this->embedder->available()) {
      return 0;
    }
    $model = $this->embedder->model();
    $rows = $this->db->select(self::TABLE, 's')->fields('s', ['id', 'title', 'body'])
      ->condition($this->db->condition('OR')->isNull('vector')->condition('model', $model, '<>'))
      ->execute()->fetchAll();
    $done = 0;
    foreach (array_chunk($rows, 24) as $chunk) {
      $vectors = $this->embedder->embed(array_map(fn($r) => mb_substr($r->title . '. ' . $r->body, 0, 1500), $chunk));
      if (!$vectors) {
        break;
      }
      foreach ($chunk as $i => $r) {
        $this->db->update(self::TABLE)->fields(['vector' => Embedder::pack($vectors[$i]), 'model' => $model])->condition('id', $r->id)->execute();
        $done++;
      }
    }
    $this->docs = NULL;
    return $done;
  }

  /**
   * All indexed documents, with decoded terms and vectors.
   */
  public function docs(): array {
    if ($this->docs === NULL) {
      if ($this->isDirty()) {
        $this->rebuild(FALSE);
      }
      $model = $this->embedder->model();
      $this->docs = [];
      foreach ($this->db->select(self::TABLE, 's')->fields('s')->orderBy('id')->execute() as $r) {
        $this->docs[$r->id] = [
          'id' => $r->id,
          'type' => $r->type,
          'entity_id' => (int) $r->entity_id,
          'title' => $r->title,
          'body' => $r->body,
          'url' => $r->url,
          'data' => json_decode($r->data, TRUE) ?: [],
          'tf' => json_decode($r->terms, TRUE) ?: [],
          'length' => (int) $r->length,
          'vector' => $r->model === $model ? Embedder::unpack($r->vector) : NULL,
        ];
      }
    }
    return $this->docs;
  }

  /**
   * Index summary for the admin page.
   */
  public function summary(): array {
    $byType = [];
    $vectors = 0;
    foreach ($this->docs() as $d) {
      $byType[$d['type']] = ($byType[$d['type']] ?? 0) + 1;
      $vectors += $d['vector'] ? 1 : 0;
    }
    return [
      'total' => array_sum($byType),
      'by_type' => $byType,
      'vectors' => $vectors,
      'dimensions' => ($first = current(array_filter(array_column($this->docs(), 'vector')))) ? count($first) : 0,
      'built' => $this->state->get('sthira.search.built'),
    ];
  }

  /* ---------------------------------------------------------------------
   * Building documents from content.
   * ------------------------------------------------------------------- */

  /**
   * Builds every document. Keyed by document id ("project:12").
   */
  public function collect(): array {
    $items = $this->repo->items();
    $projects = $this->repo->projects();
    $docs = [];

    // Open requirements (public view: quantity, place, date; never price).
    $reqs = [];
    foreach ($this->repo->requests(NULL, array_keys(self::PUBLIC_STAGES)) as $r) {
      $item = $items[$r['item_id']] ?? NULL;
      $p = $projects[$r['project_id']] ?? NULL;
      if (!$item || !$p) {
        continue;
      }
      $qty = Format::qty($r['qty']) . ' ' . ($r['unit'] ?: $item['unit']);
      $short = $this->assistant->shortProject($p['name']);
      $data = [
        'ref' => $r['ref'],
        'qty' => $qty,
        'material' => $item['name'],
        'material_id' => $item['id'],
        'category' => $item['category'],
        'project_id' => $p['id'],
        'project' => $short,
        'city' => $p['location'],
        'needed' => $r['needed_by'],
        'needed_label' => Format::day($r['needed_by']),
        'status' => self::PUBLIC_STAGES[$r['stage']],
        'live' => $r['stage'] === 'approved',
        'urgent' => $r['priority'] === 'urgent',
      ];
      $reqs[$r['id']] = $data;
      $docs['requirement:' . $r['id']] = [
        'type' => 'requirement',
        'entity_id' => $r['id'],
        'title' => $qty . ' ' . $item['name'],
        'body' => sprintf(
          'We are buying %s of %s (%s) for %s in %s. Needed by %s. %s.%s Also called: %s. %s',
          $qty, $item['name'], $item['category'], $p['name'], $p['location'], date('l j F Y', strtotime($r['needed_by'])),
          $data['status'], $data['urgent'] ? ' Urgent requirement.' : '', $item['keywords'], $item['description']
        ),
        'url' => '#buying',
        'data' => $data,
      ];
    }

    // Projects.
    foreach ($projects as $p) {
      $short = $this->assistant->shortProject($p['name']);
      $buying = array_values(array_filter($reqs, fn($q) => $q['project_id'] === $p['id']));
      $data = [
        'name' => $p['name'],
        'short' => $short,
        'code' => $p['code'],
        'city' => $p['location'],
        'client' => $p['client'],
        'phase' => $p['phase'],
        'progress' => $p['progress'],
        'until' => date('M Y', strtotime($p['end_date'])),
        'description' => $p['description'],
        'open' => count($buying),
        'buying' => array_values(array_unique(array_column($buying, 'material'))),
        'active' => $p['progress'] < 100,
      ];
      $docs['project:' . $p['id']] = [
        'type' => 'project',
        'entity_id' => $p['id'],
        'title' => $p['name'],
        'body' => trim(sprintf(
          '%s is an active construction project in %s. %s Client: %s. Current phase: %s. %d%% built, completion %s. %s',
          $p['name'], $p['location'], $p['description'], $p['client'], $p['phase'], $p['progress'], $data['until'],
          $buying ? 'Now buying: ' . implode(', ', $data['buying']) . '.' : 'No open requirements right now.'
        )),
        'url' => '#project-' . strtolower($p['code']),
        'data' => $data,
      ];
    }

    // Materials.
    foreach ($items as $i) {
      $open = array_values(array_filter($reqs, fn($q) => $q['material_id'] === $i['id']));
      $docs['material:' . $i['id']] = [
        'type' => 'material',
        'entity_id' => $i['id'],
        'title' => $i['name'],
        'body' => trim(sprintf('%s is a %s material we buy, in %s. %s Also called: %s.', $i['name'], strtolower($i['category']), $i['unit'], $i['description'], $i['keywords'])),
        'url' => '#categories',
        'data' => [
          'name' => $i['name'],
          'category' => $i['category'],
          'unit' => $i['unit'],
          'description' => $i['description'],
          'keywords' => $i['keywords'],
          'open' => count($open),
          'sites' => array_values(array_unique(array_map(fn($q) => $q['project'] . ', ' . $q['city'], $open))),
        ],
      ];
    }

    // Categories.
    foreach (Repository::CATEGORIES as $key => $label) {
      $in = array_values(array_filter($items, fn($i) => $i['category'] === $label));
      if (!$in) {
        continue;
      }
      $names = array_column($in, 'name');
      $docs['category:' . $key] = [
        'type' => 'category',
        'entity_id' => 0,
        'title' => $label,
        'body' => sprintf('%s category. We buy %s. Also called: %s.', $label, implode(', ', $names), implode(', ', array_column($in, 'keywords'))),
        'url' => '#categories',
        'data' => [
          'label' => $label,
          'items' => $names,
          'open' => count(array_filter($reqs, fn($q) => $q['category'] === $label)),
        ],
      ];
    }

    // Supplier FAQ.
    foreach ($this->repo->faqs() as $f) {
      $docs['faq:' . $f['id']] = [
        'type' => 'faq',
        'entity_id' => $f['id'],
        'title' => $f['question'],
        'body' => $f['answer'] . ($f['keywords'] ? ' Related: ' . $f['keywords'] . '.' : ''),
        'url' => '#how',
        'data' => ['question' => $f['question'], 'answer' => $f['answer']],
      ];
    }

    // About the supplier network.
    $cities = array_values(array_unique(array_column($projects, 'location')));
    $docs['about:network'] = [
      'type' => 'about',
      'entity_id' => 0,
      'title' => 'About our supplier network',
      'body' => sprintf(
        'We build %d active projects in %s and buy %s materials from %d verified suppliers. Suppliers register online, get verified, add their rates, receive purchase orders and are paid within 30 days of a matched invoice.',
        count($projects), implode(', ', $cities), strtolower(implode(', ', Repository::CATEGORIES)), count($this->repo->vendorsByStatus('approved'))
      ),
      'url' => '#how',
      'data' => ['projects' => count($projects), 'cities' => $cities, 'suppliers' => count($this->repo->vendorsByStatus('approved')), 'open' => count($reqs)],
    ];

    return $docs;
  }

}
