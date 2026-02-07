<?php

// Mini App mode: legacy auth endpoints disabled
if (isset($_REQUEST['action'])) {
  $act = strtolower((string)$_REQUEST['action']);
  if (in_array($act, ['login','register','signup','signin','logout','reset','password','change_password'])) {
    http_response_code(410); exit('legacy_auth_disabled');
  }
}

header("Content-type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_reporting', 0);

define('FastCore', true);
define('BASE_DIR', $_SERVER['DOCUMENT_ROOT']);

$action = filter_var($_GET['action'],FILTER_SANITIZE_STRING);

$arr = [];

$ajax = $arr[$action];

	if($ajax != NULL) {
		if (strpos($action, ".") !== false) {
				exit('Unknown request'); 
		} 
		else {			
			if(file_exists(BASE_DIR.'/ajax/'.$ajax.'.php')) {
				
			session_start();
			
			if(!empty($_SESSION['uid'])){
			spl_autoload_register(function ($name) {
				require (BASE_DIR.'/core/'.$name.'.php'); // система
			});
			
			$config = new config;
			$func = new func;
			
			}
			include(BASE_DIR.'/ajax/'.$ajax.'.php');
		}
	else { 
			exit('Unknown request 2'); 
		}
	}
} else {
		exit('Unknown request 3'); 
}