<?php

namespace Drupal\sthira\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sthira\Form\SearchRebuildForm;
use Drupal\sthira\Search\AnswerComposer;
use Drupal\sthira\Search\Embedder;
use Drupal\sthira\Search\SearchEngine;
use Drupal\sthira\Search\SearchIndex;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Homepage search: the JSON endpoint and the admin page for the index.
 */
class SearchController extends ControllerBase {

  public function __construct(
    protected SearchEngine $engine,
    protected SearchIndex $index,
    protected Embedder $embedder,
    protected AnswerComposer $composer,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sthira.search'),
      $container->get('sthira.search_index'),
      $container->get('sthira.embedder'),
      $container->get('sthira.answer'),
    );
  }

  /**
   * GET /api/search?q=... Public: the index only holds public information.
   */
  public function api(Request $request) {
    $q = trim((string) $request->query->get('q', ''));
    $result = $this->composer->ask($q);
    $result['urls'] = [
      'register' => Url::fromRoute('sthira.vendor_register')->toString(),
    ];
    $response = new JsonResponse($result);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * The index, the model status and a query tester with scores.
   */
  public function admin(Request $request) {
    $summary = $this->index->summary();
    $status = $this->embedder->status();
    $q = trim((string) $request->query->get('q', ''));
    $test = $q !== '' ? $this->engine->search($q, TRUE) : NULL;
    $docs = array_values(array_map(fn($d) => [
      'type' => SearchIndex::TYPE_LABELS[$d['type']] ?? $d['type'],
      'title' => $d['title'],
      'body' => mb_strimwidth($d['body'], 0, 160, '…'),
      'terms' => $d['length'],
      'vector' => (bool) $d['vector'],
    ], $this->index->docs()));
    $types = [];
    foreach ($summary['by_type'] as $type => $n) {
      $types[] = ['label' => SearchIndex::TYPE_LABELS[$type] ?? $type, 'count' => $n];
    }
    return [
      '#theme' => 'sthira_search_admin',
      '#summary' => $summary + ['built_label' => $summary['built'] ? $this->dateFormatter()->formatTimeDiffSince($summary['built']) . ' ago' : 'never'],
      '#types' => $types,
      '#status' => $status + ['model' => $this->embedder->model(), 'url' => $this->embedder->settings()['ollama_url'], 'enabled' => $this->embedder->enabled()],
      '#docs' => $docs,
      '#query' => $q,
      '#test' => $test,
      '#form' => $this->formBuilder()->getForm(SearchRebuildForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function dateFormatter() {
    return \Drupal::service('date.formatter');
  }

}
