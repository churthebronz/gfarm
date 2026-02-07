<?php
// pages/user/gallery.php
// Mutant Crops collection gallery (premium TG mini-app feel)

if (!defined('FastCore')) { exit('Opss!'); }

global $db;
if (session_status() !== PHP_SESSION_ACTIVE) { try { session_start(); } catch (Throwable $e) {} }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /login'); exit; }

require_once __DIR__ . '/../../core/vx_guardians.php';
require_once __DIR__ . '/../../core/vx_guardian_art.php';
require_once __DIR__ . '/../../core/vx_affiliate.php';
require_once __DIR__ . '/../../core/vx_founders.php';

vx_guardians_schema_ensure($db);

// Pull guardians
$chains = [];
try {
  $q = $db->query(
    "SELECT c.id, c.kind, c.tarif, c.status, c.crossbreed_level, c.created_at, c.updated_at,
            t.title, t.img, t.price
     FROM vx_guardian_chains c
     LEFT JOIN db_tarif t ON t.id=c.tarif
     WHERE c.uid=?
     ORDER BY c.updated_at DESC, c.id DESC",
    $uid
  );
  if ($q) { while ($r = $q->fetchArray()) { $chains[] = $r; } }
} catch (Throwable $e) {}

// Decorative: determine special badges
$hasFounders = false;
try { $hasFounders = vx_founders_has_card($db, $uid); } catch (Throwable $e) {}
$aff = [];
try { $aff = vx_affiliate_status($db, $uid); } catch (Throwable $e) {}
$hasAffiliate = !empty($aff['has_affiliate_guardian']);

