<?php
declare(strict_types=1);

/** Time-based one-time passwords (RFC 6238) for staff two-factor authentication (Q32). */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_generate_secret(): string
{
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $secret = '';
    foreach (str_split($bits, 5) as $chunk) {
        $secret .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $secret;
}

function base32_decode_secret(string $secret): string
{
    $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $char) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $bytes .= chr(bindec($chunk));
        }
    }
    return $bytes;
}

function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode_secret($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Returns the matching time step, or null. Accepts one step of clock drift either way
 * and rejects any step at or before $lastUsedStep so a code can't be replayed.
 */
function totp_verify(string $secret, string $code, ?int $lastUsedStep = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS) {
        return null;
    }
    $current = intdiv(time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $drift) {
        $step = $current + $drift;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secret, string $accountEmail): string
{
    $issuer = APP_NAME;
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountEmail)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
