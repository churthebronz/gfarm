<?php
// pages/user/inbox.php (GreenFarm • Notifications Center)
declare(strict_types=1);

if (!defined('FastCore')) { exit('Opss!'); }

global $db, $uid, $user;

require_once __DIR__ . '/../../core/vx_retention.php';

// Retention tick also processes queued notifications (vault finished, season ending, referral qualification, etc.)
try { vx_retention_tick($db, (int)$uid); } catch (Throwable $e) {}

$opt['title'] = 'Inbox';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

?>

<div class="vx-inbox" id="vxInbox">
  <div class="vx-inbox-hero">
    <div>
      <div class="vx-inbox-title">Inbox</div>
      <div class="vx-inbox-sub">Rewards, referrals, tier unlocks, crossbreed alerts, season endings — all in one place.</div>
    </div>
    <div class="vx-inbox-actions">
      <button class="vx-btn" id="vxMarkAll"><i class="fa-regular fa-circle-check"></i> Mark all read</button>
      <button class="vx-btn" id="vxRefresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
    </div>
  </div>

  <div class="vx-inbox-list" id="vxInboxList">
    <div class="vx-inbox-empty">Loading…</div>
  </div>
</div>

<style>
.vx-inbox{max-width:980px;margin:0 auto;padding:14px 12px 84px}
.vx-inbox-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:6px 0 14px;padding:14px;border:1px solid rgba(255,255,255,.10);border-radius:18px;background:linear-gradient(180deg,rgba(15,23,42,.55),rgba(15,23,42,.35));box-shadow:0 18px 60px rgba(0,0,0,.25)}
.vx-inbox-title{font-size:22px;font-weight:900;letter-spacing:.02em}
.vx-inbox-sub{opacity:.75;margin-top:4px;line-height:1.35}
.vx-inbox-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.vx-btn{border:1px solid rgba(255,255,255,.12);background:rgba(2,6,23,.35);color:#eaf0ff;padding:10px 12px;border-radius:14px;font-weight:850;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.vx-btn:hover{background:rgba(2,6,23,.55)}

.vx-inbox-list{display:grid;gap:10px}
.vx-inbox-card{border:1px solid rgba(255,255,255,.10);border-radius:16px;padding:12px 12px 10px;background:rgba(15,23,42,.40);cursor:pointer;position:relative;overflow:hidden}
.vx-inbox-card.unread{background:linear-gradient(180deg,rgba(0,243,255,.08),rgba(15,23,42,.42));border-color:rgba(0,243,255,.16)}
.vx-inbox-card:hover{transform:translateY(-1px);transition:transform .12s ease}
.vx-inbox-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.vx-inbox-h{display:flex;gap:10px;align-items:flex-start}
.vx-dot{width:10px;height:10px;border-radius:50%;margin-top:4px;flex:0 0 auto}
.vx-dot.info{background:#38bdf8;box-shadow:0 0 14px rgba(56,189,248,.45)}
.vx-dot.success{background:#22c55e;box-shadow:0 0 14px rgba(34,197,94,.45)}
.vx-dot.warning{background:#f59e0b;box-shadow:0 0 14px rgba(245,158,11,.45)}
.vx-dot.danger{background:#ef4444;box-shadow:0 0 14px rgba(239,68,68,.45)}
.vx-inbox-title2{font-weight:950;font-size:15px;line-height:1.2}
.vx-inbox-msg{opacity:.82;margin-top:4px;line-height:1.35}
.vx-meta{display:flex;gap:10px;flex-wrap:wrap;opacity:.62;margin-top:8px;font-size:12px}
.vx-pill{border:1px solid rgba(255,255,255,.12);padding:4px 8px;border-radius:999px;background:rgba(2,6,23,.25)}
.vx-mark{border:none;background:rgba(255,255,255,.08);color:#eaf0ff;padding:7px 10px;border-radius:12px;font-weight:850;cursor:pointer;white-space:nowrap}
.vx-mark:hover{background:rgba(255,255,255,.12)}
.vx-inbox-empty{border:1px dashed rgba(255,255,255,.16);border-radius:16px;padding:16px;opacity:.72;text-align:center}

@media (max-width:720px){
  .vx-inbox-hero{flex-direction:column;align-items:stretch}
  .vx-inbox-actions{justify-content:stretch}
  .vx-btn{justify-content:center}
}
</style>

<script>
(function(){
  const listEl = document.getElementById('vxInboxList');
  const btnAll = document.getElementById('vxMarkAll');
  const btnRef = document.getElementById('vxRefresh');

  function timeAgo(ts){
    if(!ts) return '';
    const s = Math.max(0, Math.floor(Date.now()/1000) - ts);
    if(s < 60) return s + 's ago';
    const m = Math.floor(s/60); if(m < 60) return m + 'm ago';
    const h = Math.floor(m/60); if(h < 24) return h + 'h ago';
    const d = Math.floor(h/24); return d + 'd ago';
  }

  function inferCta(item){
    try {
      const data = item.data_json ? JSON.parse(item.data_json) : null;
      if(data && data.cta) return String(data.cta);
    } catch(e) {}
    const t = String(item.type||'');
    if(t.includes('ref') || t.includes('invite')) return '/user/affiliate';
    if(t.includes('crossbreed') || t.includes('guardian')) return '/user/rewards';
    if(t.includes('season')) return '/user/leaderboards';
    if(t.includes('vault')) return '/user/rewards';
    if(t.includes('points')) return '/user/points';
    return '/user/dashboard';
  }

  async function fetchItems(){
    listEl.innerHTML = '<div class="vx-inbox-empty">Loading…</div>';
    try{
      const res = await fetch('/api/user/notifications_feed.php?limit=50', {credentials:'same-origin'});
      const j = await res.json();
      if(!j || !j.ok){ throw new Error('bad'); }
      const items = j.items || [];
      if(!items.length){
        listEl.innerHTML = '<div class="vx-inbox-empty">No notifications yet. Keep earning and inviting — your Inbox will fill up.</div>';
        return;
      }
      listEl.innerHTML = '';
      items.forEach(item => {
        const lvl = String(item.level||'info');
        const unread = (parseInt(item.is_read,10) === 0);
        const card = document.createElement('div');
        card.className = 'vx-inbox-card' + (unread ? ' unread' : '');

        const metaBits = [];
        if(unread) metaBits.push('<span class="vx-pill">Unread</span>');
        metaBits.push('<span class="vx-pill">' + timeAgo(parseInt(item.ts,10)) + '</span>');
        if(item.type) metaBits.push('<span class="vx-pill">' + String(item.type).replace(/_/g,' ') + '</span>');

        card.innerHTML = `
          <div class="vx-inbox-top">
            <div class="vx-inbox-h">
              <span class="vx-dot ${lvl}"></span>
              <div>
                <div class="vx-inbox-title2">${escapeHtml(item.title||'Notification')}</div>
                <div class="vx-inbox-msg">${escapeHtml(item.message||'')}</div>
                <div class="vx-meta">${metaBits.join('')}</div>
              </div>
            </div>
            <button class="vx-mark" data-id="${item.id}">Mark</button>
          </div>
        `;

        // Mark button
        card.querySelector('.vx-mark').addEventListener('click', async (e) => {
          e.stopPropagation();
          await markOne(parseInt(item.id,10));
          await fetchItems();
          try{ window.vxUpdateBell && window.vxUpdateBell(); }catch(e){}
        });

        // Tap to deep-link
        card.addEventListener('click', async () => {
          if(unread){ await markOne(parseInt(item.id,10)); }
          const cta = inferCta(item);
          window.location.href = cta;
        });

        listEl.appendChild(card);
      });

      try{ window.vxUpdateBell && window.vxUpdateBell(); }catch(e){}
    }catch(e){
      listEl.innerHTML = '<div class="vx-inbox-empty">Could not load notifications. Please refresh.</div>';
    }
  }

  async function markOne(id){
    if(!id) return;
    try{
      await fetch('/api/user/notifications_mark.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'id=' + encodeURIComponent(String(id))
      });
    }catch(e){}
  }

  async function markAll(){
    try{
      await fetch('/api/user/notifications_mark.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'all=1'
      });
    }catch(e){}
  }

  function escapeHtml(str){
    return String(str||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  btnAll.addEventListener('click', async () => {
    await markAll();
    await fetchItems();
    try{ window.vxUpdateBell && window.vxUpdateBell(); }catch(e){}
  });
  btnRef.addEventListener('click', fetchItems);

  fetchItems();
})();
</script>
