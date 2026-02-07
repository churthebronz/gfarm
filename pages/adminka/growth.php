<?php
// pages/adminka/growth.php
declare(strict_types=1);
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/schema_ensure.php';
require_once __DIR__ . '/../../core/vx_app_settings.php';
require_once __DIR__ . '/../../core/events_log.php';

global $db, $config, $opt;
$opt['title'] = 'Growth Settings';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Minimal admin gate: rely on existing adminka auth (if you have one)
// If your admin system uses a different guard, keep it.
if (!isset($_SESSION['adminka']) && !isset($_SESSION['admin']) && !isset($_SESSION['adm'])) {
  // fall back to existing adminka login page if present
  header('Location: /'.($config->adm_dir ?? 'adminka').'/login');
  exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$err = '';

// Load current settings
$s = vx_app_settings();

// Save
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $want = $s;
  $want['onboarding_bonus_enabled'] = isset($_POST['onboarding_bonus_enabled']);
  $want['onboarding_bonus_points']  = max(0, (int)($_POST['onboarding_bonus_points'] ?? 0));

  $want['season pass_badge_enabled'] = isset($_POST['season pass_badge_enabled']);
  $want['season pass_badge_label']   = trim((string)($_POST['season pass_badge_label'] ?? 'Season Pass Holder'));
  if ($want['season pass_badge_label'] === '') $want['season pass_badge_label'] = 'Season Pass Holder';

  $want['share_templates_enabled'] = isset($_POST['share_templates_enabled']);
  $rawTpls = (string)($_POST['share_templates'] ?? '');
  $tpls = [];
  foreach (preg_split('~\r?\n~', $rawTpls) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $tpls[] = $line;
  }
  $want['share_templates'] = $tpls;
  // Telegram bot deep link
  $want['tg_bot_username'] = trim((string)($_POST['tg_bot_username'] ?? ''));
  $want['tg_bot_username'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$want['tg_bot_username']);
  $want['tg_deeplink_enabled'] = isset($_POST['tg_deeplink_enabled']);

  // Founders share preview
  $want['founders_share_title'] = trim((string)($_POST['founders_share_title'] ?? 'Claim your free Founders Seed'));
  if ($want['founders_share_title'] === '') $want['founders_share_title'] = 'Claim your free Founders Seed';
  $want['founders_share_desc'] = trim((string)($_POST['founders_share_desc'] ?? 'Limited to the first 30 days from launch. Earn VP daily and climb the season rank.'));
  if ($want['founders_share_desc'] === '') $want['founders_share_desc'] = 'Limited to the first 30 days from launch. Earn VP daily and climb the season rank.';
  $want['founders_share_image'] = trim((string)($_POST['founders_share_image'] ?? '/img/founders_share.png'));
  if ($want['founders_share_image'] === '') $want['founders_share_image'] = '/img/founders_share.png';

  // Affiliate Seed unlock knobs
  $want['affiliate_required_refs'] = max(1, (int)($_POST['affiliate_required_refs'] ?? 10));
  $want['affiliate_vp_per_day'] = max(0, (int)($_POST['affiliate_vp_per_day'] ?? 5));
  $want['affiliate_lp_per_day'] = max(0, (int)($_POST['affiliate_lp_per_day'] ?? 5));
  $want['affiliate_badge_label'] = trim((string)($_POST['affiliate_badge_label'] ?? 'Affiliate'));
  if ($want['affiliate_badge_label'] === '') $want['affiliate_badge_label'] = 'Affiliate';

  $want['strict_ref_hardening'] = isset($_POST['strict_ref_hardening']);

  if (vx_app_settings_save($want)) {
    $msg = 'Saved.';
    $s = vx_app_settings();
  } else {
    $err = 'Failed to save (file permissions). Make sure /core is writable for app_settings.json.';
  }
}

// Proof wall (latest events)
$wall = [];
try {
  $q = $db->query("SELECT id, uid, event, meta, created_at FROM events_log WHERE event IN ('ref_apply','share_proof','checkin','tg_auth') ORDER BY id DESC LIMIT 80");
  if ($q) { while ($r = $q->fetchArray()) { $wall[] = $r; } }
} catch (Throwable $e) {}

?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .vx-wrap{max-width:1100px;margin:18px auto;padding:0 12px}
  .vx-card{border-radius:18px;border:1px solid rgba(148,163,184,.16);background:rgba(2,6,23,.55);box-shadow:0 18px 52px rgba(0,0,0,.45)}
  .vx-hd{padding:14px 16px;border-bottom:1px solid rgba(148,163,184,.12)}
  .vx-bd{padding:14px 16px}
  .vx-mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace}
  textarea{min-height:130px}
</style>

