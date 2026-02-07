<?php
declare(strict_types=1);

/**
 * Mutant Crop rarity helpers.
 *
 * Uses `vault_definitions.rarity` if present (preferred), otherwise derives
 * a rarity from the guardian entry price.
 */

if (!function_exists('vx_rarity_normalize')) {
  function vx_rarity_normalize(string $r): string {
    $r = strtolower(trim($r));
    $allowed = ['common','rare','epic','legendary','mythic'];
    return in_array($r, $allowed, true) ? $r : 'common';
  }
}

if (!function_exists('vx_rarity_for_tarif')) {
  /**
   * @return array{rarity:string,source:string}
   */
  function vx_rarity_for_tarif($db, int $tarifId, string $title = '', float $priceUsd = 0.0): array {
    // Best: vault_definitions table (new TCG layer).
    try {
      if ($db && method_exists($db, 'query')) {
        // By ID first.
        $row = $db->query('SELECT rarity FROM vault_definitions WHERE id = ? LIMIT 1', [$tarifId])->fetchArray();
        if (!empty($row['rarity'])) {
          return ['rarity' => vx_rarity_normalize((string)$row['rarity']), 'source' => 'vault_definitions:id'];
        }
        // Fallback: name match.
        if ($title !== '') {
          $row2 = $db->query('SELECT rarity FROM vault_definitions WHERE name = ? LIMIT 1', [$title])->fetchArray();
          if (!empty($row2['rarity'])) {
            return ['rarity' => vx_rarity_normalize((string)$row2['rarity']), 'source' => 'vault_definitions:name'];
          }
        }
      }
    } catch (Throwable $e) {
      // Fail-soft.
    }

    // Derived rarity (price tiers). Adjust whenever you add real rarities.
    $p = max(0.0, (float)$priceUsd);
    // Default ladder: small entries are common, then rare/epic/legendary/mythic.
    if ($p >= 10000) return ['rarity' => 'mythic',    'source' => 'derived:price'];
    if ($p >= 2500)  return ['rarity' => 'legendary', 'source' => 'derived:price'];
    if ($p >= 500)   return ['rarity' => 'epic',      'source' => 'derived:price'];
    if ($p >= 100)   return ['rarity' => 'rare',      'source' => 'derived:price'];
    return ['rarity' => 'common', 'source' => 'derived:price'];
  }
}

if (!function_exists('vx_rarity_label')) {
  function vx_rarity_label(string $rarity): string {
    $r = vx_rarity_normalize($rarity);
    return strtoupper($r);
  }
}

if (!function_exists('vx_rarity_css_class')) {
  function vx_rarity_css_class(string $rarity): string {
    $r = vx_rarity_normalize($rarity);
    return 'vx-tier-' . $r;
  }
}
