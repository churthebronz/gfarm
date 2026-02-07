<?php
if (!defined('FastCore')) { exit('Oops!'); }

// Included inside closure? Pull globals for DB/config/user context.
global $db, $config, $opt, $CURRENT_USER, $func;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

/** Page meta */
$opt['title'] = 'Make a deposit';

/** Ensure session + DB */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($db) || !($db instanceof db)) { exit('DB unavailable'); }

/** Resolve current user */
$uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
if ($uid <= 0) {
    header('Location: /auth', true, 302);
    exit;
}

/** Try to reuse hydrated user (from index), else query */
$user = isset($CURRENT_USER) && is_array($CURRENT_USER) ? $CURRENT_USER : null;
if (!$user) {
    $user = $db->query('SELECT * FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray();
}
if (!$user) { echo '<div class="alert alert-danger text-center">User not found.</div>'; return; }

$username = $user['login'] ?? ('u'.$uid);

/** Ban check */
if (!empty($user['ban']) && (int)$user['ban'] === 1) {
    exit('Your account has been blocked for violating the rules');
}

/** Payment method from route: /user/insert/{method} */
$py = $pg->segment[2] ?? null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
:root{
  --vx-bg:#060816;
  --vx-surface:#0b1024;
  --vx-surface-2:#0e1430;
  --vx-border:rgba(255,255,255,.10);
  --vx-text:#eef2ff;
  --vx-muted:#a8b2d1;
  --vx-accent:#00ffe0;
  --vx-accent-2:#58a6ff;
  --vx-warn:#fbbf24;
  --vx-hot:#f97316;
  --vx-success:#22c55e;
  --vx-danger:#ef4444;
  --radius:18px;
  --shadow:0 18px 48px rgba(0,0,0,.45);
}

body{ background:var(--vx-bg); color:var(--vx-text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
.vx-shell{ max-width:1120px; margin:18px auto 28px; padding:14px; }
.vx-card{ background:linear-gradient(180deg, rgba(11,16,36,.9), rgba(9,13,28,.97)); border:1px solid var(--vx-border); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
.vx-card-hd{ padding:12px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; gap:10px; }
.vx-title{ margin:0; font-weight:1000; font-size:1.12rem; letter-spacing:.01em; }
.vx-sub{ margin:0; color:var(--vx-muted); font-size:.95rem; }
.vx-card-bd{ padding:12px; }
.vx-kicker{ font-weight:900; color:#fff; display:flex; align-items:center; gap:8px; }
.vx-kicker i{ color:var(--vx-accent); }
.badge-chip{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.6); font-weight:900; }

/* ====== METHOD PICKER ====== */
.method-grid{ display:grid; grid-template-columns:1fr; gap:10px; }
/* TG mobile feels better as a single down-list */
@media(min-width:820px){ .method-grid{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media(min-width:992px){ .method-grid{ grid-template-columns:repeat(4,minmax(0,1fr)); } }

@media(max-width:700px){
  .method{ display:flex; gap:12px; align-items:center; padding:12px; }
  .method .ico{ flex:0 0 44px; width:44px; height:44px; border-radius:14px; }
  .method h3{ margin:0; font-size:1rem; }
  .method .meta{ margin:2px 0 0; font-size:.86rem; }
  .method .btn-go{ margin-left:auto; border-radius:999px; padding:10px 12px; }
}
.method{ display:flex; align-items:center; gap:10px; padding:12px; border-radius:14px; border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.65); text-decoration:none; color:inherit; transition:transform .12s, border-color .16s, box-shadow .2s; }
.method:hover{ transform:translateY(-2px); border-color:rgba(0,255,224,.5); box-shadow:0 20px 60px rgba(0,255,224,.10); }
.method img{ width:42px; height:42px; object-fit:contain; background:#020617; border-radius:10px; }
.method .t{ font-weight:900; }
.method .c{ font-size:.84rem; color:var(--vx-muted); }

/* ====== DEPOSIT DETAILS (MOBILE-FIRST) ====== */
.pay-wrap{ display:grid; grid-template-columns:1fr; gap:12px; }
@media(min-width:992px){ .pay-wrap{ grid-template-columns:1.1fr .9fr; } }

.info-block{ border-radius:16px; border:1px solid rgba(255,255,255,.10); background:rgba(15,23,42,.75); padding:12px; }

.addr-wrap{ display:grid; gap:10px; }
.addr-label{ font-weight:1000; display:flex; align-items:center; gap:8px; }

/* Address box (FULL VIEW) */
.addr-box{ border:1px dashed rgba(0,255,224,.45); background:rgba(2,6,23,.6); border-radius:14px; padding:10px; }
.addr-textarea{
  width:100%; min-height:64px; max-height:220px;
  display:block; border:0; outline:0; background:transparent; color:var(--vx-text);
  font-family:"JetBrains Mono","SF Mono",ui-monospace,Menlo,monospace; font-size:.96rem; line-height:1.35;
  white-space:nowrap; overflow:auto; resize:none;
}
.addr-actions-row{ display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.btn-copy, .btn-open{
  border:0; border-radius:12px; padding:10px 12px; font-weight:900; cursor:pointer;
}
.btn-copy{ background:linear-gradient(135deg, var(--vx-accent), var(--vx-accent-2)); color:#001014; box-shadow:0 12px 26px rgba(0,255,224,.25); }
.btn-open{ background:rgba(15,23,42,.92); color:#e2e8f0; border:1px solid rgba(148,163,184,.30); }

/* Meta */
.meta-row{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.meta{ border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:10px; background:rgba(2,6,23,.45); }
.meta .k{ font-size:.84rem; color:var(--vx-muted); }
.meta .v{ font-weight:1000; }

/* QR */
.qr-card{ display:flex; align-items:center; justify-content:center; border-radius:16px; border:1px solid rgba(255,255,255,.10); background:rgba(2,6,23,.75); padding:12px; }
.qr-card img{ width:220px; height:220px; object-fit:cover; border-radius:12px; }
.qr-note{ text-align:center; font-size:.92rem; color:var(--vx-muted); margin-top:6px; }

/* Notes */
.warn{ background:rgba(251,146,60,.12); border:1px solid rgba(251,146,60,.45); color:#fde68a; border-radius:12px; padding:10px; font-size:.95rem; }

/* Sticky CTA */
.cta-bar{ position:sticky; bottom:10px; z-index:9; margin-top:10px; }
.cta-inner{ display:flex; gap:8px; background:rgba(2,6,23,.92); border:1px solid rgba(255,255,255,.10); border-radius:14px; padding:8px; box-shadow:0 18px 48px rgba(0,0,0,.45); }
.cta-inner .btn{ flex:1; font-weight:900; }
.btn-primary-vx{ border:0; border-radius:12px; padding:10px 14px; background:linear-gradient(135deg,var(--vx-accent),var(--vx-accent-2)); color:#001014; }
.btn-secondary-vx{ border-radius:12px; padding:10px 14px; background:rgba(15,23,42,.92); color:#e2e8f0; border:1px solid rgba(148,163,184,.35); }

/* ====== HISTORY ====== */
.hist-card{ margin-top:12px; border-radius:16px; border:1px solid rgba(255,255,255,.10); background:rgba(15,23,42,.75); }
.hist-hd{ padding:10px 12px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid rgba(255,255,255,.08); }
.hist-hd .title{ margin:0; font-weight:1000; font-size:1rem; }
.table-vx{ width:100%; border-collapse:collapse; }
.table-vx th, .table-vx td{ padding:10px 8px; border-bottom:1px solid rgba(148,163,184,.14); }
.table-vx th{ font-weight:900; color:#e5e7eb; text-transform:uppercase; font-size:.78rem; letter-spacing:.05em; }
.table-vx td{ color:#e2e8f0; font-size:.94rem; }
.badge-status{ display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:999px; font-weight:900; border:1px solid rgba(255,255,255,.14); background:rgba(2,6,23,.55); }
.badge-status .ok{ color:var(--vx-success); }
.badge-status .wait{ color:var(--vx-warn); }
.badge-status .err{ color:var(--vx-danger); }
.cancel-btn{ border:0; border-radius:999px; padding:8px 12px; background:linear-gradient(180deg,#ef4444,#dc2626); color:#fff; font-weight:900; cursor:pointer; }
.cancel-btn:hover{ filter:brightness(1.05); }

/* helpers */
.mt-6{ margin-top:1.25rem; }
.center{ text-align:center; }
.nowrap{ white-space:nowrap; }
</style>

<div class="vx-shell">
<?php
try {
    if ($py) {
        /** Normalize selected payment method */
        $selectPS = mb_strtoupper($py);

        /** Load payment system */
        $systemPay = $db->query('SELECT * FROM db_paysystem WHERE name = ? LIMIT 1', $selectPS)->fetchArray();
        if (!$systemPay) { throw new Exception('Payment system not found'); }

        $minDep     = (float)$systemPay['mindep'];
        $currencyPS = (string)$systemPay['currency'];
        $psTitle    = (string)$systemPay['title'];

        /** Create order (pending) */
        $db->query(
            "INSERT INTO db_insert (uid, login, sum, sum_x, sys, `add`, role, type, status)
             VALUES (?, ?, ?, ?, ?, ?, '1', '1', '0')",
            $uid, $username, $minDep, $minDep, $currencyPS, time()
        );
        $order_idd = (int)$db->lastInsert();

        /** Paykassa */
        $calculate = isset($systemPay['pairs']) ? ((float)$systemPay['pairs'] * $minDep) : $minDep;
        $test = false;
        if (!class_exists('PaykassaSCI')) { throw new Exception('Payment gateway class (PaykassaSCI) not found on server'); }
        $paykassa = new PaykassaSCI($config->pkm_id, $config->pkm_pass, $test);

        $res = $paykassa->createAddress(
            $systemPay['name'],     // system
            $currencyPS,            // currency ticker
            $order_idd,             // order id
            "Insert by id-".$order_idd
        );
        if (!empty($res['error'])) { throw new Exception($res['message'] ?? 'Unknown gateway error'); }

        if ($test === false) {
            $invoice_id = $res['data']['invoice'] ?? '';
            $address    = $res['data']['wallet']  ?? '';
            $tag        = $res['data']['tag']     ?? '';
            $tag_name   = $res['data']['tag_name']?? '';
            $is_tag     = !empty($res['data']['is_tag']);
            $system     = $res['data']['system']  ?? $systemPay['name'];
            $currency   = $res['data']['currency']?? $currencyPS;

            // URI + QR
            $system_lower = strtolower((string)$system);
            $uri_prefix = '';
            if (strpos($system_lower, 'bitcoin') !== false || $system_lower === 'btc')    { $uri_prefix = 'bitcoin:'; }
            elseif (strpos($system_lower, 'ethereum') !== false || $system_lower === 'eth'){ $uri_prefix = 'ethereum:'; }
            elseif (strpos($system_lower, 'litecoin') !== false || $system_lower === 'ltc'){ $uri_prefix = 'litecoin:'; }
            elseif ($system_lower === 'tron' || $system_lower === 'trx')                   { $uri_prefix = 'tron:'; }

            $paymentURI = $uri_prefix ? ($uri_prefix . $address) : $address;
            $qr_url  = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . rawurlencode($paymentURI);
            ?>
            <section class="vx-card">
              <div class="vx-card-hd">
                <div>
                  <div class="vx-kicker"><i class="fa-solid fa-coins"></i> Deposit</div>
                  <h1 class="vx-title"><?= h($psTitle); ?> <span class="badge-chip"><?= h($currency); ?></span></h1>
                  <p class="vx-sub">Send funds to the generated address. Deposits auto-credit after confirmations.</p>
                </div>
                <a class="btn btn-sm btn-outline-light" href="/user/insert"><i class="fa fa-arrow-left"></i> Methods</a>
              </div>
              <div class="vx-card-bd">
                <div class="pay-wrap">
                  <!-- LEFT: address + meta -->
                  <div class="info-block">
                    <div class="addr-wrap">
                      <div class="addr-label"><i class="fa fa-wallet"></i> Destination Address (full view)</div>

                      <!-- FULLY VISIBLE ADDRESS (no truncation) -->
                      <div class="addr-box">
                        <textarea
                          id="purse"
                          class="addr-textarea"
                          rows="2"
                          wrap="off"
                          readonly
                          spellcheck="false"
                          autocapitalize="off"
                          autocorrect="off"
                        ><?= h($address); ?></textarea>
                      </div>

                      <?php if ($is_tag && $tag && $tag_name): ?>
                        <div class="addr-label"><i class="fa fa-tag"></i> <?= h(mb_convert_case($tag_name, MB_CASE_TITLE)); ?></div>
                        <div class="addr-box">
                          <textarea
                            id="memoField"
                            class="addr-textarea"
                            rows="2"
                            wrap="off"
                            readonly
                            spellcheck="false"
                            autocapitalize="off"
                            autocorrect="off"
                          ><?= h($tag); ?></textarea>
                        </div>
                        <div class="warn">
                          <b>Memo required:</b> This network requires sending to the address <u>and</u> specifying <b><?= h(mb_convert_case($tag_name, MB_CASE_TITLE)); ?></b> = <b><?= h($tag); ?></b> in your wallet.
                        </div>
                      <?php endif; ?>

                      <!-- Buttons beneath (no overlap) -->
                      <div class="addr-actions-row">
                        <button class="btn-copy" data-copy="#purse"><i class="fa fa-copy"></i> Copy Address</button>
                        <?php if ($uri_prefix): ?>
                          <a class="btn-open" href="<?= h($paymentURI); ?>" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Open in wallet</a>
                        <?php endif; ?>
                        <?php if ($is_tag && $tag): ?>
                          <button class="btn-copy" data-copy="#memoField"><i class="fa fa-copy"></i> Copy Memo</button>
                        <?php endif; ?>
                      </div>

                    </div>

                    <div class="meta-row mt-6">
                      <div class="meta">
                        <div class="k">Minimum deposit</div>
                        <div class="v"><?= number_format($minDep, 8, '.', ''); ?> <?= h($currency); ?></div>
                      </div>
                      <div class="meta">
                        <div class="k">USD estimate</div>
                        <div class="v">$<?= number_format($calculate, 2, '.', ''); ?></div>
                      </div>
                    </div>

                    <div class="qr-note mt-6 center">
                      Funds will be credited automatically after network confirmations.
                    </div>
                  </div>

                  <!-- RIGHT: QR + guide -->
                  <div>
                    <div class="qr-card">
                      <?php if (!empty($address)): ?>
                        <img loading="lazy" decoding="async" class="qr-code" src="<?= h($qr_url); ?>" alt="Deposit QR">
                      <?php else: ?>
                        <div class="warn">Address not available for QR.</div>
                      <?php endif; ?>
                    </div>
                    <div class="info-block mt-6">
                      <div class="vx-kicker"><i class="fa fa-circle-info"></i> Quick guide</div>
                      <ol class="mt-2 mb-0" style="padding-left:18px; color:var(--vx-muted)">
                        <li>Copy the address (and memo if shown).</li>
                        <li>Send any amount <b>above the minimum</b> to this address.</li>
                        <li>Wait for confirmations. The status below will update.</li>
                      </ol>
                    </div>
                  </div>
                </div>

                <div class="cta-bar">
                  <div class="cta-inner">
                    <button id="btnCopy2" class="btn btn-primary-vx"><i class="fa fa-copy"></i> Copy Address</button>
                    <a href="/user/insert" class="btn btn-secondary-vx"><i class="fa fa-arrow-left"></i> Change Method</a>
                  </div>
                </div>
              </div>
            </section>

            <!-- HISTORY (visible on this page, mobile-friendly) -->
            <section class="hist-card">
              <div class="hist-hd">
                <h3 class="title"><i class="fa fa-clock-rotate-left"></i> My recent deposits</h3>
                <span class="badge-chip">Auto-refresh each minute</span>
              </div>
              <div class="p-2 p-sm-3">
                <div class="table-responsive">
                  <table class="table-vx">
                    <thead>
                      <tr>
                        <th class="text-center">Status</th>
                        <th class="text-center">Amount</th>
                        <th class="text-end">Time</th>
                        <th class="text-center">Action</th>
                      </tr>
                    </thead>
                    <tbody id="vxHistBody">
                      <?php
                      try {
                          $statusIcons = [
                            0 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Pending</span>',
                            1 => '<span class="badge-status"><i class="fa fa-check ok"></i> Success</span>',
                            2 => '<span class="badge-status"><i class="fa fa-times err"></i> Canceled</span>',
                          ];
                          $inserts = $db->query(
                              'SELECT * FROM db_insert WHERE uid = ? AND status IN (0,1) ORDER BY id DESC LIMIT 10', $uid
                          )->fetchAll();

                          if (empty($inserts)) {
                              echo '<tr><td colspan="4" class="center text-muted">No deposits found.</td></tr>';
                          } else {
                              foreach ($inserts as $ins) {
                                  $sumIn = sprintf('%.2f', (float)$ins['sum']);
                                  $st    = (int)$ins['status'];
                                  $icon  = $statusIcons[$st] ?? '-';
                                  $rowId = (int)$ins['id'];
                                  $ts    = is_numeric($ins['add']) ? (int)$ins['add'] : time();
                                  echo '<tr id="deposit-row-'.$rowId.'">
                                          <td class="text-center">'.$icon.'</td>
                                          <td class="text-center">$'.$sumIn.'</td>
                                          <td class="text-end">'.date('d M Y - H:i:s', $ts).'</td>
                                          <td class="text-center">'.($st === 0
                                              ? '<button class="cancel-btn" onclick="confirmCancel('.$rowId.')">Cancel</button>'
                                              : '<span class="text-muted">—</span>').'</td>
                                        </tr>';
                              }
                          }
                      } catch (Exception $e) {
                          echo '<tr><td colspan="4" class="center text-danger">Error loading deposits: '.h($e->getMessage()).'</td></tr>';
                      }
                      ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>
            <?php
        } else {
            $url = $res['data']['url'] ?? '#';
            echo '<div class="vx-shell center mt-3">Test link: <a target="_blank" href="' . h($url) . '">Open link</a></div>';
        }

    } else {
        // No method chosen: list available systems
        ?>
        <section class="vx-card">
          <div class="vx-card-hd">
            <div>
              <div class="vx-kicker"><i class="fa fa-wallet"></i> Select method</div>
              <h1 class="vx-title">Choose a crypto network</h1>
              <p class="vx-sub">Send funds to the generated address. Deposits auto-credit after confirmations.</p>
            </div>
          </div>
          <div class="vx-card-bd">
            <div class="method-grid">
              <?php
              $pslist = $db->query('SELECT * FROM db_paysystem')->fetchAll();
              if (empty($pslist)) {
                  echo '<div class="center text-danger">No payment systems available.</div>';
              }
              foreach ($pslist as $psl) {
                  $psPage = mb_strtolower($psl['name']);
                  echo '<a class="method" href="/user/insert/'.h($psPage).'">
                          <img loading="lazy" decoding="async" src="/img/pay/ps/'.h($psPage).'.png" alt="'.h($psl['title']).'">
                          <div>
                            <div class="t">'.h($psl['title']).'</div>
                            <div class="c">'.h($psl['currency']).'</div>
                          </div>
                        </a>';
              }
              ?>
            </div>
          </div>
        </section>

        <!-- HISTORY also shown on the method list page -->
        <section class="hist-card">
          <div class="hist-hd">
            <h3 class="title"><i class="fa fa-clock-rotate-left"></i> My recent deposits</h3>
          </div>
          <div class="p-2 p-sm-3">
            <div class="table-responsive">
              <table class="table-vx">
                <thead>
                  <tr>
                    <th class="text-center">Status</th>
                    <th class="text-center">Amount</th>
                    <th class="text-end">Time</th>
                    <th class="text-center">Action</th>
                  </tr>
                </thead>
                <tbody id="vxHistBody2">
                  <?php
                  try {
                      $statusIcons = [
                        0 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Pending</span>',
                        1 => '<span class="badge-status"><i class="fa fa-check ok"></i> Success</span>',
                        2 => '<span class="badge-status"><i class="fa fa-times err"></i> Canceled</span>',
                      ];
                      $inserts = $db->query(
                          'SELECT * FROM db_insert WHERE uid = ? AND status IN (0,1) ORDER BY id DESC LIMIT 10', $uid
                      )->fetchAll();

                      if (empty($inserts)) {
                          echo '<tr><td colspan="4" class="center text-muted">No deposits found.</td></tr>';
                      } else {
                          foreach ($inserts as $ins) {
                              $sumIn = sprintf('%.2f', (float)$ins['sum']);
                              $st    = (int)$ins['status'];
                              $icon  = $statusIcons[$st] ?? '-';
                              $rowId = (int)$ins['id'];
                              $ts    = is_numeric($ins['add']) ? (int)$ins['add'] : time();
                              echo '<tr id="deposit-row2-'.$rowId.'">
                                      <td class="text-center">'.$icon.'</td>
                                      <td class="text-center">$'.$sumIn.'</td>
                                      <td class="text-end">'.date('d M Y - H:i:s', $ts).'</td>
                                      <td class="text-center">'.($st === 0
                                          ? '<button class="cancel-btn" onclick="confirmCancel('.$rowId.', true)">Cancel</button>'
                                          : '<span class="text-muted">—</span>').'</td>
                                    </tr>';
                          }
                      }
                  } catch (Exception $e) {
                      echo '<tr><td colspan="4" class="center text-danger">Error loading deposits: '.h($e->getMessage()).'</td></tr>';
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <?php
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger text-center mt-3">Error: ' . h($e->getMessage()) . '</div>';
}
?>
</div>

<script>
(function(){
  function toast(msg){ try{ vxToast(msg); }catch(_){ alert(msg); } }

  function copyFromSelector(sel){
    var el = document.querySelector(sel);
    if(!el) return;
    var val = (el.value !== undefined ? el.value : el.textContent) || '';
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(val).then(function(){ toast('Copied'); }).catch(fallbackCopy);
      return;
    }
    fallbackCopy();
    function fallbackCopy(){
      try{
        // If it's a textarea, select its content
        if (el.select) {
          el.focus(); el.select();
          if (el.setSelectionRange) el.setSelectionRange(0, val.length);
          document.execCommand && document.execCommand('copy');
        } else {
          var ta = document.createElement('textarea');
          ta.value = val; document.body.appendChild(ta);
          ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        }
        toast('Copied');
      }catch(e){ toast('Copy failed — select and copy manually.'); }
    }
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-copy]');
    if(!btn) return;
    e.preventDefault();
    copyFromSelector(btn.getAttribute('data-copy'));
  });

  var btn2 = document.getElementById('btnCopy2');
  if (btn2) btn2.addEventListener('click', function(e){ e.preventDefault(); copyFromSelector('#purse'); });

  window.confirmCancel = function(depositId, listPage){
    if (!confirm('Are you sure you want to cancel this deposit?')) return;
    let canceled = JSON.parse(localStorage.getItem('canceledDeposits') || '[]');
    if (!canceled.includes(depositId)) { canceled.push(depositId); localStorage.setItem('canceledDeposits', JSON.stringify(canceled)); }
    var row = document.getElementById((listPage ? 'deposit-row2-' : 'deposit-row-') + depositId);
    if (row) row.remove();
    toast('Deposit canceled (UI).');
  };

  // Hide previously canceled
  (function hideCanceled(){
    const canceled = JSON.parse(localStorage.getItem('canceledDeposits') || '[]');
    canceled.forEach(function(id){
      var r1 = document.getElementById('deposit-row-' + id);
      var r2 = document.getElementById('deposit-row2-' + id);
      if (r1) r1.remove();
      if (r2) r2.remove();
    });
  })();
})();
</script>