<div class="vx-wrap">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h3 class="m-0">Growth settings</h3>
      <div class="text-secondary">Safe knobs you can tweak without touching code.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/health">Diagnostics</a>
      <a class="btn btn-outline-secondary" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/seasons">Seasons</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err); ?></div><?php endif; ?>

  <div class="vx-card mb-3">
    <div class="vx-hd"><strong>Onboarding + Sharing</strong></div>
    <div class="vx-bd">
      <form method="post">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="onb" name="onboarding_bonus_enabled" <?= !empty($s['onboarding_bonus_enabled'])?'checked':''; ?>>
              <label class="form-check-label" for="onb"><strong>First-login points bonus</strong></label>
            </div>
            <div class="mt-2">
              <label class="form-label">Points amount</label>
              <input class="form-control" type="number" min="0" name="onboarding_bonus_points" value="<?= (int)($s['onboarding_bonus_points'] ?? 250); ?>">
              <div class="form-text">Awarded once per user, right after a successful Telegram login.</div>
            </div>


            <hr>

            <div class="mt-2">
              <label class="form-label"><strong>Affiliate Seed</strong></label>
              <div class="row g-2">
                <div class="col-sm-4">
                  <label class="form-label">Required paid referrals</label>
                  <input class="form-control" type="number" min="1" name="affiliate_required_refs" value="<?= (int)($s['affiliate_required_refs'] ?? 10); ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">VP per day</label>
                  <input class="form-control" type="number" min="0" name="affiliate_vp_per_day" value="<?= (int)($s['affiliate_vp_per_day'] ?? 5); ?>">
                </div>
                <div class="col-sm-4">
                  <label class="form-label">LP per day</label>
                  <input class="form-control" type="number" min="0" name="affiliate_lp_per_day" value="<?= (int)($s['affiliate_lp_per_day'] ?? 5); ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Badge label</label>
                  <input class="form-control" type="text" name="affiliate_badge_label" value="<?= h((string)($s['affiliate_badge_label'] ?? 'Affiliate')); ?>">
                  <div class="form-text">Unlock rule: counts <b>paid</b> seed activations only (excludes Founders / Affiliate). This seed pays VP + LP only.</div>
                </div>
              </div>
            </div>

            <hr>

            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="gen" name="season pass_badge_enabled" <?= !empty($s['season pass_badge_enabled'])?'checked':''; ?>>
              <label class="form-check-label" for="gen"><strong>Season Pass badge</strong></label>
            </div>
            <div class="mt-2">
              <label class="form-label">Badge label</label>
              <input class="form-control" type="text" name="season pass_badge_label" value="<?= h((string)($s['season pass_badge_label'] ?? 'Season Pass Holder')); ?>">
              <div class="form-text">Shown on user dashboard after first login.</div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="tpl" name="share_templates_enabled" <?= !empty($s['share_templates_enabled'])?'checked':''; ?>>
              <label class="form-check-label" for="tpl"><strong>Use share templates</strong></label>
            </div>
            <div class="mt-2">
              <label class="form-label">Templates (one per line). Use <span class="vx-mono">{link}</span> placeholder.</label>
              <textarea class="form-control" name="share_templates" spellcheck="false"><?php
                $tpls = (array)($s['share_templates'] ?? []);
                echo h(implode("\n", $tpls));
              ?></textarea>
              <div class="form-text">Example: <span class="vx-mono">🚀 Season Pass is live. Claim points: {link}</span></div>
            </div>
            <hr>

            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="tgd" name="tg_deeplink_enabled" <?= !empty($s['tg_deeplink_enabled'])?'checked':''; ?>>
              <label class="form-check-label" for="tgd"><strong>Telegram bot deep link</strong></label>
            </div>
            <div class="mt-2">
              <label class="form-label">Bot username (no @)</label>
              <?php $tgU = preg_replace('/[^a-zA-Z0-9_]/','', (string)($s['tg_bot_username'] ?? '')); if ($tgU === '') $tgU = 'GreenFarmAppBot'; ?>
              <input class="form-control" type="text" name="tg_bot_username" value="<?= h($tgU); ?>" placeholder="GreenFarmAppBot">
              <div class="form-text">Used in share links so friends can open your bot directly: t.me/<span class="vx-mono">bot</span>?start=ref_CODE</div>
            </div>


            <hr>

            <div class="mt-2">
              <label class="form-label"><strong>Founders share preview</strong></label>
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label">Preview title</label>
                  <input class="form-control" type="text" name="founders_share_title" value="<?= h((string)($s['founders_share_title'] ?? 'Claim your free Founders Seed')); ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Preview description</label>
                  <input class="form-control" type="text" name="founders_share_desc" value="<?= h((string)($s['founders_share_desc'] ?? 'Limited to the first 30 days from launch. Earn VP daily and climb the season rank.')); ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Preview image path or URL</label>
                  <input class="form-control" type="text" name="founders_share_image" value="<?= h((string)($s['founders_share_image'] ?? '/img/founders_share.png')); ?>" placeholder="/img/founders_share.png">
                  <div class="form-text">Used for the Telegram link preview on <span class="vx-mono">/share/founders.php</span>. Default file included: <span class="vx-mono">/img/founders_share.png</span></div>
                </div>
              </div>
            </div>


            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="strict" name="strict_ref_hardening" <?= !empty($s['strict_ref_hardening'])?'checked':''; ?>>
              <label class="form-check-label" for="strict"><strong>Strict referral hardening</strong></label>
            </div>
            <div class="text-secondary small mt-1">Tightens duplicate-device/IP and suspicious patterns. (Fail-soft.)</div>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Save settings</button>
          <a class="btn btn-outline-secondary" href="/<?= h($config->adm_dir ?? 'adminka'); ?>/growth">Reload</a>
        </div>
      </form>
    </div>
  </div>

  <div class="vx-card">
    <div class="vx-hd"><strong>Referral / Quest proof wall</strong> <span class="text-secondary">(latest 80)</span></div>
    <div class="vx-bd">
      <?php if (empty($wall)): ?>
        <div class="text-secondary">No events yet.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-dark table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Time</th><th>UID</th><th>Event</th><th>Meta</th></tr></thead>
            <tbody>
              <?php foreach ($wall as $r):
                $meta = (string)($r['meta'] ?? '');
                if (strlen($meta) > 180) $meta = substr($meta, 0, 180) . '…';
              ?>
                <tr>
                  <td class="vx-mono"><?= (int)($r['id'] ?? 0); ?></td>
                  <td class="vx-mono"><?= date('m-d H:i:s', (int)($r['created_at'] ?? 0)); ?></td>
                  <td class="vx-mono"><?= (int)($r['uid'] ?? 0); ?></td>
                  <td><?= h((string)($r['event'] ?? '')); ?></td>
                  <td class="vx-mono"><?= h($meta); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
