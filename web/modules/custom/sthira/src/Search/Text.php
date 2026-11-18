<?php

namespace Drupal\sthira\Search;

/**
 * Text processing shared by the indexer and the query parser.
 *
 * Documents and queries go through the same steps so their terms line up:
 * lower-case, join common phrases, split into words, drop stop words and
 * reduce each word to a simple stem ("projects" and "project" match).
 */
final class Text {

  /**
   * Words that carry no meaning for search.
   */
  const STOP = [
    'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'for', 'by', 'with', 'from', 'into', 'near', 'around',
    'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'can', 'could', 'will', 'would', 'shall', 'should',
    'may', 'might', 'must', 'i', 'me', 'my', 'we', 'us', 'our', 'you', 'your', 'it', 'its', 'they', 'them', 'their',
    'this', 'that', 'these', 'those', 'there', 'here', 'any', 'some', 'all', 'please', 'show', 'list', 'give', 'find',
    'get', 'see', 'want', 'know', 'tell', 'about', 'like', 'also', 'just', 'currently', 'kindly', 'pls', 'plz',
    'have', 'has', 'had', 'having', 'so', 'if', 'then', 'than', 'what', 'which', 'who', 'whom', 'where', 'when', 'how',
    'why', 'up', 'out', 'yet', 'very', 'much', 'more', 'most', 'other', 'per', 'via', 'etc',
  ];

  /**
   * Phrases joined into one token before splitting (applied to raw text).
   */
  const PHRASES = [
    'how many' => ' howmany ', 'number of' => ' howmany ', 'count of' => ' howmany ', 'total number' => ' howmany ',
    'what is' => ' whatis ', 'what are' => ' whatis ', "what's" => ' whatis ', 'whats' => ' whatis ', 'tell me about' => ' whatis ',
    'used for' => ' whatis ', 'meaning of' => ' whatis ', 'difference between' => ' whatis ',
    'in progress' => ' ongoing ', 'under construction' => ' ongoing ', 'right now' => ' now ', 'at the moment' => ' now ',
    'looking for' => ' need ', 'in need of' => ' need ', 'on the lookout' => ' need ',
    'sign up' => ' signup ', 'sign-up' => ' signup ', 'log in' => ' login ', 'sign in' => ' login ',
    'this week' => ' thisweek ', 'next week' => ' nextweek ', 'this month' => ' thismonth ', 'next month' => ' nextmonth ',
    'salt lake' => ' saltlake ', 'navi mumbai' => ' mumbai ', 'west bengal' => ' bengal ',
    'm-sand' => ' msand ', 'm sand' => ' msand ', 'river sand' => ' msand ', 'manufactured sand' => ' msand ',
    'rcc' => ' concrete ', 'ready mix' => ' concrete ', 'ready-mix' => ' concrete ',
    'purchase order' => ' po ', 'payment terms' => ' payment ', 'credit period' => ' payment ',
    'lead time' => ' leadtime ', 'price list' => ' rate ', 'rate card' => ' rate ',
    'sq mm' => ' sqmm ', 'sq.mm' => ' sqmm ', 'sq ft' => ' sqft ', 'sq.ft' => ' sqft ', 'cu.m' => ' cum ', 'cu m' => ' cum ',
    'as soon as possible' => ' urgent ', 'asap' => ' urgent ',
    'be finished' => ' completion ', 'be completed' => ' completion ', 'be complete' => ' completion ', 'be ready' => ' completion ',
    'finish date' => ' completion ', 'finished' => ' completion ', 'handed over' => ' handover ',
  ];

  /**
   * Lower-cases and joins known phrases.
   */
  public static function normalize(string $text): string {
    $t = mb_strtolower($text);
    $t = strtr($t, ['×' => 'x', '–' => '-', '—' => '-', '’' => "'", '“' => '"', '”' => '"', '₹' => ' ']);
    $t = ' ' . preg_replace('/\s+/', ' ', $t) . ' ';
    foreach (self::PHRASES as $from => $to) {
      $t = preg_replace('/(?<![a-z0-9])' . preg_quote($from, '/') . '(?![a-z0-9])/u', $to, $t);
    }
    return $t;
  }

  /**
   * Splits text into raw words (no stemming, stop words kept).
   */
  public static function words(string $text): array {
    $t = self::normalize($text);
    // Keep hyphenated words joined as well as split ("m-sand" -> "msand").
    $t = preg_replace_callback('/([a-z0-9]+)-([a-z0-9]+)/', fn($m) => $m[1] . $m[2] . ' ' . $m[1] . ' ' . $m[2], $t);
    preg_match_all('/[a-z0-9]+(?:\.[0-9]+)?/u', $t, $m);
    return $m[0];
  }

  /**
   * Words reduced to stems, without stop words.
   */
  public static function terms(string $text): array {
    $out = [];
    foreach (self::words($text) as $w) {
      if (in_array($w, self::STOP, TRUE) || (strlen($w) < 2 && !ctype_digit($w))) {
        continue;
      }
      $out[] = self::stem($w);
    }
    return $out;
  }

  /**
   * A light English stemmer, good enough for plurals and -ing / -ed forms.
   */
  public static function stem(string $w): string {
    if (strlen($w) <= 3 || ctype_digit($w[0])) {
      return $w;
    }
    $irregular = ['buying' => 'buy', 'bought' => 'buy', 'built' => 'build', 'paid' => 'pay', 'children' => 'child', 'sites' => 'site', 'tiles' => 'tile'];
    if (isset($irregular[$w])) {
      return $irregular[$w];
    }
    if (preg_match('/^(.{3,})ies$/', $w, $m)) {
      return $m[1] . 'y';
    }
    if (preg_match('/^(.{2,}(?:ss|x|z|ch|sh))es$/', $w, $m)) {
      return $m[1];
    }
    if (preg_match('/^(.{3,}[^su])s$/', $w, $m)) {
      return $m[1];
    }
    if (strlen($w) > 6 && preg_match('/^(.{4,})ing$/', $w, $m)) {
      return self::undouble($m[1]);
    }
    if (strlen($w) > 5 && preg_match('/^(.{4,})ed$/', $w, $m)) {
      return self::undouble($m[1]);
    }
    return $w;
  }

  protected static function undouble(string $w): string {
    return preg_match('/([bdgmnprt])\1$/', $w) ? substr($w, 0, -1) : $w;
  }

  /**
   * Term frequencies for a list of terms.
   */
  public static function tf(array $terms): array {
    $tf = [];
    foreach ($terms as $t) {
      $tf[$t] = ($tf[$t] ?? 0) + 1;
    }
    return $tf;
  }

  /**
   * Closest word in a vocabulary, for typo correction.
   *
   * Allows one edit for words of 4 to 6 letters and two for longer ones.
   */
  public static function closest(string $word, array $vocabulary): ?string {
    $len = strlen($word);
    if ($len < 4 || ctype_digit($word[0])) {
      return NULL;
    }
    $max = $len >= 7 ? 2 : 1;
    $best = NULL;
    $bestD = $max + 1;
    foreach ($vocabulary as $v) {
      $v = (string) $v;
      if (abs(strlen($v) - $len) > $max || strlen($v) < 4) {
        continue;
      }
      $d = levenshtein($word, $v);
      if ($d < $bestD || ($d === $bestD && $best !== NULL && $v[0] === $word[0] && $best[0] !== $word[0])) {
        $best = $v;
        $bestD = $d;
      }
    }
    return $bestD <= $max ? $best : NULL;
  }

}
