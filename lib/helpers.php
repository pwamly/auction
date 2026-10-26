<?php
declare(strict_types=1);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Link inside the site. Pages are linked without ".php" (the htdocs .htaccess maps them). */
function url(string $path = ''): string
{
    return APP_BASE . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post_str(string $key, int $maxLength = 5000): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
}

function get_str(string $key, int $maxLength = 200): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
}

function get_int(string $key): ?int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? null : $value;
}

function post_int(string $key): ?int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? null : $value;
}

// ---------------------------------------------------------------- sessions, CSRF, flash

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ndc_auction_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => APP_BASE === '' ? '/' : APP_BASE . '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Stops a POST that didn't come from one of our own forms. */
function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('This form has expired. Go back, refresh the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------------------------------------------------------------- money (TZS)

/** Parses "1,250,000" or "1250000.50" into integer cents, or null if invalid. */
function parse_money(string $raw): ?int
{
    $clean = str_replace([',', ' ', CURRENCY], '', trim($raw));
    if ($clean === '' || !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $clean), 2, '0');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function cents_to_decimal(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function decimal_to_cents($decimal): int
{
    [$whole, $fraction] = array_pad(explode('.', (string) $decimal), 2, '0');
    return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
}

function money($decimal): string
{
    if ($decimal === null) {
        return '—';
    }
    $cents = decimal_to_cents($decimal);
    $digits = $cents % 100 === 0 ? 0 : 2;
    return CURRENCY . ' ' . number_format($cents / 100, $digits);
}

// ---------------------------------------------------------------- time (DB is UTC, display is APP_TIMEZONE)

function utc_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function utc_string(DateTimeImmutable $dt): string
{
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

function from_utc(?string $utc): ?DateTimeImmutable
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(APP_TIMEZONE));
}

function fmt_dt(?string $utc, string $format = 'd M Y, H:i'): string
{
    $dt = from_utc($utc);
    return $dt ? $dt->format($format) : '—';
}

function to_ms(?string $utc): ?int
{
    $dt = from_utc($utc);
    return $dt ? (int) $dt->format('Uv') : null;
}

/** <input type="datetime-local"> value (local time) -> UTC DB string, or null. */
function parse_local_input(string $value): ?string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone(APP_TIMEZONE));
    return $dt ? utc_string($dt) : null;
}

function to_local_input(?string $utc): string
{
    $dt = from_utc($utc);
    return $dt ? $dt->format('Y-m-d\TH:i') : '';
}

function fmt_duration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
}

// ---------------------------------------------------------------- misc

function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body['serverNow'] = (int) floor(microtime(true) * 1000);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Writes rows as a CSV download; cells that look like formulas are neutralised for Excel. */
function send_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($v) {
            $v = $v ?? '';
            return is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        }, $row));
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------- pop-up form round trips

/** Keeps a pop-up form's values and errors for the next page load (after a redirect). */
function stash_form(string $key, array $values, array $errors): void
{
    unset($values['password'], $values['password_confirm'], $values['csrf']);
    $_SESSION['forms'][$key] = ['values' => $values, 'errors' => $errors];
}

/** Returns [values, errors] stashed for a form, once. */
function take_form(string $key): array
{
    $form = $_SESSION['forms'][$key] ?? ['values' => [], 'errors' => []];
    unset($_SESSION['forms'][$key]);
    return [$form['values'], $form['errors']];
}

/** A safe "return to" URL inside this site: the posted return_to, else the referring page, else $fallback. */
function return_url(string $fallback = ''): string
{
    $candidates = [(string) ($_POST['return_to'] ?? ''), (string) ($_SERVER['HTTP_REFERER'] ?? '')];
    foreach ($candidates as $c) {
        $path = parse_url($c, PHP_URL_PATH);
        $host = parse_url($c, PHP_URL_HOST);
        if ($path && str_starts_with($path, APP_BASE . '/') && ($host === null || $host === ($_SERVER['HTTP_HOST'] ?? $host))) {
            parse_str((string) parse_url($c, PHP_URL_QUERY), $query);
            unset($query['modal']);
            return $path . ($query ? '?' . http_build_query($query) : '');
        }
    }
    return url($fallback);
}

/** Redirects back to the page the pop-up came from and reopens that pop-up. */
function back_to_modal(string $modalId, string $fallback = ''): void
{
    $to = return_url($fallback);
    header('Location: ' . $to . (str_contains($to, '?') ? '&' : '?') . 'modal=' . rawurlencode($modalId));
    exit;
}

/** Hidden field telling a pop-up form where to return. */
function return_field(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return '<input type="hidden" name="return_to" value="' . h(preg_replace('/([?&])modal=[^&]*&?/', '$1', $uri)) . '">';
}
