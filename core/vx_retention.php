<?php
// core/vx_retention.php
// Lightweight retention utilities: streaks, meta, soft notifications
if (!defined('FastCore')) define('FastCore', true);

function vx_meta_get($db, int $uid, string $key, $default=null) {
  try {
    $q = $db->query("SELECT meta_value FROM user_meta WHERE user_id=? AND meta_key=? LIMIT 1", $uid, $key);
    $r = $q ? ($q->fetchArray() ?: []) : [];
    if (!isset($r['meta_value'])) return $default;
    return $r['meta_value'];
  } catch (Throwable $e) { return $default; }
}

function vx_meta_set($db, int $uid, string $key, string $value): bool {
  try {
    // Upsert
    $db->query("INSERT INTO user_meta (user_id, meta_key, meta_value, created_at, updated_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
                $uid, $key, $value);
    return true;
  } catch (Throwable $e) { return false; }
}

function vx_notify_add($db, int $uid, string $type, string $level, string $title, string $message, array $data=[]): void {
  try {
    $db->query("INSERT INTO notifications_log (user_id, type, level, title, message, data_json, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)",
                $uid, $type, $level, $title, $message, json_encode($data, JSON_UNESCAPED_SLASHES));
  } catch (Throwable $e) {}
}

function vx_notify_once($db, int $uid, string $uniqKey, string $type, string $level, string $title, string $message, array $data=[]): void {
  try {
    $q = $db->query("SELECT id FROM notifications_log WHERE user_id=? AND type=? AND data_json LIKE ? LIMIT 1",
      $uid, $type, '%"uk":"'.addslashes($uniqKey).'"%');
    $r = $q ? ($q->fetchArray() ?: []) : [];
    if (!empty($r['id'])) return;
    $data['uk'] = $uniqKey;
    vx_notify_add($db, $uid, $type, $level, $title, $message, $data);
  } catch (Throwable $e) {}
}

function vx_streak_tick($db, int $uid): array {
  // stores: streak_days, last_open (Y-m-d)
  $today = gmdate('Y-m-d');
  $last = (string)vx_meta_get($db, $uid, 'last_open', '');
  $streak = (int)vx_meta_get($db, $uid, 'streak_days', '0');
  if ($last === $today) return ['streak'=>$streak, 'today'=>$today];

  if ($last !== '') {
    $d1 = strtotime($last.' 00:00:00 UTC');
    $d2 = strtotime($today.' 00:00:00 UTC');
    $diff = (int)(($d2 - $d1) / 86400);

    if ($diff === 1) {
      $streak = max(1, $streak + 1);
    } elseif ($diff === 2) {
      // Streak Freeze: 1 free "missed day" per ISO week (auto-applied).
      $weekKey = gmdate('o-\WW', strtotime($today.' 00:00:00 UTC'));
      $used = (string)vx_meta_get($db, $uid, 'streak_freeze_week', '');
      if ($used !== $weekKey) {
        vx_meta_set($db, $uid, 'streak_freeze_week', $weekKey);
        // keep streak as-is (no increment)
        vx_notify_once($db, $uid, 'freeze_'.$weekKey, 'streak_freeze', 'info', 'Streak freeze used', 'You missed a day — your streak was protected this week.', [
          'week' => $weekKey,
          'streak' => $streak,
        ]);
      } else {
        $streak = 1;
      }
    } else {
      // Streak Shield boost (paid): protect streak once on a hard reset.
      try {
        require_once __DIR__ . '/vx_boosts.php';
        if (vx_boost_is_active($db, $uid, 'streak_shield')) {
          vx_boost_consume($db, $uid, 'streak_shield');
          vx_notify_once($db, $uid, 'streak_shield_used_'.$today, 'streak_shield', 'success', 'Streak protected', 'Your Streak Shield protected you from a reset.', ['day'=>$today,'streak'=>$streak]);
          // keep streak unchanged
        } else {
          $streak = 1;
        }
      } catch (Throwable $e) {
        $streak = 1;
      }
    }
  } else {
    $streak = 1;
  }

  vx_meta_set($db, $uid, 'last_open', $today);
  vx_meta_set($db, $uid, 'streak_days', (string)$streak);
  return ['streak'=>$streak, 'today'=>$today];
}

