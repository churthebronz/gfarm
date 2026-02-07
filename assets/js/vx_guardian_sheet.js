/* Guardian Detail Sheet (TG mini-app feel)
 * Opens from any Guardian card and pulls data from:
 *   /api/user/guardian_detail.php?tarif=ID
 */
(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  let sharePreset = 'holo';

  function isTG(){
    try { return !!(window.Telegram && Telegram.WebApp); } catch(e) { return false; }
  }

  function haptic(kind){
    try{
      if (!isTG() || !Telegram.WebApp.HapticFeedback) return;
      const h = Telegram.WebApp.HapticFeedback;
      if (kind==='light') h.impactOccurred('light');
      else if (kind==='medium') h.impactOccurred('medium');
      else if (kind==='success') h.notificationOccurred('success');
      else if (kind==='error') h.notificationOccurred('error');
    }catch(e){}
  }

  function lockScroll(on){
    try{
      if (on){
        document.documentElement.classList.add('vx-lock');
        document.body.classList.add('vx-lock');
      } else {
        document.documentElement.classList.remove('vx-lock');
        document.body.classList.remove('vx-lock');
      }
    }catch(e){}
  }

  function ensureHost(){
    let host = document.getElementById('vxGuardianSheet');
    if (host) return host;

    host = document.createElement('div');
    host.id = 'vxGuardianSheet';
    host.innerHTML = `
      <div class="vx-gsheet-bd" data-vx-gsheet-close></div>
      <div class="vx-gsheet" role="dialog" aria-modal="true" aria-label="Guardian details">
        <div class="vx-gsheet-top">
          <div class="vx-gsheet-title">
            <div class="t" id="vxGSheetName">Guardian</div>
            <div class="s" id="vxGSheetSub">Loading…</div>
          </div>
          <button class="vx-gsheet-x" type="button" aria-label="Close" data-vx-gsheet-close>✕</button>
        </div>

        <div class="vx-gsheet-body" id="vxGSheetBody">
          <div class="vx-gsheet-hero skel" id="vxGSheetHero"></div>
          <div class="vx-gsheet-row" style="margin-top:12px;">
            <div class="vx-gsheet-stars" id="vxGSheetStars"></div>
            <div class="vx-gsheet-badges" id="vxGSheetBadges"></div>
          </div>

          <div class="vx-gsheet-panel" id="vxGSheetPanel"></div>
          <div class="vx-gsheet-stats" id="vxGSheetStats"></div>
          <div class="vx-gsheet-journal" id="vxGSheetJournal"></div>
          <div class="vx-gsheet-timeline" id="vxGSheetTimeline"></div>
        </div>

        <div class="vx-gsheet-actions" id="vxGSheetActions"></div>
      </div>
    `;
    document.body.appendChild(host);

    host.addEventListener('click', (e)=>{
      const t = e.target;
      if (t && t.closest('[data-vx-gsheet-close]')) close();
    });

    // ESC close
    window.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape' && host.classList.contains('is-open')) close();
    });

    // Basic swipe down to close (mobile)
    let startY = 0, dy = 0, tracking = false;
    const sheet = host.querySelector('.vx-gsheet');
    if (sheet){
      sheet.addEventListener('touchstart', (e)=>{
        if (!host.classList.contains('is-open')) return;
        if (e.touches && e.touches[0]){
          startY = e.touches[0].clientY;
          dy = 0;
          tracking = (sheet.scrollTop <= 0);
        }
      }, {passive:true});
      sheet.addEventListener('touchmove', (e)=>{
        if (!tracking) return;
        if (e.touches && e.touches[0]) dy = e.touches[0].clientY - startY;
      }, {passive:true});
      sheet.addEventListener('touchend', ()=>{
        if (!tracking) return;
        if (dy > 90) close();
        tracking = false;
      }, {passive:true});
    }

    return host;
  }

  function open(){
    const host = ensureHost();
    host.classList.add('is-open');
    lockScroll(true);
    try{
      if (isTG() && Telegram.WebApp && Telegram.WebApp.HapticFeedback) haptic('light');
    }catch(e){}
  }

  function close(){
    const host = document.getElementById('vxGuardianSheet');
    if (!host) return;
    host.classList.remove('is-open');
    lockScroll(false);
  }

  function fmtTime(ts){
    ts = Number(ts||0);
    if (!ts) return '—';
    try{
      const d = new Date(ts*1000);
      return d.toLocaleString(undefined, {year:'numeric', month:'short', day:'numeric'});
    }catch(e){
      return '—';
    }
  }

  function buildStars(n, max, isMax){
    n = Math.max(0, Math.min(6, Number(n||0)));
    max = Math.max(1, Math.min(6, Number(max||5)));
    let out = '';
    for (let i=1;i<=max;i++){
      out += `<span class="st ${i<=n?'on':''} ${isMax && i<=n?'mx':''}">★</span>`;
    }
    return out;
  }

  
  async function renderShareCard(j, lvl, maxE, sharePreset){
    // Client-side export (no server deps)
    const W = 1080, H = 1350;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('NO_CTX');

    // Background
    const g = ctx.createLinearGradient(0,0,W,H);
    g.addColorStop(0, '#060816');
    g.addColorStop(1, '#0b0f1a');
    ctx.fillStyle = g;
    ctx.fillRect(0,0,W,H);

    // Card shell
    const pad = 64;
    const rx = 44;
    const x = pad, y = pad, w = W - pad*2, h = H - pad*2;

    function roundRect(px,py,pw,ph,pr){
      ctx.beginPath();
      ctx.moveTo(px+pr, py);
      ctx.arcTo(px+pw, py, px+pw, py+ph, pr);
      ctx.arcTo(px+pw, py+ph, px, py+ph, pr);
      ctx.arcTo(px, py+ph, px, py, pr);
      ctx.arcTo(px, py, px+pw, py, pr);
      ctx.closePath();
    }

    // Glow stroke
    ctx.save();
    ctx.shadowColor = 'rgba(0,243,255,.35)';
    ctx.shadowBlur = 30;
    ctx.strokeStyle = 'rgba(0,243,255,.20)';
    ctx.lineWidth = 10;
    roundRect(x,y,w,h,rx);
    ctx.stroke();
    ctx.restore();

    // Surface
    ctx.fillStyle = 'rgba(12,18,28,.86)';
    roundRect(x,y,w,h,rx);
    ctx.fill();

    // Image block
    const imgH = Math.floor(h*0.62);
    const imgX = x+18, imgY = y+18, imgW = w-36;
    ctx.save();
    roundRect(imgX,imgY,imgW,imgH,34);
    ctx.clip();

    const img = await new Promise((resolve,reject)=>{
      const im = new Image();
      im.crossOrigin = 'anonymous';
      im.onload = ()=>resolve(im);
      im.onerror = ()=>reject(new Error('IMG_LOAD'));
      im.src = String(j.art_url||'');
    });

    // cover draw
    const s = Math.max(imgW/img.width, imgH/img.height);
    const dw = img.width*s, dh = img.height*s;
    const dx = imgX + (imgW - dw)/2;
    const dy = imgY + (imgH - dh)/2;
    ctx.drawImage(img, dx, dy, dw, dh);

    // subtle vignette
    const vg = ctx.createLinearGradient(0,imgY,0,imgY+imgH);
    vg.addColorStop(0,'rgba(0,0,0,0.05)');
    vg.addColorStop(1,'rgba(0,0,0,0.55)');
    ctx.fillStyle = vg;
    ctx.fillRect(imgX,imgY,imgW,imgH);

    ctx.restore();

    // Title
    ctx.fillStyle = 'rgba(234,240,255,.98)';
    ctx.font = '800 54px system-ui, -apple-system, Segoe UI, Roboto, Arial';
    const title = String(j.title||'Guardian');
    ctx.fillText(title, x+44, imgY+imgH+86);

    // Stars
    const stars = Math.max(1, Math.min(6, Number(j.stars||1)));
    ctx.font = '800 44px system-ui, -apple-system, Segoe UI, Roboto, Arial';
    ctx.fillStyle = 'rgba(255,204,102,.95)';
    ctx.fillText('★'.repeat(stars), x+44, imgY+imgH+144);

    // Meta line
    ctx.font = '700 34px system-ui, -apple-system, Segoe UI, Roboto, Arial';
    ctx.fillStyle = 'rgba(226,232,240,.78)';
    const meta = `Evolve ${lvl}/${maxE}  •  Affinity ${Number(j.affinity_level||0)}  •  ${String((j.rarity||'common')).toUpperCase()}`;
    ctx.fillText(meta, x+44, imgY+imgH+198);

    // Footer
    ctx.font = '700 30px system-ui, -apple-system, Segoe UI, Roboto, Arial';
    ctx.fillStyle = 'rgba(167,179,214,.78)';
    ctx.fillText('greenfarm.lol', x+44, y+h-54);

    return canvas.toDataURL('image/png');
  }

