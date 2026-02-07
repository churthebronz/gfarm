<?php
declare(strict_types=1);

// File: /api/user/event_status.php
// Purpose: Harvest Rush / event status for dashboard widgets.
// Notes:
// - Uses the shared user API bootstrap (fail-soft auth for TG WebView timing)
// - Never returns 401 (dashboard should not be spammed on first paint)

require_once __DIR__ . '/_bootstrap.php'; // sets $uid, $db, vx_json_out()

require_once __DIR__ . '/../../core/vx_events.php';

try {
  $payload = vx_event_status_vault_rush((int)$uid);
  // Ensure consistent envelope
  if (!is_array($payload)) $payload = ['ok'=>false];
  $payload['ok'] = true;
  vx_json_out($payload, 200);
} catch (Throwable $e) {
  vx_json_out(['ok'=>false,'error'=>'event_status'], 200);
}
