<?php

namespace Drupal\sthira\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sthira\Data\DataAsk;
use Drupal\sthira\Data\DataViews;
use Drupal\sthira\Format;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * "Ask the data": type a request, see the answer and the SQL behind it.
 */
class AskDataController extends ControllerBase {

  public function __construct(protected DataAsk $ask) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.data_ask'));
  }

  public function page(Request $request) {
    $q = trim((string) $request->query->get('q', ''));
    $result = $q !== '' ? $this->ask->ask($q) : NULL;

    $table = NULL;
    if ($result && $result['columns']) {
      $money = [];
      foreach ($result['columns'] as $c) {
        $money[$c] = DataAsk::isMoney($c);
      }
      $rows = [];
      foreach ($result['rows'] as $row) {
        $cells = [];
        foreach ($result['columns'] as $c) {
          $v = $row[$c] ?? NULL;
          if ($v === NULL || $v === '') {
            $cells[] = ['text' => '–', 'num' => FALSE, 'empty' => TRUE];
          }
          elseif (is_numeric($v) && $money[$c]) {
            $cells[] = ['text' => Format::inr($v), 'num' => TRUE];
          }
          elseif (is_numeric($v)) {
            $f = (float) $v;
            $cells[] = ['text' => floor($f) == $f ? number_format($f) : rtrim(rtrim(number_format($f, 2), '0'), '.'), 'num' => TRUE];
          }
          else {
            $cells[] = ['text' => (string) $v, 'num' => FALSE];
          }
        }
        $rows[] = $cells;
      }
      $table = ['columns' => $result['columns'], 'rows' => $rows, 'num' => array_map(fn($c) => isset($rows[0]) && $rows[0][array_search($c, $result['columns'], TRUE)]['num'], $result['columns'])];
    }

    // The breakdown behind a single number, as bars.
    $bars = [];
    if ($result && !empty($result['breakdown'])) {
      $vals = array_map(fn($r) => (float) array_values($r)[1], $result['breakdown']['rows']);
      $peak = max($vals) ?: 1;
      foreach ($result['breakdown']['rows'] as $i => $r) {
        $r = array_values($r);
        $bars[] = [
          'label' => (string) $r[0],
          'value' => $result['breakdown']['money'] ? Format::short($r[1]) : $this->ask->fmt('x', $r[1]),
          'pct' => max(2, round($vals[$i] / $peak * 100)),
          'url' => Url::fromRoute('sthira.ask_data', [], ['query' => ['q' => $this->drill($q, $result['breakdown']['label'], (string) $r[0], $result['breakdown']['subject'] ?? '')]])->toString(),
        ];
      }
    }
    $link = fn($e) => ['text' => $e, 'url' => Url::fromRoute('sthira.ask_data', [], ['query' => ['q' => $e]])->toString()];

    $views = [];
    foreach (DataViews::catalog() as $name => $info) {
      $views[] = ['name' => $name, 'about' => $info['about'], 'columns' => $info['columns']];
    }

    return [
      '#theme' => 'sthira_ask_data',
      '#q' => $q,
      '#result' => $result,
      '#table' => $table,
      '#examples' => array_map($link, $this->ask->examples(12)),
      '#next' => array_map($link, $result['next'] ?? []),
      '#bars' => $bars,
      '#views' => $views,
      '#ai' => ['on' => $this->ask->aiAvailable(), 'model' => $this->ask->aiModel()],
      '#action' => Url::fromRoute('sthira.ask_data')->toString(),
      '#attached' => ['library' => ['sthira/askdata']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * A question that opens one bar of a breakdown: "how many employees" +
   * role "Project Manager" -> "who are our project managers".
   */
  protected function drill(string $q, string $dim, string $value, string $subject): string {
    $v = mb_strtolower($value);
    return match ($dim) {
      'role' => 'who are our ' . (preg_match('/(s|sh|ch|x)$/', $v) ? $v . 'es' : $v . 's'),
      'stage' => 'requests ' . $v,
      'city' => ($subject === 'projects' ? 'projects in ' : 'suppliers in ') . $value,
      'category' => 'which suppliers sell ' . $v,
      'project' => 'total spend for ' . trim(explode('–', $value)[0]),
      'supplier' => 'requests from ' . $value,
      'action' => 'activity ' . $v,
      default => $q . ' ' . $v,
    };
  }

}
