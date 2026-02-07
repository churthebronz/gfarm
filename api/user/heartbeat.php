<?php
// File: /api/user/heartbeat.php
// Purpose: lightweight poll endpoint for dashboard (fail-soft).

require_once __DIR__ . '/_bootstrap.php'; // sets $uid, $db, helpers

$now = time();

// Touch earnings (yield)
$st = ['pending'=>0.0,'per_second'=>0.0];
try {
  $st = vx_earnings_touch($db, (int)$uid);
} catch (Throwable $e) {}

// Basic "active today" count (optional; fail-soft)
$dayStart = strtotime(gmdate('Y-m-d 00:00:00'));
$activeToday = null;
try {
  // Use adaptive events_log helper schema
  $cols = vx_events_log_columns($db);
  if ($cols['schema'] === 'legacy') {
    $r = $db->query("SELECT COUNT(DISTINCT uid) AS c FROM events_log WHERE created_at>=?", $dayStart)->fetchArray();
  } else {
    $r = $db->query("SELECT COUNT(DISTINCT user_id) AS c FROM events_log WHERE created_at>=?", $dayStart)->fetchArray();
  }
  if ($r && isset($r['c'])) $activeToday = (int)$r['c'];
} catch (Throwable $e) {}

vx_json_out([
  'ok' => true,
  't' => $now,
  'uid' => (int)$uid,
  'yield' => $st,
  'active_today' => $activeToday,
], 200);
