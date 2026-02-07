<?php
declare(strict_types=1);
// File: /core/season_pass.php
// Purpose: Season Pass ($25/season) helpers + perks.

if (!defined('FastCore')) { define('FastCore', true); }

function vx_season_pass_price_usd(): float { return 25.0; }

function vx_season_pass_stars_xtr(): int {
    $v = (int)(getenv('SEASON_PASS_XTR') ?: 2500);
    return $v > 0 ? $v : 2500;
}

function vx_season_pass_active($db, int $uid, int $seasonId): bool {
    if (!$db || $uid<=0 || $seasonId<=0) return false;
    try {
        $row = $db->query("SELECT id FROM vx_season_passes WHERE uid=? AND season_id=? LIMIT 1", $uid, $seasonId)->fetchArray();
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

function vx_season_pass_grant($db, int $uid, int $seasonId, string $via='balance'): bool {
    if (!$db || $uid<=0 || $seasonId<=0) return false;
    $now = time();
    try {
        $db->query(
            "INSERT INTO vx_season_passes (uid, season_id, purchased_via, created_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE purchased_via=VALUES(purchased_via)",
            $uid, $seasonId, substr($via,0,24), $now
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function vx_season_pass_points_mult(): float { return 1.10; } // +10%
function vx_season_pass_cap_buffer_pct(): float { return 0.05; } // +5%
function vx_season_pass_weekly_extra_claims(): int { return 1; } // +1 claim

function vx_season_pass_badge_html(bool $compact=false): string {
    $txt = $compact ? 'PASS' : 'Season Pass';
    return '<span class="vx-sp-badge" title="Season Pass active"><i class="fa-solid fa-crown"></i> '.$txt.'</span>';
}
