<?php
// pages/adminka/guardian_tuning.php
// GreenFarm Admin — Mutant Tuning (Crossbreed + VP/LP rates + rarity scaling)

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $adm;

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['admin'])) {
  echo '<div class="alert alert-danger text-center">Admin access required.</div>';
  return;
}

require_once __DIR__ . '/inc/admin_ops.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_rarity.php';

$opt['title'] = 'Admin • Mutant Tuning';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

// CSRF (prefer admin_ops)
$csrf = function_exists('vx_admin_csrf_token') ? vx_admin_csrf_token() : '';

// Handle POST
if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $okCsrf = true;
  if (function_exists('vx_admin_csrf_ok')) {
    $okCsrf = vx_admin_csrf_ok(isset($_POST['_csrf']) ? $_POST['_csrf'] : '');
  }
  if (!$okCsrf) {
    $err = 'Invalid CSRF token.';
  } else {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    try {
      if ($action === 'save_settings') {
        $s = vx_app_settings_read();
        $s['crossbreed_max'] = max(1, min(10, (int)($_POST['crossbreed_max'] ?? $s['crossbreed_max'])));
        $s['crossbreed_window_sec'] = max(3600, min(604800, (int)($_POST['crossbreed_window_sec'] ?? $s['crossbreed_window_sec'])));
        $s['vp_fallback_per_usd_per_day'] = max(0.0, (float)($_POST['vp_fallback_per_usd_per_day'] ?? $s['vp_fallback_per_usd_per_day']));
        $s['lp_fallback_per_usd_per_day'] = max(0.0, (float)($_POST['lp_fallback_per_usd_per_day'] ?? $s['lp_fallback_per_usd_per_day']));

        // JSON fields (arrays)
        $jsonFields = array('rarity_mult','vp_crossbreed_mult','lp_crossbreed_mult');
        foreach ($jsonFields as $k) {
          if (!isset($_POST[$k])) continue;
          $raw = trim((string)$_POST[$k]);
          if ($raw === '') continue;
          $j = json_decode($raw, true);
          if (is_array($j)) {
            $s[$k] = $j;
          }
        }

        if (!vx_app_settings_write($s)) {
          $err = 'Could not write app settings file (check permissions on /core/app_settings.json).';
        } else {
          $msg = 'Mutant economy settings saved.';
        }

      } elseif ($action === 'sync_defaults') {
        // Seed vault_definitions for all tariffs (so admin can tune from a concrete baseline)
        try { vx_guardians_schema_ensure($db); } catch (Exception $e) {}
        $db->query('SELECT id, title, price FROM db_tarif ORDER BY id ASC');
        while ($p = $db->fetchArray()) {
          $tid = (int)($p['id'] ?? 0);
          if ($tid <= 0) continue;
          vx_guardian_definition($db, $tid, (string)($p['title'] ?? ''), (float)($p['price'] ?? 0));
        }
        $msg = 'Synced defaults into vault_definitions (best effort).';

      } elseif ($action === 'save_defs') {
        try { vx_guardians_schema_ensure($db); } catch (Exception $e) {}
        $rows = isset($_POST['defs']) && is_array($_POST['defs']) ? $_POST['defs'] : array();
        $now = time();
        foreach ($rows as $id => $row) {
          $id = (int)$id;
          if ($id <= 0 || !is_array($row)) continue;
          $name = isset($row['name']) ? trim((string)$row['name']) : '';
          $rar  = isset($row['rarity']) ? vx_rarity_normalize((string)$row['rarity']) : 'common';
          $vp   = isset($row['vp_per_day']) ? (int)$row['vp_per_day'] : 0;
          $lp   = isset($row['lp_per_day']) ? (int)$row['lp_per_day'] : 0;
          if ($vp < 0) $vp = 0;
          if ($lp < 0) $lp = 0;
          $db->query(
            "INSERT INTO vault_definitions (id, name, rarity, vp_per_day, lp_per_day, updated_at) VALUES ('{$id}', '".addslashes($name)."', '".addslashes($rar)."', '{$vp}', '{$lp}', '{$now}')\n"
            ."ON DUPLICATE KEY UPDATE name=VALUES(name), rarity=VALUES(rarity), vp_per_day=VALUES(vp_per_day), lp_per_day=VALUES(lp_per_day), updated_at=VALUES(updated_at)"
          );
        }
        $msg = 'Seed definitions saved.';
      }
    } catch (Exception $e) {
      $err = 'Action failed.';
    }
  }
}

$settings = vx_app_settings_read();

// Load vault defs list
$defs = array();
try { vx_guardians_schema_ensure($db); } catch (Exception $e) {}
try {
  $db->query("SELECT t.id, t.title, t.price, d.rarity, d.vp_per_day, d.lp_per_day, d.name\n"
    ."FROM db_tarif t LEFT JOIN vault_definitions d ON d.id=t.id\n"
    ."ORDER BY t.id ASC");
  while ($r = $db->fetchArray()) {
    $defs[] = $r;
  }
} catch (Exception $e) { $defs = array(); }

include __DIR__ . '/inc/head.php';
include __DIR__ . '/inc/menu.php';
?>