// Claim streak: increments only when a user successfully claims yield.
// Stores: claim_streak_days, claim_last_day (UTC Y-m-d)
function vx_claim_streak_tick($db, int $uid): array {
  $today = gmdate('Y-m-d');
  $last = (string)vx_meta_get($db, $uid, 'claim_last_day', '');
  $streak = (int)vx_meta_get($db, $uid, 'claim_streak_days', '0');

  if ($last === $today) {
    return ['streak'=>$streak, 'today'=>$today, 'changed'=>false];
  }

  if ($last !== '') {
    $d1 = strtotime($last.' 00:00:00 UTC');
    $d2 = strtotime($today.' 00:00:00 UTC');
    $diff = (int)(($d2 - $d1) / 86400);
    if ($diff === 1) {
      $streak = max(1, $streak + 1);
    } else {
      // Any gap bigger than 1 day resets.
      $streak = 1;
    }
  } else {
    $streak = 1;
  }

  vx_meta_set($db, $uid, 'claim_last_day', $today);
  vx_meta_set($db, $uid, 'claim_streak_days', (string)$streak);
  return ['streak'=>$streak, 'today'=>$today, 'changed'=>true];
}

function vx_retention_tick($db, int $uid): void {
  // 0) Process due referral qualifications globally (small batch).
  // This releases any escrowed referral Points and upgrades statuses from pending -> qualified.
  try {
    require_once __DIR__ . '/vx_refqual.php';
    vx_refqual_process_due($db, 12);
  } catch (Throwable $e) {}

  // 1) streak
  vx_streak_tick($db, $uid);

  // 2) vault finished (best-effort based on db_insert end timestamp)
  $now = time();
  try {
    // find recently finished inserts (end <= now) that are still status=1 (common pattern)
    $q = $db->query("SELECT id, end, sum, type FROM db_insert WHERE uid=? AND end>0 AND end<=? ORDER BY end DESC LIMIT 20", $uid, $now);
    if ($q) {
      while ($r = $q->fetchArray()) {
        $insId = (int)($r['id'] ?? 0);
        if ($insId <= 0) continue;
        $uk = 'vault_finished_'.$insId;
        vx_notify_once($db, $uid, $uk, 'vault_finished', 'success', 'Seed matured', 'One of your vault timers has completed. Check Rewards for your latest status.', [
          'insert_id'=>$insId,
          'end'=>(int)($r['end'] ?? 0),
          'sum'=>(float)($r['sum'] ?? 0),
          'type'=>(int)($r['type'] ?? 0),
        ]);
        // only notify 1 per request
        break;
      }
    }
  } catch (Throwable $e) {}

  // 2b) Mutant Crop finished (db_store). Prompt reactivation to earn Legacy VP.
  try {
    require_once __DIR__ . '/vx_legacy.php';
    $q = $db->query("SELECT id, tarif, `end` FROM db_store WHERE uid=? AND status=2 AND `end`>0 AND `end`<=? ORDER BY `end` DESC LIMIT 20", $uid, $now);
    if ($q) {
      while ($r = $q->fetchArray()) {
        $sid = (int)($r['id'] ?? 0);
        if ($sid <= 0) continue;

        // Reactivation prompt (once per ended guardian)
        $prompted = (string)vx_meta_get($db, $uid, 'guardian_end_prompted_'.$sid, '0');
        if ($prompted !== '1') {
          vx_meta_set($db, $uid, 'guardian_end_prompted_'.$sid, '1');
          vx_notify_once(
            $db,
            $uid,
            'guardian_end_'.$sid,
            'guardian_ended',
            'warning',
            'Mutant Crop ended',
            'Reactivate this Mutant Crop to earn balance again AND unlock Legacy VP (only earned by reactivations after a full term).',
            ['store_id'=>$sid,'tarif_id'=>(int)($r['tarif'] ?? 0),'end_ts'=>(int)($r['end'] ?? 0),'cta'=>'/user/guardians?reactivate='.(int)($r['tarif'] ?? 0)]
          );
          break; // one popup per load
        }

        // Collector conversion after 24h without reactivation
        $end = (int)($r['end'] ?? 0);
        if ($end > 0 && ($now - $end) >= 86400) {
          $collector = (string)vx_meta_get($db, $uid, 'collector_store_'.$sid, '0');
          $reactivated = (string)vx_meta_get($db, $uid, 'reactivated_after_store_'.$sid, '0');
          if ($collector !== '1' && $reactivated !== '1') {
            vx_legacy_mark_collector($db, $uid, $sid);
            vx_notify_once(
              $db,
              $uid,
              'collector_'.$sid,
              'guardian_collector',
              'info',
              'Mutant Crop added to Mutant Index',
              'This completed Mutant Crop is now a collectible in your Mutant Index. You can reactivate future Guardians to unlock more Legacy VP.',
              ['store_id'=>$sid,'tarif_id'=>(int)($r['tarif'] ?? 0),'end_ts'=>$end,'cta'=>'/user/codex']
            );
            break;
          }
        }
      }
    }
  } catch (Throwable $e) {}

  // 3) season ending soon (within 72h)
  try {
    $q = $db->query("SELECT season_no, ends_at FROM vx_seasons ORDER BY ends_at DESC LIMIT 1");
    $s = $q ? ($q->fetchArray() ?: []) : [];
    $end = (int)($s['ends_at'] ?? 0);
    $no = (int)($s['season_no'] ?? 0);
    if ($end > 0 && $no > 0) {
      $left = $end - $now;
      if ($left > 0 && $left <= 72*3600) {
        $uk = 'season_ending_'.$no;
        vx_notify_once($db, $uid, $uk, 'season_ending', 'warning', 'Season ending soon', 'Season ends soon. Push your rank now to lock in rewards.', [
          'season_no'=>$no,
          'ends_ts'=>$end
        ]);
      }
    }
  } catch (Throwable $e) {}

  // 4) referral momentum (refs increased)
  try {
    $q = $db->query("SELECT refs FROM db_users WHERE id=? LIMIT 1", $uid);
    $u = $q ? ($q->fetchArray() ?: []) : [];
    $refs = (int)($u['refs'] ?? 0);
    $lastRefs = (int)vx_meta_get($db, $uid, 'refs_last', '0');
    if ($refs > $lastRefs) {
      vx_meta_set($db, $uid, 'refs_last', (string)$refs);
      $delta = $refs - $lastRefs;
      $uk = 'refs_up_'.$refs;
      vx_notify_once($db, $uid, $uk, 'ref_payout', 'success', 'New referral joined', '+'.$delta.' new referral(s). Keep it going — your weekly rank updates fast.', [
        'refs'=>$refs, 'delta'=>$delta
      ]);
    } elseif ($lastRefs === 0) {
      vx_meta_set($db, $uid, 'refs_last', (string)$refs);
    }
  } catch (Throwable $e) {}

  // 5) virality ladder (qualified invites -> Points, anti-abuse)
  try {
    require_once __DIR__ . '/vx_virality.php';
    vx_virality_tick($db, $uid);
  } catch (Throwable $e) {}


  // 6) Weekly Quest Pack (3 tiny tasks -> Points)
  try{
    $wk = gmdate('o-\WW'); // ISO week key
    $k1 = 'qwk_'.$wk.'_checkin';
    $k2 = 'qwk_'.$wk.'_share';
    $k3 = 'qwk_'.$wk.'_vault';
    $kDone = 'qwk_'.$wk.'_pack';

    $a = (string)vx_meta_get($db, $uid, $k1, '0');
    $b = (string)vx_meta_get($db, $uid, $k2, '0');
    $c = (string)vx_meta_get($db, $uid, $k3, '0');
    $done = (string)vx_meta_get($db, $uid, $kDone, '0');

    if ($done !== '1' && $a==='1' && $b==='1' && $c==='1') {
      require_once __DIR__ . '/vx_points.php';
      $packPts = 150;
      if (vx_points_add($db, $uid, $packPts, 'Weekly quest pack', ['week'=>$wk])) {
        vx_meta_set($db, $uid, $kDone, '1');
        vx_notify_once($db, $uid, 'qpack_'.$wk, 'weekly_quest_pack', 'success', 'Weekly quest pack complete', 'You completed this week\'s mini-quests and earned +'.$packPts.' Points.', ['week'=>$wk,'pts'=>$packPts]);
      }
    }
  }catch(Throwable $e){}
}

