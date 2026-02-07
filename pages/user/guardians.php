<?php
/**
 * File: /pages/user/guardians.php
 * GreenFarm — Guardians Marketplace (curated 9 buyable guardians)
 * Source of truth: db_tarif (plans=guardians)
 */
if (!defined('FastCore')) { exit('Opss!'); }

global $db, $user, $uid, $config;
require_once __DIR__ . '/../../core/seasons.php';
require_once __DIR__ . '/../../core/season_pass.php';
require_once __DIR__ . '/../../core/idempotency.php';
require_once __DIR__ . '/../../core/idk.php';

$opt['title'] = 'Guardians';

define('VX_GUARDIANS_MODE', true);
// Reuse the existing premium purchase flow + cards from boost.php
require __DIR__ . '/boost.php';
