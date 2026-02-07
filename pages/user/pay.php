<?php
if (!defined('FastCore')) { exit('Oops!'); }

// Some routes include pages inside a closure (error boundary), which means
// variables from the parent scope are not automatically available here.
// Always pull DB/config from $GLOBALS as a fallback.
global $db, $config, $opt, $CURRENT_USER, $func;
if (!isset($db) && isset($GLOBALS['db'])) { $db = $GLOBALS['db']; }
if (!isset($config) && isset($GLOBALS['config'])) { $config = $GLOBALS['config']; }

/** Page meta */
$opt['title'] = 'Withdrawal of funds';

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
if (!$user) {
    echo '<div class="alert alert-danger text-center">User not found.</div>';
    return;
}

/** --- NEW: bootstrap $func and safe CSRF helpers --- */
if (!isset($func) || !is_object($func)) {
    $funcPath = __DIR__ . '/../../core/func.php';
    if (is_file($funcPath)) require_once $funcPath;
    if (class_exists('func')) $func = new func();
}
$csrfVerify = function (): bool {
    return (isset($GLOBALS['func']) && method_exists($GLOBALS['func'],'csrfVerify'))
        ? (bool)$GLOBALS['func']->csrfVerify()
        : true;
};
$csrfField = function (): void {
    if (isset($GLOBALS['func']) && method_exists($GLOBALS['func'],'csrf')) {
        $GLOBALS['func']->csrf();
    } else {
        echo '<input type="hidden" name="_csrf" value="1">';
    }
};
/** -------------------------------------------------- */

$username = $user['login'] ?? ('u'.$uid);

/** Ban check */
if (!empty($user['ban']) && (int)$user['ban'] === 1) {
    exit('Your account has been blocked for violating the rules');
}

/** Load global config row (optional) */
$cnf = $db->query("SELECT * FROM db_conf WHERE id = '1' LIMIT 1")->fetchArray();

