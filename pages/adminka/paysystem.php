<?php if(!defined('FastCore')){exit('Opss!');}

ini_set('error_reporting', 1);
// Error handling is configured in /core/config.php
ini_set('display_startup_errors', 0);

global $db;

// --------------------------
// Helpers
// --------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function vx_has_table($db, $t){
  $t = addslashes((string)$t);
  try {
    $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' LIMIT 1");
    $r = $db->fetchArray();
    return !empty($r);
  } catch (Exception $e) { return false; }
}
function vx_has_col($db, $t, $c){
  $t = addslashes((string)$t);
  $c = addslashes((string)$c);
  try {
    $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}' LIMIT 1");
    $r = $db->fetchArray();
    return !empty($r);
  } catch (Exception $e) { return false; }
}

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// CSRF token
if (empty($_SESSION['_csrf_pay'])) {
  $_SESSION['_csrf_pay'] = sha1(uniqid('vxpay', true));
}
$csrf = (string)$_SESSION['_csrf_pay'];

function vx_csrf_ok($token){
  $a = isset($_SESSION['_csrf_pay']) ? (string)$_SESSION['_csrf_pay'] : '';
  $b = is_string($token) ? (string)$token : '';
  return ($a !== '' && $b !== '' && $a === $b);
}

// Safe query wrapper (uses your db->query($sql, array()) pattern)
function vx_q($db, $sql, $params = array()){
  // Many of your pages use $db->query('...', array(...))
  // so we keep that style.
  if (!is_array($params)) $params = array();
  return $db->query($sql, $params);
}

function vx_norm_cur($s){
  $s = strtoupper(trim((string)$s));
  // Keep simple safe set (letters/numbers/_)
  $s = preg_replace('/[^A-Z0-9_]/', '', $s);
  return $s;
}

function vx_float_str($v){
  // PayKassa might return strings; store as string but normalized
  $v = trim((string)$v);
  // allow digits, dot, minus
  $v = preg_replace('/[^0-9\.\-]/', '', $v);
  if ($v === '' || $v === '-' || $v === '.' || $v === '-.') return '0';
  return $v;
}

// Detect optional columns
$hasPairsUpdatedAt  = vx_has_table($db,'db_paysystem') && vx_has_col($db,'db_paysystem','pairs_updated_at');
$hasPairssUpdatedAt = vx_has_table($db,'db_paysystem') && vx_has_col($db,'db_paysystem','pairss_updated_at');

$msg = '';
$err = '';
$details = array();

