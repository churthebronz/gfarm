<?php
declare(strict_types=1);

/**
 * Daily Shop selection (no cron, deterministic, same-for-all)
 * - Resets at 00:00 UTC
 * - Picks exactly 9 purchasable guardians (db_tarif kind='plan', unlock_method='purchase', is_active=1)
 * - Featured: exactly 1 Legendary per day (cycles through all Legendary)
 * - Mythic excluded entirely from daily shop
 * - Additional Legendary: at most 1 extra Legendary (so total Legendary<=2)
 *
 * Returns:
 *  [
 *    'day_key' => 'YYYY-MM-DD',
 *    'reset_ts' => int (next UTC midnight),
 *    'featured_id' => int,
 *    'plans' => array<int,array> (length <= limit),
 *  ]
 */

require_once __DIR__ . '/vx_rarity.php';

if (!function_exists('vx_daily_shop_hash')) {
  function vx_daily_shop_hash(string $dayKey, int $id): string {
    return hash('sha256', $dayKey . '|' . (string)$id);
  }
}

if (!function_exists('vx_daily_shop_pick')) {
  /**
   * @param mixed $db
   * @return array{day_key:string,reset_ts:int,featured_id:int,plans:array}
   */
  function vx_daily_shop_pick($db, int $limit = 9): array {
    $limit = max(1, (int)$limit);
    $now = time();

    // UTC day key + next reset (UTC midnight)
    $dayKey = gmdate('Y-m-d', $now);
    $dayIndex = (int)floor($now / 86400); // UTC days since epoch
    $resetTs = (int)strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC') + 86400;

    // Pull all active purchasable plans
    $rows = [];
    try {
      $rows = $db->query("SELECT * FROM db_tarif WHERE is_active = 1 AND kind = 'plan' AND unlock_method = 'purchase'")->fetchAll();
    } catch (Throwable $e) {
      try { $rows = $db->query("SELECT * FROM db_tarif WHERE is_active = 1")->fetchAll(); } catch (Throwable $e2) { $rows = []; }
    }

    if (!$rows || !is_array($rows)) {
      return ['day_key'=>$dayKey,'reset_ts'=>$resetTs,'featured_id'=>0,'plans'=>[]];
    }

    // Partition by rarity (mythic excluded from daily shop)
    $legendary = [];
    $nonTop = []; // common/rare/epic only
    foreach ($rows as $r) {
      $id = (int)($r['id'] ?? 0);
      if ($id <= 0) continue;

      // Determine rarity (prefer stored rarity if present; else derived)
      $price = (float)($r['price'] ?? 0);
      $title = (string)($r['title'] ?? '');
      $rr = 'common';
      try {
        $rx = vx_rarity_for_tarif($db, $id, $title, $price);
        $rr = (string)($rx['rarity'] ?? 'common');
      } catch (Throwable $e) {}
      $rr = vx_rarity_normalize($rr);

      if ($rr === 'mythic') {
        // Mythic excluded entirely.
        continue;
      } elseif ($rr === 'legendary') {
        $legendary[] = $r;
      } else {
        $nonTop[] = $r;
      }
    }

    // Featured legendary (cycles)
    $featured = null;
    $featuredId = 0;
    if (count($legendary) > 0) {
      usort($legendary, function($a,$b){
        $ga = (int)($a['guardian_no'] ?? 999999);
        $gb = (int)($b['guardian_no'] ?? 999999);
        if ($ga !== $gb) return $ga <=> $gb;
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
      });
      $featured = $legendary[$dayIndex % count($legendary)];
      $featuredId = (int)($featured['id'] ?? 0);
    } else {
      // No legendary available (fail-soft): choose best available from nonTop by hash
      if (count($nonTop) > 0) {
        usort($nonTop, function($a,$b) use ($dayKey){
          $ha = vx_daily_shop_hash($dayKey, (int)$a['id']);
          $hb = vx_daily_shop_hash($dayKey, (int)$b['id']);
          return strcmp($ha, $hb);
        });
        $featured = $nonTop[0];
        $featuredId = (int)($featured['id'] ?? 0);
      }
    }

    // Optional extra legendary (max 1 additional legendary; never mythic)
    $extraLegendary = null;
    if ($featuredId > 0 && count($legendary) > 1 && $limit >= 2) {
      $pool = array_values(array_filter($legendary, function($x) use ($featuredId){
        return (int)($x['id'] ?? 0) !== $featuredId;
      }));
      usort($pool, function($a,$b) use ($dayKey){
        $ha = vx_daily_shop_hash($dayKey, (int)$a['id']);
        $hb = vx_daily_shop_hash($dayKey, (int)$b['id']);
        return strcmp($ha, $hb);
      });
      $extraLegendary = $pool[0] ?? null;
    }

    // Fill remainder from nonTop (deterministic daily shuffle)
    usort($nonTop, function($a,$b) use ($dayKey){
      $ha = vx_daily_shop_hash($dayKey, (int)$a['id']);
      $hb = vx_daily_shop_hash($dayKey, (int)$b['id']);
      return strcmp($ha, $hb);
    });

    $picked = [];
    $seen = [];

    $push = function($row) use (&$picked, &$seen, $limit) {
      if (!$row || count($picked) >= $limit) return;
      $id = (int)($row['id'] ?? 0);
      if ($id <= 0 || isset($seen[$id])) return;
      $seen[$id] = 1;
      $picked[] = $row;
    };

    $push($featured);
    $push($extraLegendary);

    foreach ($nonTop as $r) {
      $push($r);
      if (count($picked) >= $limit) break;
    }

    return ['day_key'=>$dayKey,'reset_ts'=>$resetTs,'featured_id'=>$featuredId,'plans'=>$picked];
  }
}
