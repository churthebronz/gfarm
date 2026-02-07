<?php
declare(strict_types=1);

// File: /public_html/core/schema_helpers.php
// Purpose: Safe schema helpers for MariaDB/MySQL + FastCore DB wrapper.
// Important: Avoid prepared placeholders in SHOW statements (some servers/drivers reject it).

if (!defined('FastCore')) { define('FastCore', true); }

/**
 * Best-effort last error string from the DB wrapper.
 */
function vx_db_last_error($db): string {
    try {
        if (is_object($db)) {
            if (method_exists($db, 'lastErrorMsg')) return (string)$db->lastErrorMsg();
            if (method_exists($db, 'error'))       return (string)$db->error();
            if (method_exists($db, 'lastError'))   return (string)$db->lastError();
        }
    } catch (Throwable $e) {}
    return '';
}

/**
 * Escape a value for safe embedding inside single quotes.
 * (We avoid placeholders for some schema queries/SHOW statements.)
 */
function vx_sql_quote(string $v): string {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
}

/**
 * Fetch a row safely from DB result objects that support fetchArray()/fetch().
 *
 * CRITICAL: Some FastCore DB wrappers PRINT errors from fetchArray() instead of throwing.
 * So we buffer output and discard it to avoid "SQL Error: No valid query executed before fetchArray()."
 */
function vx_safe_fetch_array($res): ?array {
    if (!$res) return null;
    if (is_array($res)) return $res;

    try {
        // Prefer fetchArray()
        if (is_object($res) && method_exists($res, 'fetchArray')) {
            ob_start();
            $row = null;
            try { $row = $res->fetchArray(); } catch (Throwable $e) { $row = null; }
            $out = ob_get_clean();

            // If the wrapper printed an error, treat as failure
            if (is_string($out) && $out !== '') {
                // swallow output
                return null;
            }
            return is_array($row) ? $row : null;
        }

        // Fallback fetch()
        if (is_object($res) && method_exists($res, 'fetch')) {
            ob_start();
            $row = null;
            try { $row = $res->fetch(); } catch (Throwable $e) { $row = null; }
            $out = ob_get_clean();

            if (is_string($out) && $out !== '') {
                return null;
            }
            return is_array($row) ? $row : null;
        }
    } catch (Throwable $e) {
        // ignore
    }

    return null;
}

/**
 * Execute a schema query safely. Never lets printed DB errors leak to output.
 */
function vx_schema_query($db, string $sql) {
    if (!$db) return null;

    try {
        ob_start();
        $res = null;
        try { $res = $db->query($sql); } catch (Throwable $e) { $res = null; }
        $out = ob_get_clean();

        // if query printed anything, swallow it and fail soft
        if (is_string($out) && $out !== '') return null;

        return $res ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Detect whether this install is using SQLite.
 * GreenFarm commonly runs on SQLite3 (fetchArray()) but some installs use MySQL/MariaDB.
 */
function vx_db_is_sqlite($db): bool {
    if (!$db) return false;
    if (class_exists('SQLite3') && ($db instanceof SQLite3)) return true;
    // Heuristic for SQLite-like wrappers
    if (is_object($db) && method_exists($db, 'lastErrorMsg') && method_exists($db, 'querySingle')) return true;
    return false;
}

/**
 * Check if a table exists (MySQL/MariaDB).
 * Uses information_schema first (reliable), then SHOW TABLES LIKE (no placeholders).
 */
function vx_table_exists($db, string $name): bool {
    $name = trim($name);
    if ($name === '' || !$db) return false;

    // SQLite
    if (vx_db_is_sqlite($db)) {
        try {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=" . vx_sql_quote($name) . " LIMIT 1";
            $res = vx_schema_query($db, $sql);
            $row = vx_safe_fetch_array($res);
            return (bool)$row;
        } catch (Throwable $e) {
            return false;
        }
    }

    // 1) information_schema
    try {
        $sql = "SELECT 1 AS ok
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = " . vx_sql_quote($name) . "
                LIMIT 1";
        $res = vx_schema_query($db, $sql);
        $row = vx_safe_fetch_array($res);
        if ($row) return true;
    } catch (Throwable $e) {
        // ignore and try fallback
    }

    // 2) SHOW TABLES LIKE (no placeholders)
    try {
        $sql = "SHOW TABLES LIKE " . vx_sql_quote($name);
        $res = vx_schema_query($db, $sql);
        $row = vx_safe_fetch_array($res);
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a column exists on a table (MySQL/MariaDB).
 */
function vx_column_exists($db, string $table, string $col): bool {
    $table = trim($table);
    $col   = trim($col);
    if ($table === '' || $col === '' || !$db) return false;

    // SQLite
    if (vx_db_is_sqlite($db)) {
        try {
            $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            if ($tbl === '') return false;
            $res = vx_schema_query($db, "PRAGMA table_info('" . $tbl . "')");
            if (!$res) return false;
            while ($r = vx_safe_fetch_array($res)) {
                if ((string)($r['name'] ?? '') === $col) return true;
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // 1) information_schema
    try {
        $sql = "SELECT 1 AS ok
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = " . vx_sql_quote($table) . "
                  AND column_name = " . vx_sql_quote($col) . "
                LIMIT 1";
        $res = vx_schema_query($db, $sql);
        $row = vx_safe_fetch_array($res);
        if ($row) return true;
    } catch (Throwable $e) {
        // ignore
    }

    // 2) SHOW COLUMNS fallback (no placeholders)
    try {
        // sanitize table name for backticks
        $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($tbl === '') return false;

        $sql = "SHOW COLUMNS FROM `{$tbl}` LIKE " . vx_sql_quote($col);
        $res = vx_schema_query($db, $sql);
        $row = vx_safe_fetch_array($res);
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Back-compat alias some pages expect.
 */
function schema_has_table($db, string $name): bool {
    return vx_table_exists($db, $name);
}