<div class="container-fluid" style="max-width:1200px">
  <div class="d-flex align-items-center justify-content-between" style="gap:12px;flex-wrap:wrap">
    <div>
      <h3 style="margin:0">Mutant Tuning</h3>
      <div style="color:rgba(148,163,184,.95)">Crossbreed window, scaling factors, and per-crop VP/LP rates. No cron required — accrual is lazy + heartbeat.</div>
    </div>
    <div class="d-flex" style="gap:10px;flex-wrap:wrap">
      <form method="post" style="margin:0">
        <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="sync_defaults">
        <button class="btn btn-outline-warning"><i class="fa fa-refresh"></i> Sync defaults</button>
      </form>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success" style="margin-top:14px"><?php echo h($msg); ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger" style="margin-top:14px"><?php echo h($err); ?></div>
  <?php endif; ?>

  <div class="row" style="margin-top:14px">
    <div class="col-lg-5">
      <div class="card" style="background:rgba(2,6,23,.55); border:1px solid rgba(148,163,184,.15)">
        <div class="card-body">
          <h5 class="card-title" style="margin-bottom:10px">Economy knobs</h5>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="mb-2">
              <label class="form-label">Max crossbreed level</label>
              <input class="form-control" type="number" name="crossbreed_max" min="1" max="10" value="<?php echo (int)$settings['crossbreed_max']; ?>">
            </div>
            <div class="mb-2">
              <label class="form-label">Crossbreed window (seconds)</label>
              <input class="form-control" type="number" name="crossbreed_window_sec" min="3600" max="604800" value="<?php echo (int)$settings['crossbreed_window_sec']; ?>">
              <div style="color:rgba(148,163,184,.95); font-size:.86rem; margin-top:4px">86400 = 24h</div>
            </div>

            <div class="mb-2">
              <label class="form-label">Fallback VP/day per $ (used if vault_definitions missing)</label>
              <input class="form-control" type="number" step="0.01" name="vp_fallback_per_usd_per_day" value="<?php echo h($settings['vp_fallback_per_usd_per_day']); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Fallback LP/day per $ (crossbreed ≥ 1)</label>
              <input class="form-control" type="number" step="0.01" name="lp_fallback_per_usd_per_day" value="<?php echo h($settings['lp_fallback_per_usd_per_day']); ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">rarity_mult (JSON)</label>
              <textarea class="form-control" name="rarity_mult" rows="4"><?php echo h(json_encode($settings['rarity_mult'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">vp_crossbreed_mult (JSON array, index = level)</label>
              <textarea class="form-control" name="vp_crossbreed_mult" rows="3"><?php echo h(json_encode($settings['vp_crossbreed_mult'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">lp_crossbreed_mult (JSON array, index = level)</label>
              <textarea class="form-control" name="lp_crossbreed_mult" rows="3"><?php echo h(json_encode($settings['lp_crossbreed_mult'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></textarea>
            </div>

            <button class="btn btn-primary"><i class="fa fa-save"></i> Save settings</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card" style="background:rgba(2,6,23,.55); border:1px solid rgba(148,163,184,.15)">
        <div class="card-body">
          <h5 class="card-title" style="margin-bottom:10px">Seed definitions (VP/LP per day)</h5>
          <div style="color:rgba(148,163,184,.95); font-size:.92rem; margin-bottom:10px">
            These are the production rates used for daily VP/LP accrual while active. LP only starts at crossbreed ≥ 1.
          </div>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save_defs">

            <div style="overflow:auto; border-radius:12px; border:1px solid rgba(148,163,184,.15)">
              <table class="table table-dark table-sm" style="margin:0; min-width:760px">
                <thead>
                  <tr>
                    <th style="width:64px">ID</th>
                    <th>Title</th>
                    <th style="width:110px">Price</th>
                    <th style="width:140px">Rarity</th>
                    <th style="width:120px">VP/day</th>
                    <th style="width:120px">LP/day</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($defs as $d):
                    $id = (int)($d['id'] ?? 0);
                    $title = (string)($d['title'] ?? '');
                    $price = (float)($d['price'] ?? 0);
                    $rar = (string)($d['rarity'] ?? '');
                    if ($rar === '') {
                      $rm = vx_rarity_for_tarif($db, $id, $title, $price);
                      $rar = (string)($rm['rarity'] ?? 'common');
                    }
                    $vp = (int)($d['vp_per_day'] ?? 0);
                    $lp = (int)($d['lp_per_day'] ?? 0);
                  ?>
                  <tr>
                    <td><?php echo $id; ?></td>
                    <td>
                      <input class="form-control form-control-sm" name="defs[<?php echo $id; ?>][name]" value="<?php echo h($d['name'] ? $d['name'] : $title); ?>">
                    </td>
                    <td><?php echo h(number_format($price, 2)); ?></td>
                    <td>
                      <select class="form-select form-select-sm" name="defs[<?php echo $id; ?>][rarity]">
                        <?php
                          $opts = array('common','rare','epic','legendary','mythic');
                          foreach ($opts as $o) {
                            $sel = ($o === $rar) ? 'selected' : '';
                            echo '<option value="'.h($o).'" '.$sel.'>'.h(ucfirst($o)).'</option>';
                          }
                        ?>
                      </select>
                    </td>
                    <td><input class="form-control form-control-sm" type="number" name="defs[<?php echo $id; ?>][vp_per_day]" value="<?php echo $vp; ?>"></td>
                    <td><input class="form-control form-control-sm" type="number" name="defs[<?php echo $id; ?>][lp_per_day]" value="<?php echo $lp; ?>"></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between" style="gap:10px; flex-wrap:wrap; margin-top:12px">
              <div style="color:rgba(148,163,184,.95); font-size:.86rem">
                Tip: keep LP/day small (rarer) — rarity_mult and lp_crossbreed_mult will amplify it.
              </div>
              <button class="btn btn-primary"><i class="fa fa-save"></i> Save definitions</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/foot.php'; ?>
