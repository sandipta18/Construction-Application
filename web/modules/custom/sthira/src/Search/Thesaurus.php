<?php

namespace Drupal\sthira\Search;

/**
 * Construction and procurement vocabulary.
 *
 * Two jobs:
 * - SYNONYMS widen a query with the words our content actually uses
 *   ("rebar" also searches "steel" and "tmt"; "warehouse" also "logistics").
 * - CONCEPTS tag a query with what the person is asking for (projects,
 *   requirements, a count, an explanation, urgency, a time window).
 *
 * Keys are raw words; they are stemmed when used, so plurals work too.
 */
final class Thesaurus {

  const SYNONYMS = [
    // Materials.
    'rebar' => ['steel', 'tmt', 'bar'], 'sariya' => ['steel', 'tmt'], 'saria' => ['steel', 'tmt'], 'rod' => ['steel', 'tmt'],
    'iron' => ['steel'], 'reinforcement' => ['steel', 'tmt'], 'tmt' => ['steel'], 'metal' => ['aggregate', 'steel'],
    'concrete' => ['cement', 'aggregate', 'msand'], 'opc' => ['cement'], 'ppc' => ['cement'], 'binder' => ['cement'],
    'sand' => ['msand'], 'msand' => ['sand'], 'gravel' => ['aggregate'], 'jelly' => ['aggregate'], 'stone' => ['aggregate'],
    'crushed' => ['aggregate'], 'ballast' => ['aggregate'],
    'block' => ['aac', 'brick'], 'aac' => ['block'], 'masonry' => ['brick', 'block'], 'wall' => ['brick', 'block', 'masonry'],
    'brick' => ['block'], 'bricks' => ['block'],
    'wire' => ['copper', 'electrical', 'wiring'], 'cable' => ['wire', 'copper', 'electrical'], 'wiring' => ['wire', 'electrical'],
    'electric' => ['electrical', 'wire'], 'electrical' => ['wire', 'copper'], 'mep' => ['electrical', 'plumbing'],
    'pipe' => ['cpvc', 'plumbing'], 'plumbing' => ['pipe', 'cpvc'], 'sanitary' => ['plumbing', 'pipe'], 'pvc' => ['cpvc', 'pipe'],
    'water' => ['pipe', 'plumbing'],
    'tile' => ['vitrified', 'flooring', 'finishing'], 'flooring' => ['tile', 'vitrified'], 'floor' => ['tile', 'flooring'],
    'ceramic' => ['tile', 'vitrified'], 'paint' => ['emulsion', 'finishing'], 'painting' => ['paint', 'emulsion'],
    'colour' => ['paint'], 'color' => ['paint'], 'emulsion' => ['paint'], 'interior' => ['finishing'], 'finish' => ['finishing'],
    // Building types.
    'residential' => ['apartment', 'residency', 'flat'], 'apartment' => ['residential', 'flat', 'residency'], 'flat' => ['apartment', 'residential'],
    'housing' => ['residential', 'apartment'], 'home' => ['residential', 'apartment'], 'house' => ['residential', 'apartment'],
    'tower' => ['residential', 'storey'], 'highrise' => ['tower', 'storey'],
    'warehouse' => ['logistics', 'warehousing', 'shed'], 'godown' => ['warehouse', 'logistics'], 'logistic' => ['warehouse', 'warehousing'],
    'storage' => ['warehouse', 'logistics'], 'industrial' => ['logistics', 'warehouse', 'shed'], 'factory' => ['industrial', 'shed'],
    'hospital' => ['healthcare', 'medical'], 'healthcare' => ['hospital'], 'medical' => ['hospital', 'healthcare'],
    'clinic' => ['hospital', 'healthcare'], 'health' => ['hospital', 'healthcare'],
    'commercial' => ['office', 'logistics'], 'office' => ['commercial'],
    // Places.
    'mumbai' => ['bhiwandi', 'thane'], 'bombay' => ['bhiwandi', 'mumbai'], 'thane' => ['bhiwandi'],
    'calcutta' => ['kolkata'], 'bengal' => ['kolkata'], 'saltlake' => ['kolkata'], 'howrah' => ['kolkata'],
    'poona' => ['pune'], 'hinjewadi' => ['pune'], 'maharashtra' => ['pune', 'bhiwandi'],
    // Suppliers and process.
    'supplier' => ['vendor'], 'vendor' => ['supplier'], 'seller' => ['supplier', 'vendor'], 'dealer' => ['supplier', 'vendor'],
    'supply' => ['supplier', 'register'], 'sell' => ['supplier', 'register'],
    'trader' => ['supplier', 'vendor'], 'distributor' => ['supplier', 'vendor'], 'manufacturer' => ['supplier', 'vendor'],
    'register' => ['registration', 'signup'], 'registration' => ['register'], 'signup' => ['register'], 'join' => ['register'],
    'onboard' => ['register', 'onboarding'], 'empanel' => ['register', 'empanelment'], 'enroll' => ['register'], 'apply' => ['register'],
    'document' => ['documents', 'certificate'], 'paper' => ['document'], 'kyc' => ['document', 'gst', 'pan'], 'gstin' => ['gst'],
    'pay' => ['payment', 'paid'], 'payment' => ['paid', 'pay'], 'money' => ['payment'], 'credit' => ['payment'], 'dues' => ['payment'],
    'invoice' => ['payment', 'bill'], 'bill' => ['invoice'], 'billing' => ['invoice'],
    'deliver' => ['delivery'], 'delivery' => ['deliver'], 'dispatch' => ['deliver', 'delivery'], 'ship' => ['deliver'], 'transport' => ['delivery'],
    'choose' => ['selection', 'compare'], 'select' => ['choose', 'selection'], 'win' => ['choose', 'selection'], 'l1' => ['lowest', 'price'],
    'tender' => ['quotation', 'bid'], 'rfq' => ['quotation', 'bid'], 'bid' => ['quotation', 'quote'], 'quote' => ['quotation', 'rate'],
    'price' => ['rate'], 'rate' => ['price'], 'cost' => ['price', 'rate'],
    'verify' => ['verification'], 'approve' => ['verification', 'approval'], 'approval' => ['verification'],
    'contact' => ['support', 'help'], 'support' => ['contact', 'help'], 'msme' => ['small'],
  ];