// --------------------------
// Actions
// --------------------------
if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!vx_csrf_ok(isset($_POST['_csrf']) ? $_POST['_csrf'] : null)) {
    $err = 'Invalid CSRF token. Refresh the page and try again.';
  } else {

    // Update row
    if (isset($_POST['update'])) {
      $psid = (int)(isset($_POST['psid']) ? $_POST['psid'] : 0);
      $title = trim((string)$_POST['title']);
      $name = trim((string)$_POST['name']);
      $currency = vx_norm_cur($_POST['currency']);
      $pairs = vx_float_str($_POST['pairs']);
      $pairss = vx_float_str($_POST['pairss']);
      $minDep = (float)(isset($_POST['mindep']) ? $_POST['mindep'] : 0);

      if ($psid <= 0) {
        $err = 'Invalid ID.';
      } elseif ($currency === '') {
        $err = 'Currency cannot be empty.';
      } else {
        try {
          vx_q($db,
            "UPDATE db_paysystem SET title=?, name=?, currency=?, pairs=?, pairss=?, mindep=? WHERE id=? LIMIT 1",
            array($title, $name, $currency, $pairs, $pairss, $minDep, $psid)
          );
          $msg = 'Payment row updated.';
        } catch (Exception $e) {
          $err = 'Update failed: '.h($e->getMessage());
        }
      }
    }

    // Add default row
    if (isset($_POST['add'])) {
      try {
        $title = 'VIEW PAYMENT';
        $name = 'NAME PAYMENT';
        $currency = 'USDT';
        $pairs = '1';
        $pairss = '1';
        $minDep = 10;

        vx_q($db,
          "INSERT INTO db_paysystem (title,name,currency,pairs,pairss,mindep) VALUES (?,?,?,?,?,?)",
          array($title, $name, $currency, $pairs, $pairss, $minDep)
        );
        $msg = 'Payment row added.';
      } catch (Exception $e) {
        $err = 'Add failed: '.h($e->getMessage());
      }
    }

    // Delete row
    if (isset($_POST['delete'])) {
      $delId = (int)$_POST['delete'];
      if ($delId <= 0) {
        $err = 'Invalid delete ID.';
      } else {
        try {
          vx_q($db, "DELETE FROM db_paysystem WHERE id=? LIMIT 1", array($delId));
          $msg = 'Payment row deleted.';
        } catch (Exception $e) {
          $err = 'Delete failed: '.h($e->getMessage());
        }
      }
    }

    // Update rates (CUR_USDT -> pairs)
    if (isset($_POST['getcurrency'])) {
      require_once __DIR__ . "/../../core/PaykassaCurrency.php";

      try {
        $rows = $db->query("SELECT id, currency FROM db_paysystem ORDER BY id DESC LIMIT 500")->fetchAll();
      } catch (Exception $e) {
        $rows = array();
      }

      $currs = array();
      foreach ($rows as $r) {
        $c = vx_norm_cur(isset($r['currency']) ? $r['currency'] : '');
        if ($c !== '' && $c !== 'USDT') $currs[$c] = true;
      }

      $pairs = array();
      foreach (array_keys($currs) as $c) $pairs[] = $c."_USDT";
      // Always include USDT_USDT so PayKassa returns it
      $pairs[] = "USDT_USDT";
      $pairs = array_values(array_unique($pairs));

      $res = PaykassaCurrency::getCurrencyPairs($pairs);

      if (!empty($res["error"])) {
        $err = 'PayKassa error: '.h($res["message"]);
      } else {
        // Flatten data structure safely
        $map = array();
        if (!empty($res["data"]) && is_array($res["data"])) {
          foreach ($res["data"] as $chunk) {
            if (is_array($chunk)) {
              foreach ($chunk as $pair => $value) {
                $map[(string)$pair] = (string)$value;
              }
            }
          }
        }

        $updated = 0;
        $failed = 0;
        $now = time();

        foreach ($map as $pair => $rate) {
          $pair = (string)$pair;
          $rate = vx_float_str($rate);
          // Extract left currency of CUR_USDT
          $base = strstr($pair, '_', true);
          $base = vx_norm_cur($base);

          if ($base === '' || $base === 'USDT') continue;

          try {
            if ($hasPairsUpdatedAt) {
              vx_q($db, "UPDATE db_paysystem SET pairs=?, pairs_updated_at=? WHERE currency=?", array($rate, $now, $base));
            } else {
              vx_q($db, "UPDATE db_paysystem SET pairs=? WHERE currency=?", array($rate, $base));
            }
            $updated++;
          } catch (Exception $e) {
            $failed++;
            $details[] = "Failed updating pairs for {$base}: ".$e->getMessage();
          }
        }

        $msg = "Updated CUR_USDT rates → db_paysystem.pairs. Updated: {$updated}, Failed: {$failed}.";
      }
    }

    // Update rates (USDT_CUR -> pairss)
    if (isset($_POST['getcurrency2'])) {
      require_once __DIR__ . "/../../core/PaykassaCurrency.php";

      try {
        $rows = $db->query("SELECT id, currency FROM db_paysystem ORDER BY id DESC LIMIT 500")->fetchAll();
      } catch (Exception $e) {
        $rows = array();
      }

      $currs = array();
      foreach ($rows as $r) {
        $c = vx_norm_cur(isset($r['currency']) ? $r['currency'] : '');
        if ($c !== '' && $c !== 'USDT') $currs[$c] = true;
      }

      $pairs2 = array();
      foreach (array_keys($currs) as $c) $pairs2[] = "USDT_".$c;
      $pairs2[] = "USDT_USDT";
      $pairs2 = array_values(array_unique($pairs2));

      $res2 = PaykassaCurrency::getCurrencyPairs($pairs2);

      if (!empty($res2["error"])) {
        $err = 'PayKassa error: '.h($res2["message"]);
      } else {
        $map2 = array();
        if (!empty($res2["data"]) && is_array($res2["data"])) {
          foreach ($res2["data"] as $chunk) {
            if (is_array($chunk)) {
              foreach ($chunk as $pair2 => $value2) {
                $map2[(string)$pair2] = (string)$value2;
              }
            }
          }
        }

        $updated = 0;
        $failed = 0;
        $now = time();

        foreach ($map2 as $pair2 => $rate2) {
          $pair2 = (string)$pair2;
          $rate2 = vx_float_str($rate2);

          // Expect USDT_CUR → extract part after "USDT_"
          if (strpos($pair2, 'USDT_') !== 0) continue;
          $cur = vx_norm_cur(substr($pair2, 5));
          if ($cur === '' || $cur === 'USDT') continue;

          try {
            if ($hasPairssUpdatedAt) {
              vx_q($db, "UPDATE db_paysystem SET pairss=?, pairss_updated_at=? WHERE currency=?", array($rate2, $now, $cur));
            } else {
              vx_q($db, "UPDATE db_paysystem SET pairss=? WHERE currency=?", array($rate2, $cur));
            }
            $updated++;
          } catch (Exception $e) {
            $failed++;
            $details[] = "Failed updating pairss for {$cur}: ".$e->getMessage();
          }
        }

        $msg = "Updated USDT_CUR rates → db_paysystem.pairss. Updated: {$updated}, Failed: {$failed}.";
      }
    }
  }
}
?>
<style>
/* Scoped styling to match your adminka look without breaking other pages */
.vx-pay{max-width:1400px;margin:0 auto;padding:10px}
.vx-pay .vx-top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.vx-pay .vx-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-weight:900;font-size:12px}
.vx-pay h1{margin:8px 0 0;font-size:20px;font-weight:900}
.vx-pay .sub{opacity:.75;font-size:13px;margin-top:2px}
.vx-pay .vx-actions{display:flex;gap:8px;flex-wrap:wrap}
.vx-pay .vx-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);font-weight:900;font-size:13px}
.vx-pay .vx-btn:hover{background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.18)}
.vx-pay .vx-btn.warn{background:rgba(245,158,11,.10);border-color:rgba(245,158,11,.30)}
.vx-pay .vx-btn.danger{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30)}
.vx-pay .vx-btn.ok{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.30)}

