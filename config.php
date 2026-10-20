<?php
/**
 * Site configuration. Environment variables (LOTLINE_*) override the defaults,
 * so production secrets don't have to live in this file.
 */

function env_or(string $name, $default)
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

define('APP_NAME', env_or('LOTLINE_APP_NAME', 'Lotline'));
define('APP_ORG', env_or('LOTLINE_APP_ORG', 'NDC'));
// Public base URL used in emails and QR codes, without a trailing slash.
define('APP_URL', rtrim(env_or('LOTLINE_APP_URL', 'http://localhost/auction'), '/'));
// Path prefix for links inside the site.
define('APP_BASE', rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/'));
define('APP_TIMEZONE', 'Africa/Dar_es_Salaam');
define('CURRENCY', 'TZS');

// ---- Database ----
define('DB_HOST', env_or('LOTLINE_DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env_or('LOTLINE_DB_PORT', 3306));
define('DB_NAME', env_or('LOTLINE_DB_NAME', 'ndc_auction'));
define('DB_USER', env_or('LOTLINE_DB_USER', 'root'));
define('DB_PASS', env_or('LOTLINE_DB_PASS', ''));

// ---- Auction rules (Requirements_doc.docx section D/E/H) ----
const DEFAULT_MIN_INCREMENT = 5;          // Q11
const ANTI_SNIPING_WINDOW_MIN = 20;       // Q16: a bid this close to closing...
const ANTI_SNIPING_EXTENSION_MIN = 20;    // ...pushes closing to bid time + this
const PAYMENT_WINDOW_HOURS = 48;          // Q23

// ---- Uploads (Q1 photos & supporting documents) ----
const MAX_PHOTOS = 10;
const MAX_DOCUMENTS = 10;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const DOCUMENT_TYPES = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/msword' => 'doc',
    'application/vnd.ms-excel' => 'xls',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

// ---- Email (Q21) ----
// 'log' writes emails to storage/mail/ instead of sending (development).
// 'smtp' sends through the server below.
define('MAIL_TRANSPORT', env_or('LOTLINE_MAIL_TRANSPORT', 'log'));
define('MAIL_FROM', env_or('LOTLINE_MAIL_FROM', 'auctions@example.com'));
define('MAIL_FROM_NAME', env_or('LOTLINE_MAIL_FROM_NAME', APP_ORG . ' Online Auction'));
define('SMTP_HOST', env_or('LOTLINE_SMTP_HOST', ''));
define('SMTP_PORT', (int) env_or('LOTLINE_SMTP_PORT', 587));
define('SMTP_SECURE', env_or('LOTLINE_SMTP_SECURE', 'tls')); // tls (STARTTLS), ssl, or none
define('SMTP_USER', env_or('LOTLINE_SMTP_USER', ''));
define('SMTP_PASS', env_or('LOTLINE_SMTP_PASS', ''));

// ---- Security ----
const PASSWORD_MIN_LENGTH = 8;
const LOGIN_MAX_FAILURES = 5;      // per email or IP...
const LOGIN_LOCKOUT_MINUTES = 15;  // ...within this window
