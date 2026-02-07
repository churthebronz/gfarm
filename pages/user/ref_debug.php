<?php
if (!defined('FastCore')) { define('FastCore', true); }
require_once __DIR__ . '/../../core/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$opt['title'] = 'Referral Debug';

// Fetch current user (for fallback rendering)
$u = [];
try {
  $q = $db->query('SELECT id, login, rid, ref_code, ref_start_param FROM db_users WHERE id=? LIMIT 1', $uid);
  $u = $q ? ($q->fetchArray() ?: []) : [];
} catch (Throwable $e) {}

?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/modern-normalize/2.0.0/modern-normalize.min.css" rel="stylesheet" />
<style>
:root{--vx-bg:#060816; --vx-surface:#0b1024; --vx-border:rgba(255,255,255,.10); --vx-text:#eaf0ff; --vx-muted:#a8b2d1; --vx-accent:#00ffe0;}
body{background:radial-gradient(1200px 700px at 110% -10%, rgba(0,255,224,.10), transparent 45%) #060816;color:var(--vx-text);}
.vx-wrap{max-width:980px;margin:0 auto;padding:14px;}
.vx-card{background:rgba(255,255,255,.03);border:1px solid var(--vx-border);border-radius:16px;padding:16px;}
.vx-grid{display:grid;gap:12px;grid-template-columns:1fr;} @media(min-width:900px){.vx-grid{grid-template-columns:1.2fr .8fr}}
.vx-row{display:flex;justify-content:space-between;gap:10px;border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.02)}
.vx-row .k{color:var(--vx-muted)}
.vx-row .v{font-weight:800;word-break:break-all;text-align:right}
.vx-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;text-decoration:none;color:var(--vx-text);background:rgba(255,255,255,.03);cursor:pointer}
.vx-btn:active{transform:translateY(1px)}
.vx-btn.primary{border-color:rgba(0,255,224,.35)}
.vx-in{width:100%;border:1px solid var(--vx-border);border-radius:12px;padding:10px 12px;background:rgba(0,0,0,.25);color:var(--vx-text)}
.vx-mini{color:var(--vx-muted);font-size:.92rem}
</style>

<div class="vx-wrap">
  <div class="vx-card" style="margin-bottom:12px;">
    <h2 style="margin:0 0 6px 0;">Referral Debug</h2>
    <div class="vx-mini">Use this page inside the Telegram Mini App to confirm your referral payload is being captured (no need to type URLs in Telegram).</div>
  </div>

  <div class="vx-grid">
    <div class="vx-card">
      <h3 style="margin:0 0 10px 0;">Current values</h3>
      <div id="vxRows">
        <div class="vx-row"><div class="k">UID</div><div class="v"><?= (int)($u['id'] ?? $uid) ?></div></div>
        <div class="vx-row"><div class="k">Login</div><div class="v"><?= h((string)($u['login'] ?? '')) ?></div></div>
        <div class="vx-row"><div class="k">RID (referrer uid)</div><div class="v"><?= (int)($u['rid'] ?? 0) ?></div></div>
        <div class="vx-row"><div class="k">Your ref_code</div><div class="v"><?= h((string)($u['ref_code'] ?? '')) ?></div></div>
        <div class="vx-row"><div class="k">DB start_param</div><div class="v"><?= h((string)($u['ref_start_param'] ?? '')) ?></div></div>
        <div class="vx-row"><div class="k">Cookie vx_start_param</div><div class="v"><?= h((string)($_COOKIE['vx_start_param'] ?? '')) ?></div></div>
        <div class="vx-row"><div class="k">Session vx_start_param</div><div class="v"><?= h((string)($_SESSION['vx_start_param'] ?? '')) ?></div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
        <button class="vx-btn" type="button" id="vxRefresh">Refresh</button>
        <button class="vx-btn primary" type="button" id="vxCopyBot">Copy bot referral test link</button>
      </div>
      <div class="vx-mini" style="margin-top:8px;">Tip: share the copied link to yourself in Telegram, tap it, press Start, then open the Mini App. Come back here and hit Refresh.</div>
    </div>

    <div class="vx-card">
      <h3 style="margin:0 0 10px 0;">Simulate start_param (for testing)</h3>
      <div class="vx-mini" style="margin-bottom:8px;">If Telegram won't pass a payload during testing, you can set one manually. This calls <code>/api/start_param.php</code> and stores the value in cookie+session.</div>
      <input class="vx-in" id="vxSim" placeholder="e.g. ref_TEST123 or TEST123" />
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
        <button class="vx-btn primary" type="button" id="vxSet">Set start_param</button>
        <button class="vx-btn" type="button" id="vxClear">Clear</button>
      </div>
      <div class="vx-mini" style="margin-top:10px;" id="vxMsg"></div>
    </div>
  </div>
</div>

<script>
(function(){
  function toast(t){
    var el = document.getElementById('vxMsg');
    if (el) el.textContent = t || '';
  }
  async function refresh(){
    try{
      const r = await fetch('/api/user/ref_debug.php', {credentials:'include'});
      const j = await r.json();
      if (!j || !j.ok) { toast('Not authenticated / API error'); return; }
      const rows = [
        ['UID', j.uid],
        ['Login', j.login],
        ['RID (referrer uid)', j.rid],
        ['Your ref_code', j.ref_code],
        ['DB start_param', j.ref_start_param_db],
        ['Cookie vx_start_param', j.vx_start_param_cookie],
        ['Session vx_start_param', j.vx_start_param_session],
      ];
      const wrap = document.getElementById('vxRows');
      if (wrap){
        wrap.innerHTML = rows.map(function(r){
          return '<div class="vx-row"><div class="k">'+String(r[0])+'</div><div class="v">'+String(r[1]??'')+'</div></div>';
        }).join('');
      }
      window.__vxBotLink = j.bot_ref_test_link || '';
      toast('Updated.');
    }catch(e){ toast('Network error'); }
  }
  async function setParam(v){
    v = (v||'').trim();
    if (!v) { toast('Enter a value first.'); return; }
    try{
      await fetch('/api/start_param.php?ref=' + encodeURIComponent(v), {credentials:'include'});
      toast('Set. Now press Refresh.');
    }catch(e){ toast('Network error'); }
  }
  async function clearParam(){
    try{
      await fetch('/api/start_param.php?ref=', {credentials:'include'});
      toast('Cleared. Now press Refresh.');
    }catch(e){ toast('Network error'); }
  }
  document.getElementById('vxRefresh')?.addEventListener('click', refresh);
  document.getElementById('vxSet')?.addEventListener('click', function(){ setParam(document.getElementById('vxSim')?.value); });
  document.getElementById('vxClear')?.addEventListener('click', clearParam);
  document.getElementById('vxCopyBot')?.addEventListener('click', async function(){
    try{
      if (!window.__vxBotLink) await refresh();
      const link = window.__vxBotLink || '';
      if (!link){ toast('No ref_code yet.'); return; }
      await navigator.clipboard.writeText(link);
      toast('Copied: ' + link);
      // TG haptics if available
      try{ if (window.Telegram && Telegram.WebApp){ Telegram.WebApp.HapticFeedback.impactOccurred('light'); } }catch(e){}
    }catch(e){ toast('Copy failed'); }
  });
  refresh();
})();
</script>
