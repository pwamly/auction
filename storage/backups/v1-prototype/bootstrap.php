<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const USERNAME_PATTERN = '/^[a-z0-9_-]{3,32}$/';
const PASSWORD_MIN_LENGTH = 8;

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // All timestamps are UTC so NOW() and UNIX_TIMESTAMP() agree with the stored end times.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('lotline_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/** @return array{id:int,username:string,role:string}|null */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function server_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function normalize_username(string $raw): string
{
    return strtolower(trim($raw));
}

/** Converts a user-supplied money value to integer cents, or null if invalid. */
function to_cents($value): ?int
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if (!is_numeric($value)) {
        return null;
    }
    $cents = (int) round(((float) $value) * 100);
    // DECIMAL(12,2) upper bound.
    if ($cents < 0 || $cents > 999999999999) {
        return null;
    }
    return $cents;
}

function cents_to_decimal(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function decimal_to_cents(string $decimal): int
{
    return (int) round(((float) $decimal) * 100);
}
