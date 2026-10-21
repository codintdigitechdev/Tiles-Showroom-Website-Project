<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');

/* ---- Database layer (mysqli, same driver as the other projects; exposes a small PDO-like API) ---- */
class DbStmt {
    private $res;
    public function __construct($res) { $this->res = $res; }
    public function fetchAll(): array { return $this->res ? $this->res->fetch_all(MYSQLI_ASSOC) : []; }
    public function fetch() { return $this->res ? ($this->res->fetch_assoc() ?: false) : false; }
    public function fetchColumn() { if (!$this->res) return false; $r = $this->res->fetch_row(); return $r ? $r[0] : false; }
}
class Db {
    public mysqli $c;
    public function __construct(mysqli $c) { $this->c = $c; }
    public function beginTransaction(): void { $this->c->begin_transaction(); }
    public function commit(): void { $this->c->commit(); }
    public function rollBack(): void { $this->c->rollback(); }
    public function lastInsertId(): int { return (int)$this->c->insert_id; }
}
function db_error(mysqli_sql_exception $e): PDOException {
    $p = new PDOException($e->getMessage());
    $p->errorInfo = ['HY000', (int)$e->getCode(), $e->getMessage()];
    return $p;
}
function db(): Db {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $c->set_charset('utf8mb4');
            $db = new Db($c);
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            $code = (int)$e->getCode();
            $hint = $code === 1049 ? 'The database "' . DB_NAME . '" does not exist yet: import database/shree_enterprises.sql in phpMyAdmin.'
                  : ($code === 1045 ? 'Wrong DB_USER / DB_PASS in config.php.'
                  : 'Is MySQL running in the XAMPP Control Panel? Check DB_HOST in config.php.');
            die('<div style="font-family:sans-serif;max-width:640px;margin:40px auto"><h3>Database connection failed</h3><p>' . $hint .
                '</p><p style="font-size:13px">Config file in use: <code>' . htmlspecialchars(realpath(__DIR__ . '/../config.php')) . '</code><br>User: <code>' .
                htmlspecialchars(DB_USER) . '</code> · Password set in config: <b>' . (DB_PASS === '' ? 'NO (empty)' : 'yes (' . strlen(DB_PASS) . ' characters)') .
                '</b></p><p style="color:#777;font-size:13px">Technical detail: ' . htmlspecialchars($e->getMessage()) . '</p></div>');
        }
    }
    return $db;
}

function q(string $sql, array $params = []): DbStmt {
    try {
        $st = db()->c->prepare($sql);
        if ($params) {
            $params = array_values($params);
            $st->bind_param(str_repeat('s', count($params)), ...$params);
        }
        $st->execute();
        $res = $st->get_result();
        return new DbStmt($res ?: null);
    } catch (mysqli_sql_exception $e) {
        throw db_error($e);
    }
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return '₹ ' . number_format((float)$n, 2); }
function dmy($d): string { return $d ? date('d-m-Y', strtotime($d)) : ''; }
function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    return $f;
}

// ---- auth
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return (user()['role'] ?? '') === 'admin'; }
function require_login(): void { if (!user()) redirect('login.php'); }
function require_admin(): void {
    require_login();
    if (!is_admin()) { flash('Admin access required.', 'err'); redirect('dashboard.php'); }
}

// ---- CSRF
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('Invalid request token. Go back and reload the page.');
    }
}

// ---- billing math (same rule as the original system: subtotal - discount%, then + GST%)
function bill_total(float $subtotal, float $disc, float $gst): float {
    return round($subtotal * (1 - $disc / 100) * (1 + $gst / 100), 2);
}

const PAY_MODES = ['Cash', 'Online', 'Card', 'Cheque'];

// ---- validation helpers (server side). They throw RuntimeException with a user-friendly message.
function v_mobile($v, string $label, bool $required = true): ?string {
    $v = trim((string)$v);
    if ($v === '') { if ($required) throw new RuntimeException("$label is required."); return null; }
    if (!preg_match('/^\d+$/', $v)) throw new RuntimeException("$label must contain digits only.");
    if (strlen($v) !== 10) throw new RuntimeException("$label must be exactly 10 digits.");
    if (!preg_match('/^[6-9]/', $v)) throw new RuntimeException("$label must start with 6, 7, 8 or 9.");
    return $v;
}
function v_name($v, string $label, bool $required = true, int $max = 80): ?string {
    $v = trim(preg_replace('/\s+/', ' ', (string)$v));
    if ($v === '') { if ($required) throw new RuntimeException("$label is required."); return null; }
    if (mb_strlen($v) < 2) throw new RuntimeException("$label is too short.");
    if (mb_strlen($v) > $max) throw new RuntimeException("$label must be at most $max characters.");
    if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]*$/u', $v)) throw new RuntimeException("$label may contain letters, spaces and . ' - only (no digits or symbols).");
    return $v;
}
function v_num($v, string $label, float $min = 0, float $max = 9999999.99, bool $int = false, bool $required = true): ?float {
    $s = trim((string)$v);
    if ($s === '') { if ($required) throw new RuntimeException("$label is required."); return null; }
    if (!preg_match($int ? '/^\d+$/' : '/^\d+(\.\d{1,2})?$/', $s)) {
        throw new RuntimeException($int ? "$label must be a whole number (digits only)." : "$label must be a valid amount (numbers only, max 2 decimals).");
    }
    $n = (float)$s;
    if ($n < $min) throw new RuntimeException("$label must be at least " . $min . '.');
    if ($n > $max) throw new RuntimeException("$label must not be more than " . $max . '.');
    return $n;
}
function v_date($v, string $label, bool $allowFuture = true, bool $required = true): ?string {
    $s = trim((string)$v);
    if ($s === '') { if ($required) throw new RuntimeException("$label is required."); return null; }
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || (int)$d->format('Y') < 2000) throw new RuntimeException("$label is not a valid date.");
    if (!$allowFuture && $s > date('Y-m-d')) throw new RuntimeException("$label cannot be in the future.");
    return $s;
}
function v_text($v, string $label, int $max = 150, bool $required = false): ?string {
    $v = trim((string)$v);
    if ($v === '') { if ($required) throw new RuntimeException("$label is required."); return null; }
    if (mb_strlen($v) > $max) throw new RuntimeException("$label must be at most $max characters.");
    return $v;
}

// ---- extra charges: labour / transport (added after GST, not discounted)
function bill_grand_total(float $subtotal, float $disc, float $gst, float $labour = 0, float $transport = 0): float {
    return round(bill_total($subtotal, $disc, $gst) + $labour + $transport, 2);
}

// ---- branding
function shop_logo(int $h = 70): string { return '<img src="assets/img/logo.png" alt="' . e(SHOP_NAME) . '" style="height:' . $h . 'px;width:' . $h . 'px;vertical-align:middle">'; }
function shop_head(): string {
    return '<div class="ph"><div class="phi">' . shop_logo(78) . '<div><h2>' . e(SHOP_NAME) . '</h2><b class="tag">' . e(SHOP_TAGLINE) . '</b></div></div><small>' . e(SHOP_ADDRESS) . ' · Mob: ' . e(SHOP_PHONE) . '</small></div>';
}
