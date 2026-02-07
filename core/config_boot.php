<?php
// core/config_boot.php — normalize/override $config safely

if (!isset($config) || !is_object($config)) { $config = new stdClass(); }

/* Map legacy DB constants into $config if not already set */
if (empty($config->db_host) && defined('dbHost')) $config->db_host = dbHost;
if (empty($config->db_user) && defined('dbUser')) $config->db_user = dbUser;
if (empty($config->db_pass) && defined('dbPass')) $config->db_pass = dbPass;
if (empty($config->db_name) && defined('dbName')) $config->db_name = dbName;

/* Bot token: allow overrides via constant or env */
if (empty($config->bot_token)) {
    if (defined('BOT_TOKEN')) {
        $config->bot_token = BOT_TOKEN;
    } elseif (getenv('TELEGRAM_BOT_TOKEN')) {
        $config->bot_token = getenv('TELEGRAM_BOT_TOKEN');
    }
}
if (!empty($config->bot_token) && empty($config->telegram_token)) {
    $config->telegram_token = $config->bot_token;
}

// Optional: allow env override for bot username
if (empty($config->telegram_bot) && getenv('TELEGRAM_BOT_USERNAME')) {
    $config->telegram_bot = getenv('TELEGRAM_BOT_USERNAME');
}

// no closing tag
