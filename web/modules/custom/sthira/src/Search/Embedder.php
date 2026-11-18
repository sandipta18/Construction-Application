<?php

namespace Drupal\sthira\Search;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Turns text into vectors with a local, open-source embedding model.
 *
 * Talks to Ollama (https://ollama.com) running next to Drupal, by default the
 * "ollama" service of this DDEV project. Nothing leaves the machine. When
 * Ollama is not running, search falls back to keyword + concept ranking.
 */
class Embedder {

  /**
   * How long an availability check is trusted, in seconds.
   */
  const CHECK_TTL = 30;

  protected ?array $status = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $http,
    protected StateInterface $state,
  ) {}

  public function settings(): array {
    $s = $this->configFactory->get('sthira.settings')->get('search') ?? [];
    return $s + ['embeddings' => 'auto', 'ollama_url' => 'http://ollama:11434', 'model' => 'nomic-embed-text'];
  }

  public function model(): string {
    return (string) $this->settings()['model'];
  }

  public function enabled(): bool {
    return $this->settings()['embeddings'] !== 'off';
  }

  /**
   * Whether the model can be used right now.
   *
   * @return array
   *   ['ok' => bool, 'message' => string, 'checked' => int]
   */
  public function status(bool $refresh = FALSE): array {
    if (!$this->enabled()) {
      return ['ok' => FALSE, 'message' => 'Vector embeddings are turned off in settings.', 'checked' => time()];
    }
    if (!$refresh && $this->status) {
      return $this->status;
    }
    $cached = $this->state->get('sthira.search.embedder');
    if (!$refresh && $cached && $cached['checked'] > time() - self::CHECK_TTL && ($cached['model'] ?? '') === $this->model()) {
      return $this->status = $cached;
    }
    $s = $this->settings();
    $status = ['ok' => FALSE, 'message' => '', 'checked' => time(), 'model' => $this->model()];
    try {
      $res = $this->http->request('GET', rtrim($s['ollama_url'], '/') . '/api/tags', ['timeout' => 1.5, 'connect_timeout' => 1]);
      $tags = json_decode((string) $res->getBody(), TRUE)['models'] ?? [];
      $names = array_map(fn($m) => preg_replace('/:latest$/', '', $m['name'] ?? ''), $tags);
      if (in_array(preg_replace('/:latest$/', '', $this->model()), $names, TRUE)) {
        $status['ok'] = TRUE;
        $status['message'] = 'Ollama is running with ' . $this->model() . '.';
      }
      else {
        $status['message'] = 'Ollama is running but the model "' . $this->model() . '" is not downloaded. Run: ddev exec -s ollama ollama pull ' . $this->model();
      }
    }
    catch (\Throwable $e) {
      $status['message'] = 'Ollama is not reachable at ' . $s['ollama_url'] . '.';
    }
    $this->state->set('sthira.search.embedder', $status);
    return $this->status = $status;
  }

  public function available(): bool {
    return $this->status()['ok'];
  }

  /**
   * Embeds texts. Returns one unit-length vector per text, or NULL on failure.
   *
   * @param string[] $texts
   * @param string $kind
   *   'document' or 'query'. Some models want a prefix for each.
   */
  public function embed(array $texts, string $kind = 'document', float $timeout = 60): ?array {
    if (!$texts || !$this->available()) {
      return NULL;
    }
    $model = $this->model();
    if (str_contains($model, 'nomic')) {
      $prefix = $kind === 'query' ? 'search_query: ' : 'search_document: ';
      $texts = array_map(fn($t) => $prefix . $t, $texts);
    }
    try {
      $res = $this->http->request('POST', rtrim($this->settings()['ollama_url'], '/') . '/api/embed', [
        'json' => ['model' => $model, 'input' => array_values($texts), 'truncate' => TRUE],
        'timeout' => $timeout,
        'connect_timeout' => 2,
      ]);
      $vectors = json_decode((string) $res->getBody(), TRUE)['embeddings'] ?? NULL;
      if (!is_array($vectors) || count($vectors) !== count($texts)) {
        return NULL;
      }
      return array_map([self::class, 'normalize'], $vectors);
    }
    catch (\Throwable $e) {
      $this->state->set('sthira.search.embedder', ['ok' => FALSE, 'message' => 'Embedding failed: ' . $e->getMessage(), 'checked' => time(), 'model' => $model]);
      $this->status = NULL;
      return NULL;
    }
  }

  public static function normalize(array $v): array {
    $n = sqrt(array_sum(array_map(fn($x) => $x * $x, $v))) ?: 1.0;
    return array_map(fn($x) => $x / $n, $v);
  }

  public static function dot(array $a, array $b): float {
    $s = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
      $s += $a[$i] * $b[$i];
    }
    return $s;
  }

  public static function pack(array $v): string {
    return base64_encode(pack('g*', ...$v));
  }

  public static function unpack(?string $s): ?array {
    if (!$s) {
      return NULL;
    }
    $bin = base64_decode($s, TRUE);
    return $bin === FALSE ? NULL : array_values(unpack('g*', $bin));
  }

}