  /**
   * Concept tags: raw word => concepts.
   */
  const CONCEPTS = [
    // Asking about projects / sites.
    'project' => ['project'], 'site' => ['project'], 'building' => ['project'], 'construction' => ['project'], 'development' => ['project'],
    'portfolio' => ['project'], 'job' => ['project'], 'build' => ['project'],
    // Active / current.
    'active' => ['active'], 'ongoing' => ['active'], 'current' => ['active'], 'live' => ['active'], 'running' => ['active'],
    'underway' => ['active'], 'now' => ['active'], 'present' => ['active'], 'today' => ['active'],
    // Buying / requirements.
    'buy' => ['requirement'], 'buying' => ['requirement'], 'need' => ['requirement'], 'require' => ['requirement'],
    'requirement' => ['requirement'], 'demand' => ['requirement'], 'procure' => ['requirement'], 'procurement' => ['requirement'],
    'purchase' => ['requirement'], 'sourcing' => ['requirement'], 'source' => ['requirement'], 'tender' => ['requirement'],
    'rfq' => ['requirement'], 'enquiry' => ['requirement'], 'inquiry' => ['requirement'], 'opportunity' => ['requirement'],
    'open' => ['requirement'], 'wanted' => ['requirement'],
    'quantity' => ['requirement'], 'requirment' => ['requirement'],
    // Counting.
    'howmany' => ['count'], 'count' => ['count'], 'total' => ['count'],
    // Explaining.
    'whatis' => ['info'], 'explain' => ['info'], 'meaning' => ['info'], 'define' => ['info'], 'definition' => ['info'],
    'describe' => ['info'], 'use' => ['info'], 'purpose' => ['info'], 'difference' => ['info'],
    // Urgency and time.
    'urgent' => ['urgent'], 'urgently' => ['urgent'], 'immediately' => ['urgent'], 'immediate' => ['urgent'], 'priority' => ['urgent'],
    'quickly' => ['urgent'], 'soon' => ['soon'], 'thisweek' => ['thisweek'], 'nextweek' => ['nextweek'],
    'thismonth' => ['thismonth'], 'nextmonth' => ['nextmonth'], 'tomorrow' => ['thisweek'],
    // Project facts.
    'progress' => ['progress'], 'status' => ['progress'], 'complete' => ['progress'], 'completion' => ['progress'],
    'handover' => ['progress'], 'deadline' => ['progress'], 'far' => ['progress'], 'stage' => ['progress'],
    // Catalog.
    'category' => ['catalog'], 'categories' => ['catalog'], 'material' => ['catalog'], 'materials' => ['catalog'],
    'product' => ['catalog'], 'item' => ['catalog'],
    // Small talk.
    'hi' => ['hello'], 'hello' => ['hello'], 'hey' => ['hello'], 'help' => ['help'], 'thanks' => ['hello'],
  ];

  /**
   * Weighted expansions for a stemmed term: [term => weight].
   */
  public static function expand(string $stem): array {
    static $map;
    if ($map === NULL) {
      $map = [];
      foreach (self::SYNONYMS as $word => $list) {
        foreach ($list as $syn) {
          foreach (Text::terms($syn) as $s) {
            $map[Text::stem($word)][$s] = 0.6;
          }
        }
      }
    }
    return $map[$stem] ?? [];
  }

  /**
   * Readable synonyms for a stemmed term, for showing to people.
   */
  public static function display(string $stem): array {
    static $map;
    if ($map === NULL) {
      $map = [];
      foreach (self::SYNONYMS as $word => $list) {
        $map[Text::stem($word)] = array_merge($map[Text::stem($word)] ?? [], $list);
      }
    }
    return array_values(array_unique($map[$stem] ?? []));
  }

  /**
   * Concepts for a stemmed term.
   */
  public static function concepts(string $stem): array {
    static $map;
    if ($map === NULL) {
      $map = [];
      foreach (self::CONCEPTS as $word => $list) {
        $map[Text::stem($word)] = array_merge($map[Text::stem($word)] ?? [], $list);
      }
    }
    return $map[$stem] ?? [];
  }

  /**
   * All stems the thesaurus knows (used for typo correction).
   */
  public static function vocabulary(): array {
    $words = array_merge(array_keys(self::SYNONYMS), array_keys(self::CONCEPTS));
    return array_values(array_unique(array_map([Text::class, 'stem'], $words)));
  }

}
