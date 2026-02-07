(function(){
  const list = document.getElementById('vxRecentList');
  const empty = document.getElementById('vxRecentEmpty');
  const btn = document.getElementById('vxRecentRefresh');
  if(!list || !empty) return;

  function ago(ts){
    const s = Math.max(0, Math.floor(Date.now()/1000 - ts));
    if(s < 10) return 'just now';
    if(s < 60) return s+'s ago';
    const m = Math.floor(s/60);
    if(m < 60) return m+'m ago';
    const h = Math.floor(m/60);
    if(h < 48) return h+'h ago';
    const d = Math.floor(h/24);
    return d+'d ago';
  }

  function iconFor(t){
    t = String(t||'').toLowerCase();
    if(t==='claim') return '✅';
    if(t==='claim_streak') return '🔥';
    if(t==='vault_buy') return '🔒';
    if(t==='crossbreed') return '🔁';
    if(t==='aff_milestone') return '🏆';
    if(t==='affiliate_claim') return '⭐';
    return '⚡';
  }

  function titleFor(row){
    const t = String(row.type||'').toLowerCase();
    if(t==='claim') return `Yield collected (+$${Number(row.amount||0).toFixed(2)})`;
    if(t==='claim_streak') return `Claim streak updated (Day ${Math.max(1, Math.round(row.amount||0))})`;
    if(t==='vault_buy') return `Seed planted`;
    if(t==='crossbreed') return `Crossbreed completed`;
    if(t==='aff_milestone') return `Referral milestone unlocked (${Math.round(row.amount||0)})`;
    if(t==='affiliate_claim') return `Affiliate Guardian claimed`;
    return `Activity`;
  }

  function subtitleFor(row){
    const t = String(row.type||'').toLowerCase();
    const m = row.meta||{};
    if(t==='aff_milestone') return 'Keep pushing — more perks unlock at higher tiers.';
    if(t==='claim_streak') return 'Claim daily to keep your streak alive.';
    if(t==='claim') return 'Your bank balance updated.';
    if(t==='vault_buy' && m && m.title) return String(m.title);
    return '';
  }

  function render(items){
    list.innerHTML = '';
    if(!items || !items.length){
      empty.textContent = 'No activity yet — once you claim yield or unlock milestones, it will show up here.';
      return;
    }
    empty.textContent = '';
    items.slice(0, 20).forEach(row=>{
      const wrap = document.createElement('div');
      wrap.className = 'vx-recent-item';
      wrap.innerHTML = `
        <div class="vx-recent-emo">${iconFor(row.type)}</div>
        <div class="vx-recent-main">
          <div class="vx-recent-line">${titleFor(row)}</div>
          ${subtitleFor(row) ? `<div class="vx-recent-sub">${subtitleFor(row)}</div>`:''}
          <div class="vx-recent-time">${ago(Number(row.ts||0))}</div>
        </div>
      `;
      list.appendChild(wrap);
    });
  }

  async function load(){
    try{
      empty.textContent = 'Loading…';
      const r = await fetch('/api/user/activity_feed.php?limit=20', {credentials:'same-origin'});
      const j = await r.json();
      if(!j || !j.ok) throw new Error('bad');
      render(j.items||[]);
    }catch(e){
      empty.textContent = 'Could not load activity.';
      list.innerHTML = '';
    }
  }

  if(btn){ btn.addEventListener('click', load); }
  load();
})();
