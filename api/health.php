<?php
declare(strict_types=1);
define('FastCore', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth_mw.php';
global $db, $config;

// Production hardening: health output can leak environment/schema info.
// Allow only a single admin UID (config->diag_allow_uid), defaults to 1.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$allowUid = isset($config->diag_allow_uid) ? (int)$config->diag_allow_uid : 1;
$uid = (int)($_SESSION['uid'] ?? 0);
if ($uid <= 0 || ($allowUid > 0 && $uid !== $allowUid)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

$ok=true; $issues=[]; $bot = (string)($config->bot_token ?? ''); if(!$bot){$issues[] = 'TG bot token missing'; $ok=false;}
$need=['db_users','db_tg_sessions','db_store','db_tarif','db_earnings']; $missing=[]; try{ foreach($need as $t){ $r=$db->query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', $t)->fetchArray(); if((int)($r['c']??0)===0)$missing[]=$t; } }catch(Throwable $e){}
if(!empty($missing)){ $issues[]='missing tables: '.implode(',', $missing); $ok=false; }
echo json_encode(['ok'=>$ok,'issues'=>$issues,'env'=>['bot_token'=>($bot?'set':'missing')],'ts'=>time()]);