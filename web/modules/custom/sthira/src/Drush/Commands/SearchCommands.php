<?php

namespace Drupal\sthira\Drush\Commands;

use Drupal\sthira\Search\Embedder;
use Drupal\sthira\Search\SearchEngine;
use Drupal\sthira\Search\SearchIndex;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the homepage search.
 */
final class SearchCommands extends DrushCommands {

  public function __construct(
    protected SearchIndex $index,
    protected SearchEngine $engine,
    protected Embedder $embedder,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('sthira.search_index'), $container->get('sthira.search'), $container->get('sthira.embedder'));
  }

  /**
   * Rebuilds the homepage search index (and vectors, if Ollama is running).
   */
  #[CLI\Command(name: 'sthira:search-reindex', aliases: ['sthira-reindex'])]
  public function reindex(): void {
    $status = $this->embedder->status(TRUE);
    $this->io()->writeln($status['message']);
    $s = $this->index->rebuild();
    $this->io()->success(sprintf('%d documents (%d new, %d changed, %d removed), %d embedded.', $s['docs'], $s['added'], $s['updated'], $s['removed'], $s['embedded']));
  }

  /**
   * Downloads the homepage and project photos again (if missing).
   */
  #[CLI\Command(name: 'sthira:photos', aliases: ['sthira-photos'])]
  public function photos(): void {
    $n = \Drupal\sthira\Photos::seed();
    $total = count(\Drupal\sthira\Photos::PROJECTS);
    $n === $total ? $this->io()->success("All $total photos are in place.") : $this->io()->warning("$n of $total photos are in place. Check the internet connection and run this again.");
  }

  /**
   * Runs a homepage search from the command line.
   */
  #[CLI\Command(name: 'sthira:search', aliases: ['sthira-search'])]
  #[CLI\Argument(name: 'query', description: 'What to search for.')]
  public function search(string $query): void {
    $r = $this->engine->search($query, TRUE);
    $this->io()->title($r['answer']['title'] ?? 'No match');
    if (!empty($r['answer']['text'])) {
      $this->io()->writeln($r['answer']['text']);
    }
    $this->io()->writeln('');
    $this->io()->writeln('Understood: ' . implode(' · ', array_map(fn($c) => $c['label'] . ': ' . $c['value'], $r['understood'])));
    $this->io()->writeln('Mode: ' . $r['mode'] . ', ' . $r['docs'] . ' documents, ' . $r['took_ms'] . ' ms');
    $this->io()->table(['Document', 'Type', 'BM25', 'Vector', 'Score'], array_map(fn($x) => [$x['title'], $x['type'], $x['bm25'], $x['vector'] ?? '-', $x['score']], array_slice($r['ranking'], 0, 8)));
  }

}