/** Selected payout method: /user/pay/{method} */
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
  --vx-text:#eaf0ff;
  --vx-muted:#a8b2d1;
  --vx-accent:#00ffe0;
  --vx-accent-2:#58a6ff;
  --vx-warn:#fbbf24;
  --vx-success:#22c55e;
  --vx-danger:#ef4444;
  --radius:18px;
  --shadow:0 18px 48px rgba(0,0,0,.45);
  --glow:0 24px 80px rgba(0,255,224,.08), 0 8px 26px rgba(88,166,255,.08);
}
body{ background:var(--vx-bg); color:var(--vx-text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
.vx-shell{ max-width:1120px; margin:18px auto 28px; padding:14px; }
.vx-card{ background:linear-gradient(180deg, rgba(11,16,36,.90), rgba(9,13,28,.97)); border:1px solid var(--vx-border); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }
.vx-card-hd{ padding:12px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; gap:10px; }
.vx-title{ margin:0; font-weight:1000; font-size:1.12rem; letter-spacing:.01em; }
.vx-sub{ margin:0; color:var(--vx-muted); font-size:.95rem; }
.vx-card-bd{ padding:12px; }
.vx-kicker{ font-weight:900; display:flex; align-items:center; gap:8px; }
.vx-kicker i{ color:var(--vx-accent); }
.badge-chip{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.6); font-weight:900; }

/* Grid */
.vx-grid{ display:grid; grid-template-columns:1fr; gap:12px; }
@media(min-width:992px){ .vx-grid{ grid-template-columns:1.1fr .9fr; } }

/* Chips */
.info-row{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
@media(min-width:768px){ .info-row{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
.chip{ display:flex; align-items:center; gap:10px; padding:12px; border-radius:14px; border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.65); }
.chip h5{ margin:0; font-size:1rem; font-weight:900; }
.chip small{ color:var(--vx-muted); }

/* Wallet */
.wallet-box{ border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.7); border-radius:14px; padding:12px; }
.wallet-line{ display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
.wallet-mask{ font-family:"JetBrains Mono","SF Mono",ui-monospace,Menlo,monospace; font-size:.96rem; }
.wallet-actions{ display:flex; gap:8px; flex-wrap:wrap; }
.btn-vx{ border:0; border-radius:12px; padding:8px 12px; font-weight:900; }
.btn-vx-primary{ background:linear-gradient(135deg,var(--vx-accent),var(--vx-accent-2)); color:#001014; box-shadow:0 12px 28px rgba(0,255,224,.20); }
.btn-vx-secondary{ background:rgba(15,23,42,.92); color:#e2e8f0; border:1px solid rgba(148,163,184,.35); }

/* Form */
.form-wrap{ border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.72); border-radius:16px; padding:12px; }
.help{ color:var(--vx-muted); font-size:.92rem; }
.kv{ display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; }
.k{ color:var(--vx-muted); font-size:.9rem; }
.v{ font-weight:900; }
.hr{ border-top:1px dashed rgba(255,255,255,.12); margin:10px 0; }

/* Sidebar facts */
.fact{ display:flex; gap:10px; align-items:center; border:1px solid rgba(255,255,255,.12); background:rgba(15,23,42,.65); border-radius:14px; padding:12px; }

/* History */
.hist-card{ border:1px solid rgba(255,255,255,.10); background:rgba(15,23,42,.75); border-radius:16px; margin-top:12px; }
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

/* Misc */
.center{ text-align:center; }
.small-muted{ font-size:.86rem; color:var(--vx-muted); }
</style>

<div class="vx-shell">
<?php
/** Selected method branch **/
if ($py) {
    // sanitize route segment
    $pyName = strtolower(preg_replace('~[^a-z0-9_-]~i', '', (string)$py));
    if ($py && $pyName === '') {
        echo '<div class="alert alert-danger text-center">Invalid payout method.</div>';
        return;
    }

    // Load payment system
    $paySys = $db->query('SELECT * FROM db_paysystem WHERE `name` = ? LIMIT 1', strtoupper($pyName))->fetchArray();
    if (!$paySys) {
        echo '<div class="alert alert-danger text-center">Payment system not found.</div>';
        return;
    }

    // Settings/limits
    $systemPay = (string)$paySys['name'];
    $currency  = (string)$paySys['currency'];
    $minPay    = round((float)$paySys['minpay'], 2);
    $maxPay    = 1000.00;            // Global cap
    $accPay    = 5.00;               // Min deposit to unlock withdrawals
    $todayLimit= 24;                 // Hours between payouts
    $psVal     = strtolower($currency);

    // User’s saved wallet for this system
    $purseRow  = $db->query('SELECT * FROM db_purse WHERE `name` = ? AND `uid` = ? LIMIT 1', $systemPay, $uid)->fetchArray();
    $valid     = $purseRow['purse'] ?? false;

    // Validate/format wallet if validator class exists
    $purse = $valid;
    if (class_exists('wallets')) {
        $wallets  = new wallets();
        $method   = $systemPay . '_wallet';
        if (method_exists($wallets, $method)) {
            $purse = $wallets->{$method}($valid);
        }
    }

    // Available balance = money_p - hold (money_b)
    $moneyP  = isset($user['money_p']) ? (float)$user['money_p'] : 0.0;
    $moneyH  = isset($user['money_b']) ? (float)$user['money_b'] : 0.0;
    $sumUser = max(0.0, $moneyP - $moneyH);

    // Handle payout request (server-side processing unchanged)
    if (isset($_POST['pay']) && $csrfVerify()) {
        $sum = isset($_POST['sum']) ? (float)$_POST['sum'] : 0.0;

        // flat 5% fee
        $commission = 5.00;

        // Fee in site currency
        $sumNet = round($sum - ($sum * $commission / 100), 2);

        // Convert to payout currency
        $rate       = isset($paySys['pairss']) ? (float)$paySys['pairss'] : 1.0;
        $sumConv    = $sum * $rate;
        $sumConvNet = round($sumConv - ($sumConv * $commission / 100), 2);

        if ($valid !== false && $purse) {
            // 24h lock since last payout row (if any)
            $payments = $db->query('SELECT * FROM db_payout WHERE uid = ? ORDER BY id DESC LIMIT 1', $uid)->fetchArray();
            $lastAdd  = isset($payments['add']) ? (int)$payments['add'] : 0;

            if (time() >= ($lastAdd + (3600 * $todayLimit))) {
                if ($sum >= $minPay) {
                    if ($sum <= $maxPay) {
                        if ((float)($user['sum_in'] ?? 0) >= $accPay) {
                            if ($sum <= $sumUser) {
                                if ($currency === 'USD') {
                                    if (!class_exists('rfs_payeer')) {
                                        echo '<div class="alert alert-danger">Payeer gateway not installed on server.</div>';
                                    } else {
                                        $payeer = new rfs_payeer($config->py_NUM, $config->py_apiID, $config->py_apiKEY);
                                        if ($payeer->isAuth()) {
                                            $arBalance = $payeer->getBalance();
                                            if (!empty($arBalance) && (int)$arBalance['auth_error'] === 0) {
                                                $balance = (float)($arBalance['balance']['USD']['DOSTUPNO'] ?? 0);
                                                $psSys   = '1136053'; // Payeer PS id
                                                if ($balance >= $sum) {
                                                    $array = [
                                                        'action'              => 'output',
                                                        'ps'                  => $psSys,
                                                        'curIn'               => 'USD',
                                                        'sumOut'              => $sumConvNet,
                                                        'curOut'              => 'USD',
                                                        'param_ACCOUNT_NUMBER'=> $purse,
                                                    ];
                                                    $initOutput = $payeer->initOutput($array);
                                                    if ($initOutput) {
                                                        $historyId = $payeer->output();
                                                        if (!empty($historyId)) {
                                                            $db->query('UPDATE db_users SET money_p = money_p - ? WHERE id = ?', $sum, $uid);
                                                            $da = time(); $dd = $da + 60*60*24*15;
                                                            $db->query(
                                                                'INSERT INTO db_payout (uid, login, purse, sum, sum2, `sys`, `add`, `del`, status)
                                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 3)',
                                                                $uid, $username, $purse, $sum, $sumConvNet, $currency, $da, $dd
                                                            );
                                                            $db->query('UPDATE db_users SET sum_out = sum_out + ? WHERE id = ?', $sum, $uid);
                                                            $db->query('UPDATE db_stats SET payments = payments + ? WHERE id = 1', $sum);
                                                            echo '<div class="alert alert-success">Funds have been successfully transferred to your wallet</div>';
                                                        } else {
                                                            echo '<div class="alert alert-danger">Payment failed: gateway rejected the operation.</div>';
                                                        }
                                                    } else {
                                                        echo '<div class="alert alert-danger">Payment initialization failed.</div>';
                                                    }
                                                } else {
                                                    echo '<div class="alert alert-danger">Insufficient Payeer USD balance on gateway.</div>';
                                                }
                                            } else {
                                                echo '<div class="alert alert-danger">Payeer authentication error.</div>';
                                            }
                                        } else {
                                            echo '<div class="alert alert-danger">Payeer not authenticated.</div>';
                                        }
                                    }
                                } else {
                                    // Paykassa path (crypto, etc.)
                                    if (!class_exists('PaykassaAPI')) {
                                        echo '<div class="alert alert-danger">Paykassa API library not installed on server.</div>';
                                    } else {
                                        $paykassa_api_id       = $config->pka_id;
                                        $paykassa_api_password = $config->pka_pass;
                                        $paykassa_merchant_id  = $config->pkm_id;
                                        $test = false;

                                        $paykassa = new PaykassaAPI($paykassa_api_id, $paykassa_api_password, $test);
                                        $params = [
                                            "merchant_id" => $paykassa_merchant_id,
                                            "wallet"      => ["address" => $purse, "tag" => ""],
                                            "amount"      => $sumConvNet,
                                            "system"      => $systemPay,
                                            "currency"    => $currency,
                                            "comment"     => "Payout for user ".$user['id'],
                                            "priority"    => "high",
                                        ];

                                        $res = $paykassa->sendMoney(
                                            $params["merchant_id"],
                                            $params["wallet"],
                                            $params["amount"],
                                            $params["system"],
                                            $params["currency"],
                                            $params["comment"],
                                            $params["priority"]
                                        );

                                        if (!empty($res['error'])) {
                                            echo '<div class="alert alert-danger text-center">'.h($res['message']).'</div>';
                                        } else {
                                            $db->query('UPDATE db_users SET money_p = money_p - ? WHERE id = ?', $sum, $uid);
                                            $da = time(); $dd = $da + 60*60*24*15;
                                            $db->query(
                                                'INSERT INTO db_payout (uid, login, purse, sum, sum2, `sys`, `add`, `del`, status)
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 3)',
                                                $uid, $username, $purse, $sum, $sumConvNet, $currency, $da, $dd
                                            );
                                            $db->query('UPDATE db_users SET sum_out = sum_out + ? WHERE id = ?', $sum, $uid);
                                            $db->query('UPDATE db_stats SET payments = payments + ? WHERE id = 1', $sum);
                                            echo '<div class="alert alert-success">Funds have been successfully transferred to your wallet.</div>';
                                        }
                                    }
                                }
                            } else {
                                echo '<div class="alert alert-warning">Error. Available balance for withdrawal '
                                   . number_format($sumUser, 2, '.', '')
                                   . ' {!VAL!}.</div>';
                            }
                        } else {
                            echo '<div class="alert alert-danger">Make a deposit of at least '
                               . number_format($accPay, 2, '.', '')
                               . ' {!VAL!} to enable withdrawals.</div>';
                        }
                    } else {
                        echo '<div class="alert alert-danger">Maximum payout amount is <b>'
                           . number_format($maxPay, 2, '.', '')
                           . ' {!VAL!}</b>.</div>';
                    }
                } else {
                    echo '<div class="alert alert-danger">You have already ordered a payment in the last '
                       . (int)$todayLimit . ' hours.</div>';
                }
            } else {
                echo '<div class="alert alert-warning">Purse appears invalid: '.h((string)$purse).'</div>';
            }
        } else {
            echo '<div class="alert alert-danger text-uppercase">Error! '
               . h($systemPay)
               . ' address not saved.<br><b>You need to save the wallet address in <a href="/user/settings">settings</a>.</b></div>';
        }
    }

    // Wallet mask / reveal
    if ($valid === false || $valid === '' || $valid === null) {
        $userWallet = '<span class="text-success">You need to save the wallet address in <a href="/user/settings" class="text-light">settings</a>.</span>';
    } else {
        $masked = $valid;
        if (strlen($masked) > 7) {
            $masked = substr($masked, 0, -4) . "<span style='color:#ff9218;'>.....</span>";
        }
        $userWallet = '<span class="notranslate" id="mytrx">'.$masked.'</span>';
    }

    $rate = isset($paySys['pairss']) ? (float)$paySys['pairss'] : 1.0;
    $sumCalc = $sumUser * $rate;
    ?>

    <section class="vx-card" data-system-name="<?= h($systemPay); ?>">
      <div class="vx-card-hd">
        <div>
          <div class="vx-kicker"><i class="fa-solid fa-paper-plane"></i> Withdraw</div>
          <h1 class="vx-title"><?= h($systemPay); ?> <span class="badge-chip"><?= h($currency); ?></span></h1>
          <p class="vx-sub">Payouts have a <b>5% fee</b> and a <b><?= (int)$todayLimit; ?>h</b> cooldown between requests.</p>
        </div>
        <a class="btn btn-sm btn-outline-light" href="/user/pay"><i class="fa fa-arrow-left"></i> Methods</a>
      </div>

      <div class="vx-card-bd">
        <div class="vx-grid">
          <!-- LEFT: Wallet + Form -->
          <div>
            <div class="wallet-box">
              <div class="wallet-line">
                <div>
                  <div class="small-muted">Your wallet (<?= h($currency); ?>):</div>
                  <div class="wallet-mask" id="walletMask"><?= $userWallet; ?></div>
                </div>
                <div class="wallet-actions">
                  <?php if ($valid): ?>
                  <button class="btn-vx btn-vx-secondary" id="btnReveal"><i class="fa fa-eye"></i> Reveal</button>
                  <button class="btn-vx btn-vx-primary" id="btnCopy"><i class="fa fa-copy"></i> Copy</button>
                  <?php else: ?>
                  <a class="btn-vx btn-vx-primary" href="/user/settings"><i class="fa fa-pen"></i> Add wallet</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="form-wrap mt-2">
              <form action="" method="POST" novalidate>
                <?php $csrfField(); ?>
                <div class="mb-2">
                  <label class="form-label">Payout amount ({!VAL!})</label>
                  <div class="input-group input-group-lg">
                    <input type="number" step="0.01" class="form-control"
                           placeholder="Enter amount"
                           value="<?= number_format($sumUser, 2, '.', ''); ?>"
                           id="amountInput"
                           min="<?= h($minPay); ?>"
                           max="<?= h($maxPay); ?>"
                           name="sum">
                    <span class="input-group-text">{!VAL!}</span>
                  </div>
                  <div class="help mt-1">Available: <b id="availValue"><?= number_format($sumUser, 2, '.', ''); ?></b> {!VAL!}</div>
                </div>

                <div class="kv">
                  <div><div class="k">Fee (5%)</div><div class="v" id="feeVal">$0.00</div></div>
                  <div><div class="k">You receive (site currency)</div><div class="v" id="netVal">$0.00</div></div>
                  <div><div class="k">Rate</div><div class="v" id="rateVal"><?= number_format($rate, 6, '.', ''); ?></div></div>
                  <div><div class="k">Converted receive (<?= h($currency); ?>)</div><div class="v" id="convVal"><?= number_format($sumCalc, 6, '.', ''); ?></div></div>
                </div>

                <div class="hr"></div>

                <button class="btn-vx btn-vx-primary w-100 py-2" name="pay" type="submit">
                  <i class="fa fa-paper-plane"></i> Withdraw
                </button>

                <input type="hidden" name="per" id="percent" value="<?= h($rate); ?>">
              </form>
              <div class="small-muted mt-2">
                Note: conversions use the current system rate. Network or gateway fees (if any) are external to our 5% platform fee.
              </div>
            </div>
          </div>

          <!-- RIGHT: Facts -->
          <div>
            <div class="fact mb-2">
              <i class="fa fa-shield-halved"></i>
              <div><h5 class="m-0">Min withdrawal</h5><small>$<?= number_format($minPay, 2, '.', ''); ?> {!VAL!}</small></div>
            </div>
            <div class="fact mb-2">
              <i class="fa fa-circle-up"></i>
              <div><h5 class="m-0">Max per transaction</h5><small>$<?= number_format($maxPay, 2, '.', ''); ?> {!VAL!}</small></div>
            </div>
            <div class="fact mb-2">
              <i class="fa fa-percent"></i>
              <div><h5 class="m-0">Fee</h5><small>5% of payout amount</small></div>
            </div>
            <div class="fact mb-2">
              <i class="fa fa-hourglass-half"></i>
              <div><h5 class="m-0">Cooldown</h5><small><?= (int)$todayLimit; ?> hours between requests</small></div>
            </div>
            <div class="fact">
              <i class="fa fa-lock"></i>
              <div><h5 class="m-0">Eligibility</h5><small>Deposited at least $<?= number_format($accPay, 2, '.', ''); ?> {!VAL!}</small></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- HISTORY -->
    <section class="hist-card">
      <div class="hist-hd">
        <h3 class="title"><i class="fa fa-clock-rotate-left"></i> My last payouts</h3>
      </div>
      <div class="p-2 p-sm-3">
        <div class="table-responsive">
          <table class="table-vx">
            <thead>
              <tr>
                <th class="text-center">Status</th>
                <th class="text-center">Sum</th>
                <th class="text-center">Currency</th>
                <th class="text-end">Time</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $status_array2 = [
                  0 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Wait</span>',
                  1 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Wait</span>',
                  2 => '<span class="badge-status"><i class="fa fa-times err"></i> Cancel</span>',
                  3 => '<span class="badge-status"><i class="fa fa-check ok"></i> Success</span>',
                ];
                $rows = $db->query('SELECT * FROM db_payout WHERE uid = ? ORDER BY id DESC LIMIT 10', $uid)->fetchAll();
                if (!$rows) {
                  echo '<tr><td colspan="4" class="center text-muted">No payouts found.</td></tr>';
                } else {
                  foreach ($rows as $row) {
                    $st = (int)$row['status'];
                    echo '<tr class="notranslate">
                            <td class="text-center">'.($status_array2[$st] ?? '-').'</td>
                            <td class="text-center"><span class="text-sum">$'.sprintf('%.2f',(float)$row['sum']).' <small>{!VAL!}</small></span></td>
                            <td class="text-center"><span class="text-sum">$'.sprintf('%.2f',(float)$row['sum2']).' <small>'.h($row['currency']).'</small></span></td>
                            <td class="text-end">'.date('d M Y - H:i', (int)$row['add']).'</td>
                          </tr>';
                  }
                }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <script>
    (function(){
      // Wallet reveal/copy
      const full = <?= json_encode($valid ?: ''); ?>;
      const maskHost = document.getElementById('walletMask');
      const revealBtn = document.getElementById('btnReveal');
      const copyBtn = document.getElementById('btnCopy');

      function toast(m){ try{ vxToast(m); }catch(e){ alert(m); } }

      if (revealBtn && maskHost){
        revealBtn.addEventListener('click', function(){
          const el = document.getElementById('mytrx');
          if (el) el.textContent = full || '';
          revealBtn.disabled = true;
        });
      }
      function copyText(text){
        if (!text) return;
        if (navigator.clipboard && window.isSecureContext){
          navigator.clipboard.writeText(text).then(()=>toast('Wallet copied')).catch(()=>fallback());
        } else { fallback(); }
        function fallback(){
          const ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta);
          ta.select(); document.execCommand && document.execCommand('copy');
          document.body.removeChild(ta); toast('Wallet copied');
        }
      }
      if (copyBtn){
        copyBtn.addEventListener('click', function(){ copyText(full || ''); });
      }

      // Live calculator
      const input = document.getElementById('amountInput');
      const feeEl = document.getElementById('feeVal');
      const netEl = document.getElementById('netVal');
      const convEl= document.getElementById('convVal');
      const rateEl= document.getElementById('rateVal');
      const rate  = parseFloat(document.getElementById('percent').value || '1');
      function fmt(n, d){ return (isFinite(n)? n:0).toFixed(d); }

      function recalc(){
        const v = Math.max(0, parseFloat(input.value || '0'));
        const fee = v * 0.05;
        const net = v - fee;
        const conv= net * rate;

        feeEl.textContent = '$' + fmt(fee, 2);
        netEl.textContent = '$' + fmt(net, 2);
        convEl.textContent= fmt(conv, 6) + ' <?= h($currency); ?>';
      }
      if (input){ input.addEventListener('input', recalc); recalc(); }

      // API-only (optional) – keep your existing hooks intact:
      // (Your two #vx-withdraw-* scripts below will still bind if present)
    })();
    </script>

    <?php
    return;
}
/** ======================= NO METHOD CHOSEN: LIST ======================= */
require_once __DIR__ . '/../../_fake_pay.php';
?>
<section class="vx-card">
  <div class="vx-card-hd">
    <div>
      <div class="vx-kicker"><i class="fa fa-wallet"></i> Select method</div>
      <h1 class="vx-title">Choose a payout network</h1>
      <p class="vx-sub">Withdraw in USD or crypto. Fee is 5%. Cooldown <?= (int)24; ?>h between requests.</p>
    </div>
  </div>
  <div class="vx-card-bd">
    <div class="row g-2">
      <?php
      $pslist = $db->query('SELECT * FROM db_paysystem WHERE name != ? ORDER BY id ASC', 'FaucetPay')->fetchAll();
      if (!$pslist) {
        echo '<div class="text-center text-muted">No payout systems available.</div>';
      }
      foreach ($pslist as $psl):
          $psPage = strtolower($psl['name']);
          $psVal  = strtolower($psl['currency']);
      ?>
        <div class="col-xl-3 col-lg-4 col-md-6">
          <a href="/user/pay/<?= h($psPage); ?>" class="chip w-100 text-decoration-none text-reset">
            <img loading="lazy" decoding="async" src="/img/pay/ps/<?= h($psPage); ?>.png" alt="<?= h($psl['title']); ?>" style="width:42px;height:42px;object-fit:contain;background:#020617;border-radius:10px;">
            <div>
              <h5 class="mb-0"><?= h($psl['title']); ?></h5>
              <small><?= h($psl['currency']); ?></small>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="hist-card">
  <div class="hist-hd">
    <h3 class="title"><i class="fa fa-clock-rotate-left"></i> My last payouts</h3>
  </div>
  <div class="p-2 p-sm-3">
    <div class="table-responsive">
      <table class="table-vx">
        <thead>
          <tr>
            <th class="text-center">Status</th>
            <th class="text-center">Sum</th>
            <th class="text-center">Currency</th>
            <th class="text-end">Time</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $status_array2 = [
              0 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Wait</span>',
              1 => '<span class="badge-status"><i class="fa fa-clock wait"></i> Wait</span>',
              2 => '<span class="badge-status"><i class="fa fa-times err"></i> Cancel</span>',
              3 => '<span class="badge-status"><i class="fa fa-check ok"></i> Success</span>',
            ];
            $rows = $db->query('SELECT * FROM db_payout WHERE uid = ? ORDER BY id DESC LIMIT 10', $uid)->fetchAll();
            if (!$rows) {
              echo '<tr><td colspan="4" class="center text-muted">No payouts found.</td></tr>';
            } else {
              foreach ($rows as $row) {
                $st = (int)$row['status'];
                echo '<tr class="notranslate">
                        <td class="text-center">'.($status_array2[$st] ?? '-').'</td>
                        <td class="text-center"><span class="text-sum">$'.sprintf('%.2f',(float)$row['sum']).' <small>{!VAL!}</small></span></td>
                        <td class="text-center"><span class="text-sum">$'.sprintf('%.2f',(float)$row['sum2']).' <small>'.h($row['currency']).'</small></span></td>
                        <td class="text-end">'.date('d M Y - H:i', (int)$row['add']).'</td>
                      </tr>';
              }
            }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Keep your existing API hooks intact (they’ll still bind if you want AJAX): -->
<script id="vx-withdraw-hook">
(function(){
  function ready(f){if(document.readyState!=='loading')f();else document.addEventListener('DOMContentLoaded',f);}
  function toast(m){try{vxToast(m);}catch(e){alert(m);}}
  ready(function(){
    var form = document.querySelector('form[action=""][method="POST"]');
    if(!form) return;
    form.addEventListener('submit', function(ev){
      ev.preventDefault(); ev.stopPropagation(); if(ev.stopImmediatePropagation) ev.stopImmediatePropagation();
      var amt = form.querySelector('[name="sum"]'); if(!amt) return toast('Amount required');
      var sysEl = document.querySelector('[data-system-name]'); var sys = sysEl ? sysEl.getAttribute('data-system-name') : (form.getAttribute('data-system')||'');
      if(!sys) { sys = (document.querySelector('input[name=system]')||{}).value || ''; }
      fetch('/api/user/withdraw_paykassa.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'amount=' + encodeURIComponent(amt.value) + '&system=' + encodeURIComponent(sys) + '&csrf=' + encodeURIComponent(VX_CSRF)
      }).then(r=>r.json()).then(function(j){
        toast((j && j.msg) ? j.msg : 'Done');

        if (j && j.ok) {
          var box = document.getElementById('vxWithdrawProof');
          if (box) {
            var proof = (j.proof || '').toString();
            var pid = (j.payout_id || '').toString();
            if (proof) {
              box.style.display = 'block';
              box.innerHTML = ''
                + '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">'
                + '  <div style="font-weight:1000"><i class="fa-solid fa-shield-check"></i> Withdrawal proof</div>'
                + '  <div class="vx-badge ok">Request #' + pid + '</div>'
                + '</div>'
                + '<div style="margin-top:10px;padding:10px 12px;border-radius:16px;border:1px solid rgba(148,163,184,.12);background:rgba(2,6,23,.35);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">'
                + '  <div style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \'Liberation Mono\', \'Courier New\', monospace;font-size:.78rem;opacity:.92;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%">' + proof + '</div>'
                + '  <button type="button" class="btn vx-btn-ghost" style="padding:6px 10px;font-size:.78rem" data-copy="' + proof.replace(/"/g,'&quot;') + '"><i class="fa-solid fa-copy"></i> Copy proof</button>'
                + '</div>'
                + '<div class="vx-sub" style="margin-top:8px;opacity:.8">Keep this receipt for support if needed.</div>';
            }
          }
        }
      }).catch(function(){ toast('Network error'); });
      return false;
    }, {capture:true});

    document.addEventListener('click', function(ev){
      var b = ev.target && (ev.target.closest ? ev.target.closest('[data-copy]') : null);
      if(!b) return;
      ev.preventDefault();
      var t = b.getAttribute('data-copy') || '';
      if(!t) return;
      try{ navigator.clipboard.writeText(t).then(function(){ toast('Copied'); }); }catch(e){ toast('Copy failed'); }
    });
  });
})();
</script>

<script id="vx-withdraw-api-only">
(function(){
  function ready(f){if(document.readyState!=='loading')f();else document.addEventListener('DOMContentLoaded',f);}
  function toast(m){try{vxToast(m);}catch(e){alert(m);}}
  ready(function(){
    var form = document.querySelector('form[action=""][method="POST"], form:not([action])[method="POST"], form[action="#"][method="POST"]');
    if (!form) return;
    form.setAttribute('novalidate','novalidate');
    form.addEventListener('submit', function(ev){
      ev.preventDefault(); ev.stopPropagation(); if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
      var amt = form.querySelector('[name="sum"]'); if (!amt || !amt.value) return toast('Enter amount');
      var sys = (document.querySelector('[data-system-name]')||{}).getAttribute && document.querySelector('[data-system-name]').getAttribute('data-system-name');
      if (!sys) { var inp = document.querySelector('input[name="system"]'); sys = inp ? inp.value : ''; }
      if (!sys) return toast('Select payment system');
      var btn = form.querySelector('button[type="submit"],input[type="submit"]'); if (btn) btn.disabled = true;
      fetch('/api/user/withdraw_paykassa.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'amount=' + encodeURIComponent(amt.value) + '&system=' + encodeURIComponent(sys)
      }).then(function(r){ return r.json().catch(function(){ return {ok:false,msg:'Non-JSON response'}; }); })
        .then(function(j){ toast(j.msg || (j.ok?'Paid':'Payment failed')); if (j.ok) setTimeout(function(){ location.reload(); }, 800); })
        .catch(function(){ toast('Network error'); })
        .finally(function(){ if (btn) btn.disabled = false; });
      return false;
    }, {capture:true});

    document.addEventListener('click', function(ev){
      var b = ev.target && (ev.target.closest ? ev.target.closest('[data-copy]') : null);
      if(!b) return;
      ev.preventDefault();
      var t = b.getAttribute('data-copy') || '';
      if(!t) return;
      try{ navigator.clipboard.writeText(t).then(function(){ toast('Copied'); }); }catch(e){ toast('Copy failed'); }
    });
  });
})();
</script>