function vx_gallery_img_for($db, int $tarif_id, int $level, ?string $fallbackKey): string {
  $fallbackKey = trim((string)$fallbackKey);
  // If fallback is a direct URL/path, keep it.
  if ($fallbackKey !== '' && (strpos($fallbackKey, 'http') === 0 || strpos($fallbackKey, '/') === 0)) return $fallbackKey;
  $k = '';
  try { $k = vx_guardian_art_key_for_level($db, $tarif_id, $level, $fallbackKey); } catch (Throwable $e) { $k = $fallbackKey; }
  return function_exists('vx_img_items_url_from_key') ? vx_img_items_url_from_key((string)$k) : ('/img/items/' . preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$k) . '.png');
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Gallery • GreenFarm</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/vx_shell.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    body{background:#060816;color:#eaf0ff;}
    .vx-wrap{max-width:1100px;margin:18px auto;padding:0 14px;}
    .vx-card{background:rgba(11,16,36,.92);border:1px solid rgba(255,255,255,.10);border-radius:18px;padding:14px;box-shadow:0 18px 56px rgba(0,0,0,.35);}
    .vx-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
    .vx-head h1{margin:0;font-size:22px;font-weight:950;}
    .vx-sub{opacity:.75;font-size:13px;}
    .vx-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
    @media(max-width:920px){.vx-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media(max-width:520px){.vx-grid{grid-template-columns:1fr;}}
    .vx-g{position:relative;border-radius:20px;overflow:hidden;border:1px solid rgba(255,255,255,.10);background:rgba(14,20,48,.55);box-shadow:0 20px 54px rgba(0,0,0,.35);}
    .vx-g:before{content:"";position:absolute;inset:0;pointer-events:none;opacity:.8;background:radial-gradient(800px 300px at 20% 0%, rgba(0,255,224,.10), transparent 55%), radial-gradient(800px 300px at 80% 100%, rgba(124,92,255,.10), transparent 55%);}
    .vx-g-img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;filter:saturate(1.05) contrast(1.05);}
    .vx-g-b{padding:12px;position:relative;}
    .vx-g-title{font-weight:1000;display:flex;align-items:center;justify-content:space-between;gap:10px;}
    .vx-g-title span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .vx-tag{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);font-weight:900;font-size:12px;}
    .vx-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
    .vx-btn{display:inline-flex;align-items:center;gap:8px;border-radius:14px;padding:10px 12px;font-weight:1000;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#eaf0ff;text-decoration:none;cursor:pointer;}
    .vx-btn.is-primary{border-color:rgba(0,255,224,.28);background:linear-gradient(90deg, rgba(0,255,224,.18), rgba(124,92,255,.14));}
    .vx-hero-badges{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 14px;}
  </style>
</head>
<body>
  <?php include __DIR__ . '/../menu-h.php'; ?>
  <div class="vx-wrap">
    <div class="vx-card">
      <div class="vx-head">
        <div>
          <h1>🗂️ Your Gallery</h1>
          <div class="vx-sub">Your Mutant Crops as collectible cards. Share your profile to invite friends.</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="vx-btn" href="/user/dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
          <a class="vx-btn is-primary" href="/profile/<?= (int)$uid; ?>"><i class="fa-solid fa-share-nodes"></i> Share Profile</a>
        </div>
      </div>

      <div class="vx-hero-badges">
        <?php if($hasFounders): ?><span class="vx-tag"><i class="fa-solid fa-crown"></i> Founders</span><?php endif; ?>
        <?php if($hasAffiliate): ?><span class="vx-tag"><i class="fa-solid fa-handshake"></i> Affiliate</span><?php endif; ?>
        <span class="vx-tag"><i class="fa-solid fa-layer-group"></i> <?= count($chains); ?> cards</span>
      </div>

      <?php if (empty($chains)): ?>
        <div class="vx-empty" style="margin:12px 0;">
  <h3>🧬 No Guardians yet</h3>
  <p>Your gallery is empty. Activate your first Mutant Crop to begin earning and start your Mutant Index journey.</p>
  <div class="vx-empty-actions">
    <a class="vx-btn vx-btn-primary" href="/user/guardians">View Guardians</a>
    <a class="vx-btn" href="/user/codex">Open Mutant Index</a>
  </div>
</div>
        <div style="margin-top:12px;"><a class="vx-btn is-primary" href="/user/guardians"><i class="fa-solid fa-shield-halved"></i> View Mutant Crops</a></div>
      <?php else: ?>
        <div class="vx-grid">
          <?php foreach ($chains as $c):
            $title = trim((string)($c['title'] ?? 'Mutant Crop'));
            if ($title === '') $title = 'Mutant Crop';
            $tarif = (int)($c['tarif'] ?? 0);
            $reb = (int)($c['crossbreed_level'] ?? 0);
            $img = vx_gallery_img_for($db, $tarif, $reb, (string)($c['img'] ?? ''));
            $rar = (float)($c['price'] ?? 0) > 0 ? 'Paid' : 'Free';
            $status = (string)($c['status'] ?? 'active');
          ?>
          <div class="vx-g" data-guardian-sheet="1" data-tarif="<?= (int)$tarif; ?>" role="button" tabindex="0" aria-label="Open Guardian details">
            <img loading="lazy" decoding="async" class="vx-g-img" src="<?= htmlspecialchars($img, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES); ?>">
            <div class="vx-g-b">
              <div class="vx-g-title">
                <span><?= htmlspecialchars($title, ENT_QUOTES); ?></span>
                <span class="vx-tag" title="<?= htmlspecialchars($status, ENT_QUOTES); ?>"><i class="fa-solid fa-circle"></i> <?= htmlspecialchars(strtoupper($status), ENT_QUOTES); ?></span>
              </div>
              <div class="vx-row">
                <span class="vx-tag"><i class="fa-solid fa-wand-magic-sparkles"></i> Evolve <?= (int)$reb; ?></span>
                <span class="vx-tag"><i class="fa-solid fa-lock"></i> <?= htmlspecialchars($rar, ENT_QUOTES); ?></span>
              </div>
              <div class="vx-row" style="margin-top:12px;">
                <a class="vx-btn" href="/user/dashboard#vxBoostEmbed"><i class="fa-solid fa-bolt"></i> Boost</a>
                <button class="vx-btn is-primary" type="button" data-share-profile data-uid="<?= (int)$uid; ?>"><i class="fa-solid fa-share-nodes"></i> Share</button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <script>
  (function(){
    function isTG(){ return !!(window.Telegram && Telegram.WebApp); }
    function openShare(url, text){
      try{
        if (isTG() && Telegram.WebApp.openTelegramLink){
          Telegram.WebApp.openTelegramLink('https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(text));
          try{ Telegram.WebApp.HapticFeedback.impactOccurred('light'); }catch(e){}
          return;
        }
      }catch(e){}
      try{ window.open('https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(text), '_blank'); }catch(e){}
    }

    document.querySelectorAll('[data-share-profile]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var uid = btn.getAttribute('data-uid') || '0';
        var url = window.location.origin + '/profile/' + encodeURIComponent(uid);
        var text = 'Check out my GreenFarm Guardians — claim your Founders Guardian while it is available.';
        openShare(url, text);
      });
    });
  })();
  </script>

  <!-- Guardian detail sheet -->
  <script src="/assets/js/vx_guardian_sheet.js"></script>
</body>
</html>
