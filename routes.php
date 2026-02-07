<?php
if (!defined('FastCore')) { echo ('Выявлена попытка взлома!'); exit(); }

/**
 * Router rules map (values resolve under /pages unless intentionally escaping).
 * NOTE: index.php defines $adm and $tgCallbackUrl before requiring this file.
 */

$GLOBALS['routes'] = array(
  // 404
  '_404'                 => '../inc/404.php',

  // Public
  '/'                    => 'home.php',
  '/p/([0-9]+)?'         => 'home.php',
  '/i/([A-Za-z0-9]+)?' => 'i.php',

  // Public referral landing (share outside Telegram)
  '/invite/([A-Za-z0-9]+)?' => 'invite.php',

  // Public surfaces (shareable)
  '/leaderboards'           => 'leaderboards.php',
  '/profile/([0-9]+)?'      => 'profile.php',


  // index.php handles "view" specially; keep it here to avoid 404s
  '/view/([0-9]+)?'      => 'home.php',

  // Telegram callback (uses the var set in index.php)
  '/'.$tgCallbackUrl     => $tgCallbackUrl . '.php',

  // News/Stats
  '/stats'               => 'stats.php',
  '/news'                => 'news.php',
  '/news/p/([0-9]+)?'    => 'news.php',

  // Auth page + aliases (all serve pages/auth.php)
  '/auth'                => 'auth.php',
  '/login'               => 'auth.php',
  '/register'            => 'auth.php',
  '/signin'              => 'auth.php',
  '/signup'              => 'auth.php',

  // Legal/Help
  '/terms'               => 'terms.php',
  '/privacy'             => 'privacy.php',
  '/help'                => 'help.php',

  // Account
  '/user'                        => 'dashboard.php',

  '/user/dashboard'              => 'dashboard.php',
  '/user/bonus'                  => 'bonus.php',
  '/user/plans'                  => 'guardians.php',
  '/user/guardians'               => 'guardians.php',
  // Aliases / new modules (keep stable links from dock + marketing)
  '/user/boost'                  => 'boost.php',
  '/user/earnings'               => 'earnings.php',
  '/user/codex'                  => 'codex.php',
  '/user/vp-history'             => 'vp-history.php',
  '/user/lp-history'             => 'lp-history.php',
  '/user/history'                => 'store.php',
  '/user/seasons'                => 'season_history.php',
  '/user/launch'                 => 'launch_checklist.php',
  '/user/points'                 => 'points.php',
  '/user/rewards'                => 'rewards.php',
  '/user/inbox'                  => 'inbox.php',
  '/user/token'    => 'token.php',
  '/user/token/'   => 'token.php',
  '/user/leaderboard'            => 'leaderboard.php',
  '/user/leaderboards'           => 'leaderboards.php',
  '/user/gallery'                => 'user/gallery.php',
  '/gallery'                     => 'user/gallery.php',
  '/user/insert'                 => 'insert.php',
  '/user/insert/' => 'insert.php',
  '/user/insert/([^/]+)'         => 'insert.php',
  '/user/insert/cancel/([0-9]+)' => 'insert.php',
  '/user/deposit'                => 'deposit.php',
  '/user/deposit/([^/]+)'        => 'deposit.php',
  '/user/pay'                    => 'pay.php',
  '/user/pay/([^/]+)'            => 'pay.php',
  '/user/refs'                   => 'referals.php',
  '/user/settings'               => 'settings.php',
  '/user/ref-debug'              => 'ref_debug.php',

  // Affiliate
  '/user/affiliate'              => 'affiliate.php',
  '/user/success'                => 'status_success.php',
  '/user/fail'                   => 'status_fail.php',
  '/user/logout'                 => 'dashboard.php',

  // Admin (index decides login vs main)
  '/'.$adm                     => 'main.php',
  '/'.$adm.'/'                 => 'main.php',
  '/'.$adm.'/logout'           => 'logout.php',
  '/'.$adm.'/exit'             => 'logout.php',
  '/'.$adm.'/config'           => 'config.php',
  '/'.$adm.'/seasons'          => 'seasons.php',
  '/'.$adm.'/contest_ref'      => 'contest_refs.php',
  '/'.$adm.'/contest_ref/list' => 'contest_refs.php',
  '/'.$adm.'/contest_ref/add'  => 'contest_refs.php',
  '/'.$adm.'/youtube'          => 'youtube.php',
  '/'.$adm.'/users'            => 'users.php',
  '/'.$adm.'/users/info/([0-9]+)?' => 'users.php',
  '/'.$adm.'/users/p/([0-9]+)?'    => 'users.php',
  '/'.$adm.'/uips'             => 'uips.php',
  '/'.$adm.'/uips/p/([0-9]+)?' => 'uips.php',
  '/'.$adm.'/st/([^/]+)'       => 'stats.php',
  '/'.$adm.'/news'             => 'news.php',
  '/'.$adm.'/news/add'         => 'news.php',
  '/'.$adm.'/news/edit/([0-9]+)?' => 'news.php',
  '/'.$adm.'/pers'             => 'pers.php',
  '/'.$adm.'/pers/add'         => 'pers.php',
  '/'.$adm.'/pers/edit/([0-9]+)?' => 'pers.php',
  // Aliases / newer admin pages
  '/'.$adm.'/vaults'           => 'pers.php',
  '/'.$adm.'/risk'             => 'risk.php',
  '/'.$adm.'/trust'            => 'trust.php',
  '/'.$adm.'/audit'            => 'audit.php',
  '/'.$adm.'/referers'         => 'referers.php',
  '/'.$adm.'/referers/all'     => 'referers.php',
  '/'.$adm.'/paysystem'        => 'paysystem.php',
  '/'.$adm.'/fake'             => 'fake.php',
  '/'.$adm.'/pays'             => 'pays.php',
  '/'.$adm.'/pays_payeer'      => 'pays_payeer.php',

  '/'.$adm.'/launch-checklist' => 'launch_checklist.php',
  '/'.$adm.'/health'           => 'health.php',
  '/'.$adm.'/guardian-tuning'  => 'guardian_tuning.php',
  '/'.$adm.'/economy'         => 'economy.php',
  '/'.$adm.'/partners'        => 'partners.php',
  '/'.$adm.'/growth'           => 'growth.php',
  '/'.$adm.'/js-errors'        => 'js_errors.php',

  // Affiliate dashboards
  '/'.$adm.'/affiliates'         => 'affiliates.php',

);
