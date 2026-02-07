<?php if(!defined('FastCore')){ exit('Opss!'); }

global $db, $uid, $login, $user, $func;

/* -----------------------------
   Helpers & session bootstrap
------------------------------*/
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$uid   = isset($uid)   ? (int)$uid : (int)($_SESSION['uid']   ?? 0);
$login = isset($login) ? (string)$login : (string)($_SESSION['login'] ?? '');

if ($uid <= 0) {
  echo '<div class="alert alert-warning text-center m-3">Please sign in.</div>';
  return;
}
if (!isset($db) || !($db instanceof db)) {
  echo '<div class="alert alert-danger text-center m-3">Database unavailable.</div>';
  return;
}

/* CSRF (local, framework-agnostic) */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf_ok = function($token){
  return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
};

/* Minimal fallback $func->Flogin if not present */
if (!is_object($func)) { $func = new stdClass(); }
if (!is_callable([$func, 'Flogin'])) {
  $func->Flogin = function($s){ $s = trim($s); return preg_match('~^[A-Za-z0-9_\-]{3,32}$~',$s) ? $s : false; };
}

/* Title */
$opt['title'] = 'Settings & Wallets';

/* Fresh user */
$user = $db->query('SELECT * FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray() ?: [];

/* -----------------------------
   Save wallet (POST fallback)
------------------------------*/
$msg = '';
if (isset($_POST['save_wallet'])) {
  $postName  = trim((string)($_POST['name']  ?? ''));
  $postPurse = trim((string)($_POST['purse'] ?? ''));
  $postCsrf  = (string)($_POST['csrf'] ?? '');

  if (!$csrf_ok($postCsrf)) {
    $msg = '<div class="alert alert-danger">Security token invalid. Please try again.</div>';
  } else if ($postName === '' || $postPurse === '') {
    $msg = '<div class="alert alert-danger">Wallet type or address is missing.</div>';
  } else {
    $ps = $db->query("SELECT * FROM db_paysystem WHERE LOWER(name) = LOWER(?) LIMIT 1", $postName)->fetchArray();
    if (!$ps) {
      $msg = '<div class="alert alert-danger">Unknown payment system.</div>';
    } else {
      $psName   = (string)$ps['name'];
      $psTitle  = (string)($ps['title'] ?? $psName);
      $canon    = strtolower($psName) . '_wallet';
      $purseVal = $postPurse;

      /* Optional validation via wallets class */
      if (class_exists('wallets')) {
        $wallets = new wallets();
        if (method_exists($wallets, $canon)) {
          $validated = $wallets->$canon($postPurse);
          if ($validated === false) {
            $msg = '<div class="alert alert-danger">Invalid '.$psTitle.' address format.</div>';
          } else {
            $purseVal = (string)$validated;
          }
        }
      }

      if ($msg === '') {
        $exists = $db->query("SELECT id FROM db_purse WHERE uid = ? AND name = ? LIMIT 1", $uid, $psName)->fetchArray();
        if ($exists && !empty($exists['id'])) {
          $db->query("UPDATE db_purse SET purse = ? WHERE id = ?", $purseVal, (int)$exists['id']);
          $msg = '<div class="alert alert-success">Wallet '.$psTitle.' updated.</div>';
        } else {
          $db->query("INSERT INTO db_purse (uid, name, purse) VALUES (?, ?, ?)", $uid, $psName, $purseVal);
          $msg = '<div class="alert alert-success">Wallet '.$psTitle.' saved.</div>';
        }
      }
    }
  }
}

/* -----------------------------
   Update username (POST)
------------------------------*/
if (isset($_POST['new_login'])) {
  $postCsrf = (string)($_POST['csrf'] ?? '');
  $candidate = (string)($_POST['username'] ?? '');
  if (!$csrf_ok($postCsrf)) {
    $msg .= '<div class="alert alert-danger mt-2">Security token invalid. Please try again.</div>';
  } else {
    $new = is_callable([$func, 'Flogin']) ? $func->Flogin($candidate) : false;
    if ($new !== false && !in_array(strtolower($new), ['admin'], true)) {
      $db->query('UPDATE db_users SET login = ? WHERE id = ?', $new, $uid);
      $login = $new;
      $_SESSION['login'] = $new;
      $msg .= '<div class="alert alert-success mt-2">Username has been successfully changed!</div>';
      // Refresh $user for display
      $user = $db->query('SELECT * FROM db_users WHERE id = ? LIMIT 1', $uid)->fetchArray() ?: $user;
    } else {
      $msg .= '<div class="alert alert-warning mt-2">New username has the wrong format.</div>';
    }
  }
}

/* Systems (exclude FaucetPay) */
$paySystems = $db->query("SELECT * FROM db_paysystem WHERE name <> 'FaucetPay' ORDER BY id ASC")->fetchAll();

/* TG profile bits */
$tg_id        = (int)($user['telegram_id']   ?? 0);
$tg_username  =       ($user['tg_username']  ?? $user['username'] ?? '');
$tg_firstname =       ($user['tg_firstname'] ?? '');
$tg_lastname  =       ($user['tg_lastname']  ?? '');
$tg_lang      =       ($user['tg_lang']      ?? '');
$tg_photo     =       ($user['tg_photo_url'] ?? $user['tg_photo'] ?? '');
$tg_is_prem   = (int)($user['tg_is_premium'] ?? 0);
$display_login= (string)($user['login'] ?? $login);

/* Escape helper */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
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
  --vx-hot:#f97316;
  --vx-success:#22c55e;
  --vx-danger:#ef4444;
  --radius:18px;
  --shadow:0 18px 48px rgba(0,0,0,.45);
}

