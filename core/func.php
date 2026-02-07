<?php
if (!defined('FastCore')) { exit('Oops!'); }

class func {

    # ============================
    # Калькулятор сбора прибыли
    # ============================
    public function SumCalc($per_h, $sum_tree, $last_sbor) {
        if ($last_sbor <= 0 || $sum_tree <= 0 || $per_h <= 0) {
            return 0;
        }
        $elapsed = ($last_sbor < time()) ? (time() - $last_sbor) : 0;
        $per_sec = $per_h;
        return round(($per_sec / 3600) * $elapsed, 6);
    }

    # ============================
    # Получаем IP
    # ============================
    public function ipGet() {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    # ============================
    # Версия IP
    # ============================
    public function ipValid($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : false;
    }

    # ============================
    # Фильтрация Логина (fix nested ternary)
    # ============================
    public function FLogin($login, $mask = "^[а-яА-ЯЁёa-zA-Z0-9_]", $len = "{4,50}") {
        if (is_array($login)) {
            return false;
        }
        $pattern = "/{$mask}{$len}$/u";  // e.g. ^[chars]{4,50}$
        return preg_match($pattern, $login) ? $login : false;
    }

    # ============================
    # Фильтрация Пароля
    # ============================
    public function FPass(string $pass): ?string {
        $length = strlen($pass);
        if ($length < 6 || $length > 55) {
            return null;
        }
        return $pass;
    }

    # ============================
    # Фильтрация Почты (fix broken logic)
    # ============================
    public function FMail($email) {
        if (!is_string($email)) {
            return false;
        }
        $email = trim($email);
        if (strlen($email) > 255) {
            return false;
        }
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL);
        return $valid ? strtolower($email) : false;
    }

    # ============================
    # Фильтрация URL
    # ============================
    public function validUrl($url) {
        $check_url = filter_var($url, FILTER_SANITIZE_URL);
        return filter_var($check_url, FILTER_VALIDATE_URL) ? $check_url : false;
    }

    # ============================
    # Генерация csrf (stronger token)
    # ============================
    public function csrf() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        // random token; echo hidden field
        $_SESSION['csrf'] = bin2hex(random_bytes(32)); // WHY: avoid predictable time-based tokens
        echo '<input type="hidden" name="@secury" value="' .
             htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') . '">';
    }

    /*
     * Verify CSRF input
     */
    public function csrfVerify() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $value = $_POST['@secury'] ?? '';
        $ok = isset($_SESSION['csrf']) && is_string($value) && hash_equals($_SESSION['csrf'], $value);
        unset($_SESSION['csrf']); // WHY: one-time token
        return $ok ? 'true' : 'false';
    }
}
?>
