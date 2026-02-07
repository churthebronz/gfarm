<?php
// GreenFarm — VX Token (single destination)
if (!defined('FastCore')) define('FastCore', true);

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/seasons.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0) { header('Location: /auth', true, 302); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$opt['title'] = 'VX Token';

$site = rtrim((string)($config->siteurl ?? ''), '/');
if (stripos($site, 'http') !== 0) $site = 'https://' . $site;

// SAFE season read (no fatal if function name differs)
$endsIn = 0;
try {
  if (function_exists('vx_current_season')) {
    $season = (array)vx_current_season($db);
    $endsIn = (int)($season['ends_in'] ?? 0);
  } elseif (function_exists('vx_get_current_season')) {
    $season = (array)vx_get_current_season($db);
    $ea = (int)($season['ends_at'] ?? 0);
    $endsIn = $ea > 0 ? max(0, $ea - time()) : 0;
  }
} catch (Throwable $e) { $endsIn = 0; }
?>
<div class="container" style="max-width:920px;padding-top:12px;padding-bottom:10px;">
  <div class="vx-card" style="padding:16px;border:1px solid rgba(255,154,46,.22);background:linear-gradient(135deg, rgba(255,154,46,.14), rgba(0,255,224,.08));">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <div style="font-weight:950;font-size:18px;letter-spacing:.2px;">The future of GreenFarm: <span style="color:#ff9a2e;">VX Token</span></div>
        <div style="color:rgba(234,240,255,.75);font-size:12px;margin-top:6px;line-height:1.45;max-width:740px;">
          VX is the upcoming ecosystem token planned to power long-term utility and status.
          Rewards inside the app are currently paid and shown in <b>Points</b> for simplicity and speed.
          This page is the only place where token details live.
          <?php if ($endsIn > 0): ?>
            <span class="vx-chip vx-chip--warn" style="margin-left:6px;">Season ends in <?= (int)floor($endsIn/86400) ?>d</span>
          <?php endif; ?>
        </div>
      </div>
      <a class="vx-chip vx-chip--accent" href="/user/dashboard" style="text-decoration:none;">Back to dashboard</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:14px;">
      <div class="vx-card" style="padding:12px;">
        <div style="font-weight:900;">What to expect</div>
        <ul style="margin:8px 0 0 18px;color:rgba(234,240,255,.75);font-size:13px;line-height:1.5;">
          <li>Clear token utility and in-app use cases before any listing.</li>
          <li>Transparent timeline shared here (no spam across other pages).</li>
          <li>Points remain the reward unit for leaderboards and referrals.</li>
        </ul>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="vx-card" style="padding:12px;">
          <div style="color:rgba(234,240,255,.72);font-size:12px;">Status</div>
          <div style="font-weight:950;font-size:16px;margin-top:4px;">Pre-listing</div>
          <div style="color:rgba(234,240,255,.62);font-size:11px;margin-top:3px;">Token information stays centralized to keep the app clean.</div>
        </div>
        <div class="vx-card" style="padding:12px;">
          <div style="color:rgba(234,240,255,.72);font-size:12px;">Your action</div>
          <div style="font-weight:950;font-size:16px;margin-top:4px;">Earn points → climb rank</div>
          <div style="color:rgba(234,240,255,.62);font-size:11px;margin-top:3px;">Points rewards are instant and easy to understand.</div>
        </div>
      </div>

      <div class="vx-card" style="padding:12px;">
        <div style="font-weight:900;">Important</div>
        <div style="color:rgba(234,240,255,.75);font-size:13px;line-height:1.5;margin-top:6px;">
          This page is informational only. Avoiding token walls-of-text everywhere else keeps the UX premium and conversion-focused.
        </div>
      </div>
    </div>
  </div>
</div>