.vx-pay .vx-alert{margin:10px 0 12px;padding:10px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-weight:900}
.vx-pay .vx-alert.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.vx-pay .vx-alert.bad{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}

.vx-pay .vx-card{background:rgba(15,23,42,.55);border:1px solid rgba(255,255,255,.10);border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.22)}
.vx-pay .vx-card .hd{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;background:rgba(2,6,23,.45);border-bottom:1px solid rgba(255,255,255,.08)}
.vx-pay .vx-card .bd{padding:12px 14px}
.vx-pay .vx-mini{font-size:12px;opacity:.75}
.vx-pay .vx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}

.vx-pay .vx-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:10px 0}
.vx-pay .vx-search{max-width:320px;flex:1}
.vx-pay .vx-search input{width:100%;border-radius:12px}

.vx-pay table{width:100%}
.vx-pay .tbl-wrap{overflow:auto}
.vx-pay .tbl thead th{background:rgba(2,6,23,.65);color:rgba(255,255,255,.85);font-size:12px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid rgba(255,255,255,.10)}
.vx-pay .tbl td,.vx-pay .tbl th{vertical-align:middle}
.vx-pay .tbl tbody tr:hover td{background:rgba(255,255,255,.04)}
.vx-pay .form-control{border-radius:12px}
.vx-pay .btn{border-radius:12px;font-weight:900}

@media (max-width: 640px){
  .vx-pay .vx-btn{width:100%;justify-content:center}
  .vx-pay .vx-actions{width:100%}
  .vx-pay .vx-search{max-width:none}
}
</style>

