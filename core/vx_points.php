<?php
// core/vx_points.php
// Minimal, safe Points ledger + balances helper.
if (!defined('FastCore')) define('FastCore', true);

function vx_points_add($db, int $uid, int $delta, string $ctx, array $meta = []): bool {
  if ($uid <= 0 || !$db || $delta === 0) return false;
  $ctx = trim($ctx);
  if ($ctx === '') $ctx = 'Points';
  $now = time();

  // Season Pass perk: +10% points (applies to positive deltas only)
  $baseDelta = $delta;
  // Prefer explicit season_id from caller (e.g. accrual split at season boundaries)
  $seasonId = (int)($meta['season_id'] ?? 0);
  try {
    if ($delta > 0) {
      require_once __DIR__ . '/seasons.php';
      require_once __DIR__ . '/season_pass.php';
      if ($seasonId <= 0) {
        $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
        $seasonId = (int)($sx['id'] ?? 0);
      }
      if ($seasonId > 0 && function_exists('vx_season_pass_active') && vx_season_pass_active($db, $uid, $seasonId)) {
        $mult = (float)vx_season_pass_points_mult();
        $delta = (int)max(1, round($delta * $mult));
        $meta['sp_mult'] = $mult;
        $meta['base_delta'] = $baseDelta;
      }
    }
  } catch (Throwable $e) { /* fail-soft */ }


  // Ledger first (best effort) – support schema drift (DATETIME vs INT, meta_json column optional)
  try {
    if (function_exists('vx_table_exists') && vx_table_exists($db, 'db_points_ledger')) {
      $hasMeta = (function_exists('vx_column_exists') && vx_column_exists($db, 'db_points_ledger', 'meta_json'));
      // Attempt 1: uid,delta,ctx,meta_json,created_at (INT)
      try {
        if ($hasMeta) {
          $db->query(
            "INSERT INTO db_points_ledger (uid, delta, ctx, meta_json, created_at) VALUES (?, ?, ?, ?, ?)",
            $uid, $delta, $ctx, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $now
          );
        } else {
          $db->query(
            "INSERT INTO db_points_ledger (uid, delta, ctx, created_at) VALUES (?, ?, ?, ?)",
            $uid, $delta, $ctx, $now
          );
        }
      } catch (Throwable $e1) {
        // Attempt 2: created_at is DATETIME – use NOW()/FROM_UNIXTIME and drop meta if needed
        try {
          if ($hasMeta) {
            $db->query(
              "INSERT INTO db_points_ledger (uid, delta, ctx, meta_json, created_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))",
              $uid, $delta, $ctx, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $now
            );
          } else {
            $db->query(
              "INSERT INTO db_points_ledger (uid, delta, ctx, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))",
              $uid, $delta, $ctx, $now
            );
          }
        } catch (Throwable $e2) {
          // Attempt 3: last resort – let DB default created_at if present
          try {
            if ($hasMeta) {
              $db->query(
                "INSERT INTO db_points_ledger (uid, delta, ctx, meta_json) VALUES (?, ?, ?, ?)",
                $uid, $delta, $ctx, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
              );
            } else {
              $db->query(
                "INSERT INTO db_points_ledger (uid, delta, ctx) VALUES (?, ?, ?)",
                $uid, $delta, $ctx
              );
            }
          } catch (Throwable $e3) {
            // ignore ledger failures
          }
        }
      }
    }
  } catch (Throwable $e) { /* ignore */ }

  // Keep user totals consistent.
  try {
    if ($delta > 0) {
      $db->query(
        "UPDATE db_users SET points_total = points_total + ?, points_spendable = points_spendable + ? WHERE id=? LIMIT 1",
        $delta, $delta, $uid
      );
    } else {
      $db->query(
        "UPDATE db_users SET 
            points_spendable = GREATEST(0, points_spendable + ?),
            points_total = GREATEST(0, points_total + ?)
         WHERE id=? LIMIT 1",
        $delta, $delta, $uid
      );
    }
    // Also keep per-season totals (VP) for seasonal ranking
    try {
      if ($seasonId <= 0) {
        require_once __DIR__ . '/seasons.php';
        $sx = function_exists('vx_get_current_season') ? vx_get_current_season($db) : [];
        $seasonId = (int)($sx['id'] ?? 0);
      }
      if ($seasonId > 0 && $delta !== 0) {
        require_once __DIR__ . '/vx_season_points.php';
        vx_season_points_add($db, $uid, $seasonId, (int)$delta, 0);
      }
    } catch (Throwable $e) {}

    return true;
  } catch (Throwable $e) {
    return false;
  }
}

