<?php

namespace Drupal\sthira;

/**
 * Small formatting helpers (Indian number grouping, dates, initials).
 */
class Format {

  /**
   * Formats rupees with Indian digit grouping, e.g. ₹12,34,567.
   */
  public static function inr($n, bool $symbol = TRUE): string {
    $n = (float) $n;
    $neg = $n < 0;
    $s = (string) (int) round(abs($n));
    if (strlen($s) > 3) {
      $last3 = substr($s, -3);
      $rest = substr($s, 0, -3);
      $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
      $s = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $s;
  }

  /**
   * Short rupee format: ₹4.2 crore, ₹12.5 lakh, or full below one lakh.
   */
  public static function short($n): string {
    $n = (float) $n;
    $abs = abs($n);
    if ($abs >= 1e7) {
      return ($n < 0 ? '-' : '') . '₹' . self::trim(number_format($abs / 1e7, 2)) . ' crore';
    }
    if ($abs >= 1e5) {
      return ($n < 0 ? '-' : '') . '₹' . self::trim(number_format($abs / 1e5, 1)) . ' lakh';
    }
    return self::inr($n);
  }

  protected static function trim(string $s): string {
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
  }

  /**
   * Singular unit for "per unit" labels, e.g. ₹410/bag.
   */
  public static function per(string $unit): string {
    $map = ['bags' => 'bag', 'tonnes' => 'tonne', 'coils' => 'coil', 'buckets' => 'bucket', 'pieces' => 'piece', 'cubic metres' => 'cubic metre', 'square feet' => 'square foot', 'litres' => 'litre', 'metres' => 'metre', 'nos' => 'piece', 'pcs' => 'piece', 'cu.m' => 'cubic metre', 'sq.ft' => 'square foot'];
    return $map[$unit] ?? $unit;
  }

  public static function qty($q): string {
    $q = (float) $q;
    return floor($q) == $q ? number_format($q) : number_format($q, 1);
  }

  public static function date(?int $ts): string {
    return $ts ? date('d M Y', $ts) : '—';
  }

  public static function day(?string $ymd): string {
    return $ymd ? date('D, d M', strtotime($ymd)) : '—';
  }

  public static function ago(?int $ts): string {
    if (!$ts) {
      return '';
    }
    $d = time() - $ts;
    if ($d < 60) {
      return 'just now';
    }
    if ($d < 3600) {
      return floor($d / 60) . ' min ago';
    }
    if ($d < 86400) {
      $h = floor($d / 3600);
      return $h . ($h == 1 ? ' hour ago' : ' hours ago');
    }
    if ($d < 172800) {
      return 'yesterday';
    }
    return floor($d / 86400) . ' days ago';
  }

  public static function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $i = mb_substr($parts[0] ?? '', 0, 1);
    if (count($parts) > 1) {
      $i .= mb_substr(end($parts), 0, 1);
    }
    return mb_strtoupper($i);
  }

  public static function pct($part, $whole): int {
    return $whole > 0 ? (int) round($part / $whole * 100) : 0;
  }

}
