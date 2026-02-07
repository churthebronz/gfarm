<?php
// pages/adminka/inc/menu.php (GreenFarm Admin • logout fixed)
if (!defined('FastCore')) { exit(); }

global $adm;

$uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$admBase = '/'.(string)$adm;

// PHP 5.6 safe
$navActive = function($path) use ($uri, $admBase) {
    $path = (string)$path;
    $p = $admBase . ($path === '' ? '/' : '/'.$path);
    return (strpos($uri, $p) === 0) ? ' active' : '';
};

// Logout endpoint (handled by router)
$logoutUrl = '/'.(string)$adm.'/logout';
?>
<aside class="vx-admin-side">
  <div class="vx-admin-brand">
    <div class="t1"><i class="fa fa-shield" style="margin-right:8px;color:rgba(251,191,36,.95)"></i>GreenFarm Ops</div>
    <div class="t2">Admin panel • serious mode</div>
  </div>

  <ul class="vx-admin-nav">
    <li><a class="nav-link<?php echo $navActive(''); ?>" href="/<?php echo $adm; ?>/"><i class="fa fa-bar-chart-o"></i> Statistics</a></li>
    <li><a class="nav-link<?php echo $navActive('st/inserts'); ?>" href="/<?php echo $adm; ?>/st/inserts"><i class="fa fa-plus"></i> Deposits</a></li>
    <li><a class="nav-link<?php echo $navActive('st/payouts'); ?>" href="/<?php echo $adm; ?>/st/payouts"><i class="fa fa-minus"></i> Withdrawals</a></li>
    <li><a class="nav-link<?php echo $navActive('seasons'); ?>" href="/<?php echo $adm; ?>/seasons"><i class="fa fa-calendar"></i> Seasons</a></li>
    <li><a class="nav-link<?php echo $navActive('pers'); ?>" href="/<?php echo $adm; ?>/pers"><i class="fa fa-bank"></i> Seeds</a></li>
    <li><a class="nav-link<?php echo $navActive('guardian-tuning'); ?>" href="/<?php echo $adm; ?>/guardian-tuning"><i class="fa fa-sliders"></i> Mutant Tuning</a></li>
    <li><a class="nav-link<?php echo $navActive('risk'); ?>" href="/<?php echo $adm; ?>/risk"><i class="fa fa-shield"></i> Risk</a></li>
    <li><a class="nav-link<?php echo $navActive('audit'); ?>" href="/<?php echo $adm; ?>/audit"><i class="fa fa-list"></i> Audit</a></li>
    <li><a class="nav-link<?php echo $navActive('activity'); ?>" href="/<?php echo $adm; ?>/activity"><i class="fa fa-bolt"></i> Activity</a></li>
    <li><a class="nav-link<?php echo $navActive('economy'); ?>" href="/<?php echo $adm; ?>/economy"><i class="fa fa-line-chart"></i> Economy</a></li>
    <li><a class="nav-link<?php echo $navActive("growth"); ?>" href="/<?php echo $adm; ?>/growth"><i class="fa fa-rocket"></i> Growth</a></li>
    <li><a class="nav-link<?php echo $navActive("affiliates"); ?>" href="/<?php echo $adm; ?>/affiliates"><i class="fa fa-users"></i> Affiliates</a></li>
    <li><a class="nav-link<?php echo $navActive("js-errors"); ?>" href="/<?php echo $adm; ?>/js-errors"><i class="fa fa-bug"></i> JS Errors</a></li>
    <li><a class="nav-link<?php echo $navActive('users'); ?>" href="/<?php echo $adm; ?>/users"><i class="fa fa-users"></i> Users</a></li>
    <li><a class="nav-link<?php echo $navActive('fake'); ?>" href="/<?php echo $adm; ?>/fake"><i class="fa fa-magic"></i> Simulator</a></li>
    <li><a class="nav-link<?php echo $navActive('config'); ?>" href="/<?php echo $adm; ?>/config"><i class="fa fa-gear"></i> Settings</a></li>
    <li><a class="nav-link<?php echo $navActive('paysystem'); ?>" href="/<?php echo $adm; ?>/paysystem"><i class="fa fa-credit-card"></i> Settings PS</a></li>
    <li><a class="nav-link<?php echo $navActive('health'); ?>" href="/<?php echo $adm; ?>/health"><i class="fa fa-heartbeat"></i> Health</a></li>
  </ul>
</aside>

<div class="vx-admin-main">
  <div class="vx-admin-top">
    <div class="left">
      <a title="Home" href="/<?php echo $adm; ?>"><i class="fa fa-home"></i></a>
      <span class="sep">•</span>
      <a title="Refresh" href="<?php echo htmlspecialchars($uri, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-refresh"></i></a>
      <span class="sep">•</span>
      <a title="Open site" href="/" target="_blank"><i class="fa fa-link"></i></a>
    </div>

    <div class="right" style="display:flex;gap:10px">
      <span class="vx-admin-pill ok"><i class="fa fa-lock"></i> Admin</span>
      <a class="vx-admin-pill bad" href="<?php echo $logoutUrl; ?>"><i class="fa fa-sign-out"></i> Logout</a>
    </div>
  </div>

  <div class="vx-admin-content">