<div class="vx-pay">
  <div class="vx-top">
    <div>
      <div class="vx-pill"><i class="fa fa-exchange"></i> Pay Systems</div>
      <h1>Pay System Rates & Wiring</h1>
      <div class="sub">Updates conversion rates used across deposits & withdrawals (db_paysystem.pairs / pairss).</div>
    </div>

    <div class="vx-actions">
      <form action="" method="post" class="m-0 p-0" style="margin:0">
        <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
        <button class="vx-btn warn" name="getcurrency" type="submit" onclick="return confirm('Update CUR_USDT rates into db_paysystem.pairs?');">
          <i class="fa fa-refresh"></i> Update CUR_USDT → pairs
        </button>
      </form>

      <form action="" method="post" class="m-0 p-0" style="margin:0">
        <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
        <button class="vx-btn danger" name="getcurrency2" type="submit" onclick="return confirm('Update USDT_CUR rates into db_paysystem.pairss?');">
          <i class="fa fa-refresh"></i> Update USDT_CUR → pairss
        </button>
      </form>

      <form action="" method="post" class="m-0 p-0" style="margin:0">
        <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
        <button class="vx-btn ok" name="add" type="submit"> <i class="fa fa-plus"></i> Add</button>
      </form>
    </div>
  </div>

  <?php if ($msg !== ''): ?><div class="vx-alert ok"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="vx-alert bad"><?=h($err)?></div><?php endif; ?>

  <?php if (!empty($details)): ?>
    <div class="vx-card" style="margin-bottom:12px">
      <div class="hd"><b>Details</b><span class="vx-mini">errors / warnings</span></div>
      <div class="bd">
        <ul class="m-0">
          <?php foreach ($details as $d): ?>
            <li class="vx-mini"><?=h($d)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="vx-card">
    <div class="hd">
      <b>db_paysystem</b>
      <span class="vx-mini">last 50</span>
    </div>

    <div class="bd">
      <div class="vx-toolbar">
        <div class="vx-mini">
          Tip: Rates update uses currencies in db_paysystem (auto builds pairs). No hardcoded list anymore.
          <?php if ($hasPairsUpdatedAt || $hasPairssUpdatedAt): ?>
            <span class="vx-mono" style="margin-left:8px;opacity:.85">timestamps enabled</span>
          <?php else: ?>
            <span class="vx-mono" style="margin-left:8px;opacity:.65">timestamps not enabled (optional SQL above)</span>
          <?php endif; ?>
        </div>
        <div class="vx-search">
          <input id="vxPaySearch" class="form-control form-control-sm" placeholder="Search currency / name / title…">
        </div>
      </div>

      <div class="tbl-wrap">
        <table class="table table-bordered table-striped text-center bg-white tbl" id="vxPayTbl">
          <thead>
            <tr>
              <th style="width:70px">#</th>
              <th>Title</th>
              <th>Name</th>
              <th style="width:110px">Currency</th>
              <th style="width:120px">pairs</th>
              <th style="width:120px">pairss</th>
              <th style="width:110px">min dep</th>
              <th style="width:90px">Save</th>
              <th style="width:70px">Del</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $paySystems = array();
            try {
              $paySystems = $db->query("SELECT * FROM `db_paysystem` ORDER BY `id` DESC LIMIT 50")->fetchAll();
            } catch (Exception $e) { $paySystems = array(); }

            foreach ($paySystems as $ps):
          ?>
            <tr>
              <td class="vx-mono"><?= (int)$ps['id']; ?></td>

              <td>
                <form action="" method="post" class="m-0 p-0" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
                  <input type="text" name="title" class="form-control form-control-sm m-0" value="<?=h($ps['title']); ?>"/>
              </td>

              <td>
                  <input type="text" name="name" class="form-control form-control-sm m-0" value="<?=h($ps['name']); ?>"/>
              </td>

              <td>
                  <input type="text" name="currency" class="form-control form-control-sm m-0" value="<?=h($ps['currency']); ?>"/>
              </td>

              <td>
                  <input type="text" name="pairs" class="form-control form-control-sm m-0" value="<?=h($ps['pairs']); ?>"/>
              </td>

              <td>
                  <input type="text" name="pairss" class="form-control form-control-sm m-0" value="<?=h($ps['pairss']); ?>"/>
              </td>

              <td>
                  <input type="text" name="mindep" class="form-control form-control-sm m-0" value="<?=h($ps['mindep']); ?>"/>
              </td>

              <td>
                  <input type="hidden" name="psid" value="<?= (int)$ps['id']; ?>" />
                  <button class="btn btn-primary btn-sm" name="update" type="submit">Edit</button>
                </form>
              </td>

              <td>
                <form action="" method="post" class="m-0 p-0" style="margin:0" onsubmit="return confirm('Delete this payment row? This may break deposits/withdrawals if it is used.');">
                  <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
                  <input type="hidden" name="delete" value="<?= (int)$ps['id']; ?>" />
                  <button class="btn btn-danger btn-sm" type="submit">X</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  var input = document.getElementById('vxPaySearch');
  var table = document.getElementById('vxPayTbl');
  if(!input || !table) return;

  function norm(s){ return (s||'').toString().toLowerCase(); }

  input.addEventListener('input', function(){
    var q = norm(input.value).trim();
    var rows = table.tBodies[0].rows;
    for(var i=0;i<rows.length;i++){
      var row = rows[i];
      var txt = norm(row.innerText || row.textContent || '');
      row.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
    }
  });
})();
</script>