body{ background:var(--vx-bg); color:var(--vx-text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
.vx-shell{ max-width:1120px; margin:18px auto 28px; padding:14px; }
.vx-grid{ display:grid; grid-template-columns:1fr; gap:12px; }
@media(min-width:992px){ .vx-grid{ grid-template-columns:1fr 1fr; } }

.vx-card{
  background:linear-gradient(180deg, rgba(11,16,36,.9), rgba(9,13,28,.97));
  border:1px solid var(--vx-border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.vx-card-hd{ padding:12px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; align-items:center; justify-content:space-between; gap:10px; }
.vx-title{ margin:0; font-weight:1000; font-size:1.08rem; letter-spacing:.01em; }
.vx-sub{ margin:0; color:var(--vx-muted); font-size:.95rem; }
.vx-card-bd{ padding:12px; }
.vx-kicker{ font-weight:900; color:#fff; display:flex; align-items:center; gap:8px; }
.vx-kicker i{ color:var(--vx-accent); }

.wallet-card{
  border:1px solid rgba(255,255,255,.10);
  border-radius:14px;
  background:rgba(15,23,42,.76);
  padding:12px;
}
.wallet-row{ display:flex; align-items:flex-start; gap:12px; }
.wallet-logo{ width:48px; height:48px; border-radius:10px; object-fit:contain; background:#020617; border:1px solid rgba(255,255,255,.08); }
.wallet-name{ font-weight:900; margin:0; }
.wallet-addr{ margin:4px 0 0; word-break:break-all; }
.wallet-addr .mask{ color:#cbd5e1; }
.wallet-actions{ display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.btn-vx{ border:0; border-radius:12px; padding:8px 12px; font-weight:900; }
.btn-vx-primary{ background:linear-gradient(135deg, var(--vx-accent), var(--vx-accent-2)); color:#021015; box-shadow:0 10px 26px rgba(0,255,224,.2); }
.btn-vx-secondary{ background:rgba(15,23,42,.92); color:#e2e8f0; border:1px solid rgba(148,163,184,.30); }
.btn-vx-danger{ background:linear-gradient(180deg, #ef4444, #dc2626); color:#fff; }

.inline-form{ display:grid; grid-template-columns:1fr auto; gap:8px; margin-top:6px; }
.inline-form .form-control{ background:#0b132a; border:1px solid rgba(255,255,255,.14); color:#e6edf7; }
.help{ font-size:.86rem; color:var(--vx-muted); margin-top:4px; }

.tg-card{
  border:1px solid rgba(255,255,255,.10);
  border-radius:14px;
  background:rgba(15,23,42,.76);
  padding:12px;
  display:flex; gap:12px; align-items:center;
}
.tg-avatar{ width:64px; height:64px; border-radius:50%; object-fit:cover; border:1px solid rgba(255,255,255,.12); background:#020617; }
.badge-prem{ display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:6px 10px; background:rgba(88,166,255,.2); border:1px solid rgba(88,166,255,.4); color:#dbeafe; font-weight:900; }

.username-card{
  border:1px solid rgba(255,255,255,.10);
  border-radius:14px;
  background:rgba(15,23,42,.76);
  padding:12px;
}
.notice{ margin-top:6px; font-size:.86rem; color:var(--vx-muted); }

.alert{ border-radius:14px; }
.copy-toast{ position:fixed; bottom:14px; left:50%; transform:translateX(-50%); background:#0b132a; color:#e6edf7; border:1px solid rgba(0,255,224,.45); border-radius:999px; padding:8px 12px; z-index:9999; display:none; }
</style>

<div class="vx-shell">
  <div class="vx-card mb-2">
    <div class="vx-card-hd">
      <div>
        <div class="vx-kicker"><i class="fa-solid fa-gear"></i> Account</div>
        <h1 class="vx-title">Settings & Wallets</h1>
        <p class="vx-sub">Save your payout wallets and keep your Telegram profile in sync.</p>
      </div>
      <span class="badge text-bg-dark border" style="border-color:rgba(255,255,255,.14)!important">UID #<?= (int)$uid; ?></span>
    </div>
    <div class="vx-card-bd">
      <?php if (!empty($msg)) { echo $msg; } ?>

      <div class="vx-grid">
        <!-- LEFT: Wallets manager -->
        <section class="vx-card">
          <div class="vx-card-hd">
            <div class="vx-kicker"><i class="fa-solid fa-wallet"></i> Wallets</div>
          </div>
          <div class="vx-card-bd">
            <?php
            if (empty($paySystems)) {
              echo '<div class="alert alert-info">No payment systems configured yet.</div>';
            } else {
              foreach ($paySystems as $ps) {
                $psName   = (string)$ps['name'];
                $psTitle  = (string)($ps['title'] ?? $psName);
                $psValIc  = strtolower((string)($ps['currency'] ?? 'usd'));

                $row = $db->query("SELECT purse FROM db_purse WHERE uid = ? AND name = ? LIMIT 1", $uid, $psName)->fetchArray();
                $userWallet = (string)($row['purse'] ?? '');

                $masked = $userWallet;
                if ($userWallet !== '' && mb_strlen($userWallet) > 12) {
                  $masked = h(mb_substr($userWallet, 0, 6)) .
                            "<span style='color:#ffb02e;'>…</span>" .
                            h(mb_substr($userWallet, -6));
                } else {
                  $masked = h($userWallet);
                }
                ?>
                <div class="wallet-card mb-2" data-ps="<?= h($psName); ?>">
                  <div class="wallet-row">
                    <img loading="lazy" decoding="async" class="wallet-logo" src="/img/pay/icon/<?= h($psValIc); ?>.png" alt="<?= h($psTitle); ?>">
                    <div class="flex-grow-1">
                      <h6 class="wallet-name"><?= h($psTitle); ?></h6>

                      <?php if ($userWallet !== ''): ?>
                        <div class="wallet-addr notranslate">
                          <span class="mask" data-full="<?= h($userWallet); ?>"><?= $masked; ?></span>
                        </div>
                        <div class="wallet-actions">
                          <button class="btn-vx btn-vx-primary" data-copy=".wallet-card[data-ps='<?= h($psName); ?>'] .mask"><i class="fa fa-copy"></i> Copy</button>
                          <button class="btn-vx btn-vx-secondary" data-reveal=".wallet-card[data-ps='<?= h($psName); ?>'] .mask"><i class="fa fa-eye"></i> Reveal</button>
                          <button class="btn-vx btn-vx-secondary" data-edit="#edit-<?= h($psName); ?>"><i class="fa fa-pen"></i> Edit</button>
                        </div>

                        <form id="edit-<?= h($psName); ?>" class="inline-form" method="post" action="" hidden>
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']); ?>">
                          <input type="hidden" name="name" value="<?= h($psName); ?>">
                          <input class="form-control notranslate" type="text" name="purse" placeholder="New <?= h($psTitle); ?> address" value="<?= h($userWallet); ?>" autocomplete="off">
                          <div class="d-flex gap-2">
                            <button class="btn-vx btn-vx-primary" name="save_wallet" type="submit"><i class="fa fa-check"></i></button>
                            <button class="btn-vx btn-vx-danger" type="button" data-cancel="#edit-<?= h($psName); ?>"><i class="fa fa-xmark"></i></button>
                          </div>
                          <div class="help">Paste the exact address for <?= h($psTitle); ?>. Format is validated when possible.</div>
                        </form>
                      <?php else: ?>
                        <form class="inline-form" method="post" action="">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']); ?>">
                          <input type="hidden" name="name" value="<?= h($psName); ?>">
                          <input class="form-control notranslate" type="text" name="purse" placeholder="Wallet <?= h($psTitle); ?>" autocomplete="off">
                          <button class="btn-vx btn-vx-primary" name="save_wallet" type="submit"><i class="fa fa-save"></i> Save</button>
                          <div class="help">We’ll store this payout wallet to use in withdrawals.</div>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php
              }
            }
            ?>
          </div>
        </section>

        <!-- RIGHT: Telegram + Username -->
        <section class="vx-card">
          <div class="vx-card-hd">
            <div class="vx-kicker"><i class="fa-brands fa-telegram"></i> Telegram Profile</div>
          </div>
          <div class="vx-card-bd">
            <div class="tg-card mb-2">
              <img loading="lazy" decoding="async" class="tg-avatar" src="<?= $tg_photo ? h($tg_photo) : '/img/tg.png'; ?>" alt="Avatar">
              <div>
                <div><b>ID:</b> <?= $tg_id ?: '<span class="text-muted">—</span>'; ?></div>
                <div><b>Username:</b> <?= $tg_username ? '@'.h($tg_username) : '<span class="text-muted">—</span>'; ?></div>
                <div><b>Name:</b> <?= h(trim(($tg_firstname.' '.$tg_lastname) ?: '—')); ?></div>
                <div><b>Language:</b> <?= h($tg_lang ?: '—'); ?></div>
                <?php if ($tg_is_prem): ?>
                  <div class="mt-1"><span class="badge-prem"><i class="fa-solid fa-star"></i> Telegram Premium</span></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="username-card">
              <div class="vx-kicker mb-2"><i class="fa-solid fa-user"></i> Update Username</div>
              <form action="" method="post" class="m-0">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']); ?>">
                <div class="input-group">
                  <input type="text" class="form-control" name="username" placeholder="Enter new username"
                         value="<?= h($display_login); ?>" autocomplete="off" maxlength="32">
                  <button class="btn btn-outline-light" name="new_login" type="submit"><i class="fa fa-save"></i> Save</button>
                </div>
                <div class="notice">3–32 chars. Allowed: A-Z, a-z, 0-9, _ and -</div>
              </form>
            </div>
          </div>
        </section>
      </div>

    </div>
  </div>
</div>

<div class="copy-toast" id="copyToast">Copied</div>

<script>
(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  function toast(msg){
    var el = document.getElementById('copyToast');
    if(!el) { alert(msg); return; }
    el.textContent = msg || 'Copied';
    el.style.display = 'block';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display = 'none'; }, 900);
  }

  function copyText(txt){
    if (!txt) return;
    if (navigator.clipboard && window.isSecureContext){
      navigator.clipboard.writeText(txt).then(()=>toast('Copied')).catch(()=>fallback(txt));
    } else { fallback(txt); }
    function fallback(val){
      try{
        const ta = document.createElement('textarea');
        ta.value = val; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        toast('Copied');
      }catch(e){ alert('Copy failed — select and copy manually.'); }
    }
  }

  ready(function(){
    // Copy, Reveal, Edit toggles
    document.addEventListener('click', function(e){
      // Copy
      var cp = e.target.closest('[data-copy]');
      if (cp){
        e.preventDefault();
        try{
          var sel = cp.getAttribute('data-copy');
          var holder = document.querySelector(sel);
          if (!holder) return;
          var full = holder.getAttribute('data-full') || holder.textContent || '';
          copyText(full.trim());
        }catch(_){}
        return;
      }

      // Reveal
      var rv = e.target.closest('[data-reveal]');
      if (rv){
        e.preventDefault();
        var sel = rv.getAttribute('data-reveal');
        var el = document.querySelector(sel);
        if (!el) return;
        var full = el.getAttribute('data-full') || '';
        if (full) el.textContent = full;
        rv.setAttribute('disabled','disabled');
        return;
      }

      // Show edit
      var ed = e.target.closest('[data-edit]');
      if (ed){
        e.preventDefault();
        var fsel = ed.getAttribute('data-edit');
        var form = document.querySelector(fsel);
        if (form){ form.hidden = false; var inp = form.querySelector('input[name="purse"]'); if (inp) inp.focus(); }
        return;
      }

      // Cancel inline edit
      var cn = e.target.closest('[data-cancel]');
      if (cn){
        e.preventDefault();
        var fsel = cn.getAttribute('data-cancel');
        var form = document.querySelector(fsel);
        if (form){ form.hidden = true; }
        return;
      }
    });

    // Progressive enhancement: AJAX wallet save (keeps normal POST as fallback)
    document.querySelectorAll('form.inline-form').forEach(function(form){
      form.addEventListener('submit', function(ev){
        // If this is a POST WITHOUT save_wallet, ignore (it has Save button name)
        var trigger = ev.submitter || {};
        if (!trigger.name || trigger.name !== 'save_wallet') return; // allow native
        ev.preventDefault();

        var fd = new FormData(form);
        // Support endpoint if you have one; else post to current
        fetch('/api/user/settings_save_wallet.php', {
          method:'POST', credentials:'include', body: fd
        }).then(function(r){ return r.json().catch(function(){ return {ok:false,msg:'Non-JSON response'}; }); })
          .then(function(j){
            toast(j.msg || (j.ok?'Saved':'Error'));
            if (j.ok) { setTimeout(function(){ location.reload(); }, 600); }
          })
          .catch(function(){ toast('Network error'); });
      }, {capture:true});
    });
  });
})();
</script>
