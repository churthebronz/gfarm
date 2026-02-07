<?php
// /core/classes/db.php
if (!defined('FastCore')) { exit('Oops!'); }
// Prevent redeclare fatals if this file is loaded multiple times
if (class_exists('db')) { return; }

class db {
    /**
     * Legacy compatibility flag.
     * Older pages check `$db->ok` to decide if DB is usable.
     */
    public $ok = false;

    protected $connection;
    protected $stmt;
    protected $stmt_open = false;
    public $query_count = 0;
    public $last_error = null;

    public function __construct(
        $dbhost = 'localhost',
        $dbuser = 'root',
        $dbpass = '',
        $dbname = '',
        $charset = 'utf8mb4'
    ) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->connection = @new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        if (!$this->connection || $this->connection->connect_error) {
            $err = $this->connection ? $this->connection->connect_error : 'Unknown connect error';
            $this->last_error = 'Failed to connect to MySQL - ' . $err;
            @error_log('[VX][DB] ' . $this->last_error);
            $this->connection = null;
            $this->ok = false;
            return;
        }
        @$this->connection->set_charset($charset);
        $this->ok = true;
    }

    public function __destruct() {
        if ($this->stmt_open && $this->stmt) {
            $this->stmt->close();
        }
    }

    /**
     * Usage:
     *   $db->query("SELECT * FROM t WHERE id=?", $id)->fetchArray();
     *   $db->query("UPDATE t SET a=? WHERE id=?", $a, $id);
     *   $db->query("SELECT * FROM t")->fetchAll();
     */
    public function query($sql /*, ...$params */) {
        // If DB is down, fail gracefully
        if (!$this->connection) {
            $this->last_error = 'DB connection not available';
            @error_log('[VX][DB] ' . $this->last_error . ' for query: ' . (string)$sql);
            return $this;
        }

        // Close any previous statement
        if ($this->stmt_open && $this->stmt) {
            $this->stmt->close();
            $this->stmt_open = false;
        }

        $this->stmt = $this->connection->prepare($sql);
        if ($this->stmt === false) {
            $this->last_error = 'SQL prepare error: ' . $this->connection->error;
            @error_log('[VX][DB] ' . $this->last_error . ' | SQL: ' . (string)$sql);
            $this->stmt_open = false;
            $this->stmt = null;
            return $this;
        }

        $argc = func_num_args();
        if ($argc > 1) {
            // Flatten variadic / nested array params into a single list
            $raw = array_slice(func_get_args(), 1);
            $params = [];
            foreach ($raw as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $v) { $params[] = $v; }
                } else {
                    $params[] = $arg;
                }
            }

            // Validate placeholder count
            $ph = substr_count($sql, '?');
            if ($ph !== count($params)) {
                $this->last_error = 'SQL bind mismatch: ' . $ph . ' placeholders, ' . count($params) . ' params';
                @error_log('[VX][DB] ' . $this->last_error . ' | SQL: ' . (string)$sql);
                $this->stmt->close();
                $this->stmt_open = false;
                $this->stmt = null;
                return $this;
            }

            // Build types & references
            $types = '';
            foreach ($params as $k => $v) {
                if (is_bool($v) || is_int($v)) {
                    $types .= 'i';
                    $params[$k] = (int)$v;
                } elseif (is_float($v)) {
                    $types .= 'd';
                } elseif (is_null($v)) {
                    $types .= 's'; // bind as string; value NULL is fine
                    $params[$k] = null;
                } else {
                    $types .= 's';
                    $params[$k] = (string)$v;
                }
            }

            // Prepare reference array for bind_param
            $bind = [];
            $bind[] = &$types;
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }

            if (!call_user_func_array([$this->stmt, 'bind_param'], $bind)) {
                $this->last_error = 'SQL bind_param error: ' . $this->stmt->error;
                @error_log('[VX][DB] ' . $this->last_error . ' | SQL: ' . (string)$sql);
                $this->stmt->close();
                $this->stmt_open = false;
                $this->stmt = null;
                return $this;
            }
        } else {
            // No params; if there are placeholders, that's an error
            if (strpos($sql, '?') !== false) {
                $this->last_error = 'SQL placeholders present but no parameters supplied';
                @error_log('[VX][DB] ' . $this->last_error . ' | SQL: ' . (string)$sql);
                $this->stmt->close();
                $this->stmt_open = false;
                $this->stmt = null;
                return $this;
            }
        }

        if (!$this->stmt->execute()) {
            $this->last_error = 'MySQL execution error: ' . $this->stmt->error;
            @error_log('[VX][DB] ' . $this->last_error . ' | SQL: ' . (string)$sql);
            $this->stmt->close();
            $this->stmt_open = false;
            $this->stmt = null;
            return $this;
        }

        $this->stmt_open = true;
        $this->query_count++;
        return $this;
    }

    public function fetchAll() {
        if (!$this->_ensureStmt('fetchAll')) { return []; }
        $meta = $this->stmt->result_metadata();
        if (!$meta) {
            // Not a SELECT
            $this->stmt->close();
            $this->stmt_open = false;
            return [];
        }

        $row = [];
        $bind = [];
        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }
        call_user_func_array([$this->stmt, 'bind_result'], $bind);

        $out = [];
        while ($this->stmt->fetch()) {
            // copy-by-value to avoid reference reuse
            $out[] = array_map(fn($v) => $v, $row);
        }

        $this->stmt->close();
        $this->stmt_open = false;
        return $out;
    }

    public function fetchArray() {
        if (!$this->_ensureStmt('fetchArray')) { return []; }
        $meta = $this->stmt->result_metadata();
        if (!$meta) {
            $this->stmt->close();
            $this->stmt_open = false;
            return [];
        }

        $row = [];
        $bind = [];
        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }
        call_user_func_array([$this->stmt, 'bind_result'], $bind);

        $res = [];
        if ($this->stmt->fetch()) {
            foreach ($row as $k => $v) { $res[$k] = $v; }
        }

        $this->stmt->close();
        $this->stmt_open = false;
        return $res;
    }

    public function numRows() {
        if (!$this->_ensureStmt('numRows')) { return 0; }
        $this->stmt->store_result();
        $n = $this->stmt->num_rows;
        // do not close here; allow caller to fetch after numRows()
        return $n;
    }

    /**
     * Affected rows for the last prepared statement.
     * Useful for optimistic concurrency and atomic updates.
     */
    public function affectedRows(): int {
        if (!$this->_ensureStmt('affectedRows')) { return 0; }
        return (int)$this->stmt->affected_rows;
    }

    /**
     * Execute a raw SQL statement (DDL/DML) without binding.
     *
     * Some helper modules (e.g. seasons.php) use $db->exec() to create
     * tables or run simple updates. The original wrapper only provided
     * query(), so those calls would throw and get caught, silently
     * skipping the operation.
     */
    public function exec($sql) {
        if (!$this->connection) {
            return false;
        }
        $sql = (string)$sql;
        if ($sql === '') {
            return false;
        }
        try {
            $res = $this->connection->query($sql);
            return $res !== false;
        } catch (Throwable $e) {
            // Don't fatal the app on DDL helpers; just fail gracefully.
            return false;
        }
    }

    public function lastInsert() {
        return $this->connection->insert_id;
    }

    private function _ensureStmt($fn): bool {
        if (!$this->stmt_open || !$this->stmt) {
            $this->last_error = 'No valid query executed before '.(string)$fn.'()';
            return false;
        }
        return true;
    }
}
