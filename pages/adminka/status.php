<?php declare(strict_types=1); define('FastCore', true); require_once __DIR__ . '/../../core/config.php'; ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mini App Status</title>
<style>body{font:14px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#0b0f14;color:#e8eef6}
.wrap{max-width:920px;margin:20px auto;padding:16px}.card{background:#121821;border:1px solid #182131;border-radius:14px;padding:16px 18px;margin:12px 0;box-shadow:0 8px 30px rgba(0,0,0,.25)}
.h{display:flex;align-items:center;gap:10px;margin:0 0 12px 0}pre{white-space:pre-wrap;background:#0d131a;border:1px solid #192233;padding:12px;border-radius:10px;color:#def}</style>
<script src="/js/vx_toast.js">
document.getElementById('test').addEventListener('click', function(){
  vxToast('Test toast from admin status',{duration:2000});
});
</script></head><body><div class="wrap">
<h2 class="h">Mini App Status <small id="ver"></small></h2>
<div class="card"><div id="health">Loading health…</div></div>
<div class="card"><div id="selftest">Loading selftest…</div></div>
<div class="card"><button id="copy">Copy JSON</button> <button id="test">Test Toast</button></div>
</div>
<script>(function(){const H=document.getElementById('health'),S=document.getElementById('selftest'),V=document.getElementById('ver');
fetch('/version.txt',{cache:'no-store'}).then(r=>r.text()).then(t=>{V.textContent=t.trim();}).catch(()=>{});
Promise.all([fetch('/api/health.php'), fetch('/api/selftest.php',{credentials:'include'})])
.then(([h,s])=>Promise.all([h.json().catch(()=>({ok:false})), s.json().catch(()=>({ok:false}))]))
.then(([h,s])=>{H.innerHTML='<b>Health:</b> '+(h.ok?'OK':'Issue')+'<pre>'+JSON.stringify(h,null,2)+'</pre>';S.innerHTML='<b>Selftest:</b> '+(s.ok?'OK':'Issue')+'<pre>'+JSON.stringify(s,null,2)+'</pre>'; if(!h.ok||!s.ok){vxToast('Some checks failed',{duration:4000});}else{vxToast('All checks passed',{duration:1800});}})
.catch(()=>{H.textContent='Health failed'; S.textContent='Selftest failed';});
document.getElementById('copy').addEventListener('click',function(){const t=H.textContent+'\n\n'+S.textContent;navigator.clipboard.writeText(t).then(()=>vxToast('Copied')).catch(()=>vxToast('Copy failed'));});})();
document.getElementById('test').addEventListener('click', function(){
  vxToast('Test toast from admin status',{duration:2000});
});
</script>
</body></html>