function buildAction(label, href, primary){
    const cls = 'vx-gsheet-btn' + (primary ? ' primary' : '');
    if (!href) return '';
    return `<a class="${cls}" href="${href}">${label}</a>`;
  }



      function buildTimelineHTML(j, lvl, maxE){
        const now = Math.floor(Date.now()/1000);
        const status = String(j.status||'');
        const created = Number(j.created_at||0);
        const termEnd = Number(j.term_end||0);
        const deadline = Number(j.crossbreed_deadline||0);

        const steps = [];
        steps.push({k:'Acquired', v: created?('Collected on ' + fmtTime(created)):'Collected', cls: created?'on':''});
        // Evolve ladder
        for (let i=1;i<=maxE;i++){
          const on = (lvl >= i);
          steps.push({k:'E'+i+' Evolution', v: on ? ('Unlocked (stage ' + i + ')') : 'Locked', cls: on?'on':''});
        }
        if (termEnd){
          const isPast = (now >= termEnd);
          steps.push({k:'Maturity', v: isPast ? ('Matured ' + fmtTime(termEnd)) : ('Matures ' + fmtTime(termEnd)), cls: isPast?'on':''});
        }
        if (deadline){
          const left = deadline - now;
          const cls = (left <= 0) ? 'dead' : (left <= 6*3600 ? 'warn' : '');
          const label = (left <= 0) ? ('Crossbreed window ended ' + fmtTime(deadline)) : ('Crossbreed window ends ' + fmtTime(deadline));
          steps.push({k:'Crossbreed Window', v: label, cls});
        }
        if (status === 'archived' || status === 'collection'){
          steps.push({k:'Archived', v:'Moved to collection mode', cls:'on'});
        }

        return `
          <div class="hd">
            <div>
              <div class="t">Timeline</div>
              <div class="s">Acquired → E1..E${maxE} → Archived</div>
            </div>
            <div class="s">${String(j.rarity_label||'').toUpperCase()}</div>
          </div>
          <div class="bd">
            <div class="vx-gsheet-tline">
              ${steps.map(st=>`<div class="vx-gsheet-step ${st.cls||''}"><div class="dot"></div><div class="meta"><div class="k">${st.k}</div><div class="v">${st.v}</div></div></div>`).join('')}
            </div>
          </div>`;
      }

  async function load(tarif){
    const host = ensureHost();
    const nameEl = host.querySelector('#vxGSheetName');
    const subEl  = host.querySelector('#vxGSheetSub');
    const nickWrap = host.querySelector('#vxGSheetNickWrap');
    const nickEl = host.querySelector('#vxGSheetNick');
    const nickBtn = host.querySelector('#vxGSheetNickBtn');
    const presetsEl = host.querySelector('#vxGSheetPresets');
    const heroEl = host.querySelector('#vxGSheetHero');
    const starsEl= host.querySelector('#vxGSheetStars');
    const badgesEl=host.querySelector('#vxGSheetBadges');
    const panelEl= host.querySelector('#vxGSheetPanel');
    const statsEl= host.querySelector('#vxGSheetStats');
    const journalEl = host.querySelector('#vxGSheetJournal');
    const timeEl = host.querySelector('#vxGSheetTimeline');
    const actsEl = host.querySelector('#vxGSheetActions');

    if (nameEl) nameEl.textContent = 'Guardian';
    if (subEl) subEl.textContent = 'Loading…';
    if (nickWrap) { nickWrap.style.display='none'; if (nickEl) nickEl.textContent=''; }
    if (heroEl) heroEl.innerHTML = '<div class="vx-gsheet-hero skel"></div>';
    if (starsEl) starsEl.innerHTML = '';
    if (badgesEl) badgesEl.innerHTML = '';
    if (panelEl) panelEl.innerHTML = '';
    if (statsEl) statsEl.innerHTML = '';
    if (journalEl) journalEl.innerHTML = '';
    if (timeEl) timeEl.innerHTML = '';
    if (actsEl) actsEl.innerHTML = '';

    try{
      const res = await fetch('/api/user/guardian_detail.php?tarif=' + encodeURIComponent(String(tarif||0)), {credentials:'same-origin'});
      const j = await res.json();
      if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'LOAD');

      const isOwned = !!j.owned;
      const lvl = Number(j.level||0);
      const maxE = Number(j.max_evolve||5);
      const isMax = (lvl >= maxE);
// Micro-moment: first time you open a Guardian sheet (client-only)
try{
  const key = 'vx_seen_' + String(tarif||0);
  if (!localStorage.getItem(key)){
    localStorage.setItem(key,'1');
    haptic('success');
    const host2 = document.getElementById('vxGuardianSheet');
    if (host2) { host2.classList.add('vx-mm-seen'); setTimeout(()=>host2.classList.remove('vx-mm-seen'), 1200); }
  }
}catch(e){}


      if (nameEl) nameEl.textContent = String(j.title || 'Guardian');
      const nick = String(j.nickname || '').trim();
      if (nickWrap){
        if (nick){ nickWrap.style.display='flex'; if (nickEl) nickEl.textContent = '“' + nick + '”'; }
        else { nickWrap.style.display='flex'; if (nickEl) nickEl.textContent = 'Add nickname'; nickWrap.classList.add('is-empty'); }
      }

      if (subEl) subEl.textContent = (isOwned ? 'Owned' : 'Locked') + ' • ' + String(j.rarity_label || '').toUpperCase() + ' • Evolve ' + lvl + '/' + maxE;

      if (nickBtn){
  nickBtn.onclick = async ()=>{
    try{
      const cur = (String(j.nickname||'')||'').trim();
      const val = prompt('Nickname for this Guardian (optional):', cur);
      if (val === null) return;
      const fd = new FormData();
      fd.append('tarif', String(tarif||0));
      fd.append('nickname', String(val||''));
      const rr = await fetch('/api/user/guardian_nickname_set.php', {method:'POST', body: fd, credentials:'same-origin'});
      const jj = await rr.json();
      if (jj && jj.ok){
        j.nickname = jj.nickname || '';
        const nn = String(j.nickname||'').trim();
        if (nickWrap){ nickWrap.classList.toggle('is-empty', !nn); if (nickEl) nickEl.textContent = nn ? ('“'+nn+'”') : 'Add nickname'; }
        haptic('success');
      } else {
        haptic('error');
      }
    }catch(e){ haptic('error'); }
  };
}

// Hero
      const art = String(j.art_url || '/assets/img/guardians/placeholder.png');
      if (heroEl){
        heroEl.classList.remove('skel');
        heroEl.innerHTML = `
          <div class="vx-gsheet-art">
            <img src="${art}" alt="${String(j.title||'Guardian').replace(/"/g,'')}">
            <div class="vx-gsheet-cap">
              <span class="chip">Evolve <b>${lvl}</b></span>
              <span class="chip">${String(j.rarity_label||'').toUpperCase()}</span>
              ${j.is_shiny ? '<span class="chip">✨ Shiny</span>' : ''}
            </div>
          </div>
        `;
      }

      // Stars
      if (starsEl){
        starsEl.innerHTML = buildStars(Number(j.stars||1), Number(j.stars_max||5), isMax) + (Number(j.stars_max||5)===6 ? '<span class="lbl">6★</span>' : (isMax ? '<span class="lbl">MAX</span>' : ''));
      }

      // Badges
      if (badgesEl){
        const b = [];
        if (String(j.kind||'plan') !== 'plan') b.push('<span class="b">🏆 Achievement</span>');
        if (String(j.status||'') === 'matured') b.push('<span class="b warn">⏳ Crossbreed window</span>');
        if (!isOwned) b.push('<span class="b">🔒 Locked</span>');
        badgesEl.innerHTML = b.join('');
      }

      // Panel
      if (panelEl){
        panelEl.innerHTML = `
          <div class="it"><span class="k">Status</span><span class="v">${String(j.status||'').toUpperCase()}</span></div>
          <div class="it"><span class="k">Evolve</span><span class="v">${lvl} / ${maxE}</span></div>
          <div class="it"><span class="k">Stars</span><span class="v">${Number(j.stars||1)} / ${Number(j.stars_max||5)}</span></div>
        `;
      }

      // Stats
      if (statsEl){
        const vp = Number(j.vp_total||0);
        const lp = Number(j.lp_total||0);
        statsEl.innerHTML = `
          <div class="st"><div class="k">Lifetime VP</div><div class="v">${vp.toLocaleString()}</div></div>
          <div class="st"><div class="k">Lifetime LP</div><div class="v">${lp.toLocaleString()}</div></div>
          <div class="st"><div class="k">Acquired</div><div class="v">${fmtTime(j.created_at)}</div></div>
          <div class="st"><div class="k">Matures</div><div class="v">${fmtTime(j.term_end)}</div></div>
        `;
        if (timeEl) timeEl.innerHTML = buildTimelineHTML(j, lvl, maxE);
      }

      // Actions
      if (actsEl){
        const urls = j.urls || {};
        const shareUrl = (window.location.origin || '') + (window.VX_UID ? ('/profile/' + encodeURIComponent(String(window.VX_UID))) : '/');
        const shareText = `My ${String(j.title||'Guardian')} • ${Number(j.stars||1)}★ • Evolve ${lvl}/${maxE} — join my GreenFarm journey.`;
        actsEl.innerHTML =
          buildAction('View plan', urls.plan, false) +
          (isOwned ? buildAction('Evolve', urls.evolve, true) : buildAction('Activate', urls.plan, true)) +
          `<button class="vx-gsheet-btn" type="button" id="vxGSheetExport">Export</button><button class="vx-gsheet-btn" type="button" id="vxGSheetShare">Share</button><button class="vx-gsheet-btn" type="button" id="vxGSheetStory" style="display:none">Story</button>`;


        const exp = actsEl.querySelector('#vxGSheetExport');
        if (exp){
          exp.addEventListener('click', async ()=>{
            try{
              const dataUrl = await renderShareCard(j, lvl, maxE, sharePreset);
              if (isTG()){
                // TG WebView: open image in a new tab so user can long-press to save
                const w = window.open();
                if (w) {
                  w.document.write('<meta name="viewport" content="width=device-width,initial-scale=1" /><title>Export</title><img src="'+dataUrl+'" style="max-width:100%;height:auto;display:block;margin:0 auto;background:#000;" />');
                } else {
                  window.location.href = dataUrl;
                }
              } else {
                const a = document.createElement('a');
                a.href = dataUrl;
                a.download = (String(j.title||'guardian').toLowerCase().replace(/[^a-z0-9\-_]+/g,'-') || 'guardian') + '-card.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
              }
              haptic('success');
            }catch(e){
              haptic('error');
              console.warn(e);
              alert('Export failed. Try again.');
            }
          }, {once:false});
        }

        const storyBtn = actsEl.querySelector('#vxGSheetStory');
        try{
          if (storyBtn && isTG() && Telegram.WebApp && typeof Telegram.WebApp.shareToStory === 'function'){
            storyBtn.style.display='inline-flex';
            storyBtn.addEventListener('click', ()=>{
              try{
                const media = String(j.art_url||'');
                const params = { text: shareText, widget_link: { url: shareUrl, name: 'GreenFarm' } };
                Telegram.WebApp.shareToStory(media, params);
                haptic('success');
              }catch(e){
                haptic('error');
                try{ alert('Story share is not available in this WebView.'); }catch(_e){}
              }
            }, {once:true});
          }
        }catch(_e){}

        const btn = actsEl.querySelector('#vxGSheetShare');
        if (btn){
          btn.addEventListener('click', ()=>{
            try{
              if (isTG() && Telegram.WebApp.openTelegramLink){
                Telegram.WebApp.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(shareUrl) + '&text=' + encodeURIComponent(shareText));
                haptic('success');
                return;
              }
            }catch(e){}
            try{
              window.open('https://t.me/share/url?url=' + encodeURIComponent(shareUrl) + '&text=' + encodeURIComponent(shareText), '_blank');
            }catch(e){}
          }, {once:true});
        }
      }

    }catch(err){
      if (subEl) subEl.textContent = 'Unable to load';
      if (heroEl) heroEl.innerHTML = '<div class="vx-gsheet-err">Unable to load Guardian details.</div>';
      haptic('error');
    }
  }

  function shouldIgnoreClick(target){
    if (!target) return false;
    const a = target.closest('a');
    const b = target.closest('button');
    const inp = target.closest('input,textarea,select,label');
    return !!(a || b || inp);
  }

  function attach(){
    // Delegate: anything with data-guardian-sheet and data-tarif.
    document.addEventListener('click', (e)=>{
      const t = e.target;
      const card = t && t.closest ? t.closest('[data-guardian-sheet][data-tarif]') : null;
      if (!card) return;
      if (shouldIgnoreClick(t)) return;
      const tarif = card.getAttribute('data-tarif');
      if (!tarif) return;
      e.preventDefault();
      open();
      load(tarif);
    }, true);
  }

  // Boot
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', attach);
  } else {
    attach();
  }

  // Expose minimal API
  window.VX_GUARDIAN_SHEET = { open:(tarif)=>{ open(); load(tarif); }, close };
})();