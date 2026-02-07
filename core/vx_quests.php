<?php
// core/vx_quests.php
// Daily quest strip + weekly quest pack (Points). Designed for TG mini app retention.
// Tasks: checkin, share, vault. Completion bonus once/day.
// Anti-abuse: server-side, stored in user_meta.

if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/vx_retention.php';
require_once __DIR__ . '/vx_points.php';

function vx_quest_day_key(int $ts = 0): string {
  if ($ts <= 0) $ts = time();
  return gmdate('Ymd', $ts); // UTC day
}

function vx_week_key(int $ts = 0): string {
  if ($ts <= 0) $ts = time();
  return gmdate('o-\WW', $ts); // ISO year-week in UTC
}

function vx_daily_task_key(string $task, string $dayKey): string {
  return 'dq_'.$dayKey.'_'.$task;
}

function vx_daily_done_key(string $dayKey): string {
  return 'dq_'.$dayKey.'_done';
}

function vx_week_pack_claim_key(string $weekKey): string {
  return 'wq_'.$weekKey.'_pack_claimed';
}

function vx_daily_mark_task($db, int $uid, string $task): bool {
  $task = preg_replace('/[^a-z_]/', '', strtolower($task));
  if ($uid <= 0 || $task === '') return false;
  $d = vx_quest_day_key();
  $k = vx_daily_task_key($task, $d);
  if ((string)vx_meta_get($db, $uid, $k, '0') === '1') return false;
  return vx_meta_set($db, $uid, $k, '1');
}

function vx_daily_status($db, int $uid): array {
  $d = vx_quest_day_key();
  $tasks = ['checkin','share','vault'];
  $st = ['day'=>$d,'tasks'=>[],'all_done'=>false,'bonus_claimed'=>false];
  foreach ($tasks as $t) {
    $st['tasks'][$t] = ((string)vx_meta_get($db, $uid, vx_daily_task_key($t,$d), '0') === '1');
  }
  $st['bonus_claimed'] = ((string)vx_meta_get($db, $uid, vx_daily_done_key($d), '0') === '1');
  $st['all_done'] = ($st['tasks']['checkin'] && $st['tasks']['share'] && $st['tasks']['vault']);
  return $st;
}

function vx_daily_try_award($db, int $uid): bool {
  $st = vx_daily_status($db, $uid);
  if (!$st['all_done']) return false;
  if ($st['bonus_claimed']) return false;

  // award once per UTC day
  $bonus = 100; // keep modest; consistent dopamine.
  $ok = vx_points_add($db, $uid, $bonus, 'Daily Quest Bonus', ['day'=>$st['day']]);
  if ($ok) {
    vx_meta_set($db, $uid, vx_daily_done_key($st['day']), '1');
    vx_notify_once($db, $uid, 'dq_'.$st['day'], 'daily_quests', 'success', 'Daily quests complete! +'.$bonus.' Points.', ['day'=>$st['day'], 'pts'=>$bonus]);
  }
  return $ok;
}

// Weekly quest pack: complete 3 daily tasks at least once this week (checkin/share/vault) to claim a pack.
function vx_weekly_pack_status($db, int $uid): array {
  $wk = vx_week_key();
  // We count completion of each task at least once in the week.
  // Store as meta flags set by task-markers.
  $keys = [
    'checkin' => 'wq_'.$wk.'_checkin',
    'share'   => 'wq_'.$wk.'_share',
    'vault'   => 'wq_'.$wk.'_vault',
  ];
  $s = ['week'=>$wk,'tasks'=>[],'all_done'=>false,'claimed'=>false,'reward'=>150];
  foreach ($keys as $t=>$k) {
    $s['tasks'][$t] = ((string)vx_meta_get($db, $uid, $k, '0') === '1');
  }
  $s['all_done'] = ($s['tasks']['checkin'] && $s['tasks']['share'] && $s['tasks']['vault']);
  $s['claimed'] = ((string)vx_meta_get($db, $uid, vx_week_pack_claim_key($wk), '0') === '1');
  return $s;
}

function vx_weekly_mark($db, int $uid, string $task): void {
  $task = preg_replace('/[^a-z_]/', '', strtolower($task));
  if ($uid <= 0 || $task === '') return;
  $wk = vx_week_key();
  $k = 'wq_'.$wk.'_'.$task;
  if ((string)vx_meta_get($db, $uid, $k, '0') !== '1') {
    vx_meta_set($db, $uid, $k, '1');
  }
}

function vx_weekly_pack_try_claim($db, int $uid): bool {
  $s = vx_weekly_pack_status($db, $uid);
  if (!$s['all_done'] || $s['claimed']) return false;
  $ok = vx_points_add($db, $uid, (int)$s['reward'], 'Weekly Quest Pack', ['week'=>$s['week']]);
  if ($ok) {
    vx_meta_set($db, $uid, vx_week_pack_claim_key($s['week']), '1');
    vx_notify_once($db, $uid, 'wq_pack_'.$s['week'], 'weekly_pack', 'success', 'Weekly Quest Pack claimed! +'.$s['reward'].' Points.', ['week'=>$s['week'], 'pts'=>$s['reward']]);
  }
  return $ok;
}
