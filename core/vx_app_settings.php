<?php
declare(strict_types=1);

// File: /public_html/core/vx_app_settings.php
// Lightweight file-based settings store (JSON) for admin-tunable growth knobs.
// - Safe defaults if file missing
// - Atomic writes (tmp + rename)

if (!defined('FastCore')) { define('FastCore', true); }

function vx_app_settings_path(): string {
    return __DIR__ . '/app_settings.json';
}

function vx_app_settings_defaults(): array {
    return [
        // Onboarding bonus
        'onboarding_bonus_enabled' => true,
        'onboarding_bonus_points'  => 250,

        // Season Pass badge (granted on first login)
        'season pass_badge_enabled'    => true,
        'season pass_badge_label'      => 'Season Pass Holder',

        // Referral/share templates
        'share_templates_enabled'  => true,
        'share_templates'          => [
            "🚀 I just joined GreenFarm and locked my Season Pass allocation. Join via my link: {link}",
            "🏆 Season Pass is live — points convert to VX at listing. Tap to join: {link}",
            "🔒 Secure your Season Pass status before snapshot locks it. Open: {link}",
        ],

        // Telegram bot deep link (optional, used in share pages/popups)
        // Founders share preview (used by /share/founders.php)
        'founders_share_title' => 'Claim your free Founders Guardian',
        'founders_share_desc'  => 'Limited to the first 30 days from launch. Earn VP daily and climb the season rank.',
        'founders_share_image' => '/img/founders_share.png',


        'tg_bot_username'        => 'GreenFarmAppBot',
        'tg_deeplink_enabled'     => true,

        // Extra hardening toggles
        'strict_ref_hardening'     => true,

        'strict_ref_lock'          => true,   // lock referral after first set (already implemented, this just enforces)
        'strict_tg_session'        => true,   // force re-handshake if Telegram user changes

        // Daily action defaults (API already enforces server-side)
        'daily_checkin_enabled'    => true,

        // --- Crossbreed + points economy knobs (launch-safe defaults) ---
        // Max crossbreed level for a single Guardian chain
        'crossbreed_max'              => 5,
        // Crossbreed window after maturity (seconds)
        'crossbreed_window_sec'       => 86400,
        // Rarity multipliers (applied to vp_per_day / lp_per_day)
        'rarity_mult'              => [
            'common'    => 1.0,
            'rare'      => 1.5,
            'epic'      => 2.25,
            'legendary' => 3.5,
            'mythic'    => 5.0,
        ],
        // Crossbreed multipliers for VP (index = crossbreed level)
        'vp_crossbreed_mult'          => [1.0, 1.15, 1.35, 1.60, 1.90, 2.25],
        // Crossbreed multipliers for LP (index = crossbreed level; level 0 unused)
        'lp_crossbreed_mult'          => [0.0, 1.0, 1.55, 2.30, 3.30, 4.80],
        // Fallback per-day rates if vault_definitions isn't populated
        'vp_fallback_per_usd_per_day' => 0.25,
        'lp_fallback_per_usd_per_day' => 0.05,

        // Legacy VP (existing feature) tuning
        'legacy_vp_rate'           => 0.25,
    ];
}

function vx_app_settings_read(): array {
    $path = vx_app_settings_path();
    $d = vx_app_settings_defaults();

    if (!is_file($path)) return $d;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $d;
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return $d;

    // merge (file overrides defaults)
    return $j + $d;
}

function vx_app_settings_write(array $settings): bool {
    $path = vx_app_settings_path();
    $dir = dirname($path);
    if (!is_dir($dir)) return false;

    $final = $settings + vx_app_settings_defaults();
    $json = json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;

    $tmp = $path . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) return false;

    return @rename($tmp, $path);
}

function vx_app_setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) $cache = vx_app_settings_read();
    if (!array_key_exists($key, $cache)) return $default;

    $val = $cache[$key];
    // Hardwired safety defaults for critical growth settings
    if ($key === 'tg_bot_username') {
        $val = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$val);
        if ($val === '') {
            $defs = vx_app_settings_defaults();
            return (string)($defs['tg_bot_username'] ?? ($default ?? 'GreenFarmAppBot'));
        }
        return $val;
    }
    return $val;
}


// Back-compat wrappers (older adminka pages expect these names)
function vx_app_settings(): array {
    return vx_app_settings_read();
}

function vx_app_settings_save(array $settings): bool {
    return vx_app_settings_write($settings);
}
