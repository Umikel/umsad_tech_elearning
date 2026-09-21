<?php
/**
 * Umsad Tech E-Learning Platform
 * Application configuration and lightweight environment loading.
 */

if (!function_exists('umsadLoadEnvironment')) {
    /**
     * Load KEY=value pairs without replacing real process/server variables.
     */
    function umsadLoadEnvironment(string $file): void
    {
        if (!is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            if (getenv($key) !== false || array_key_exists($key, $_SERVER) || array_key_exists($key, $_ENV)) {
                continue;
            }

            $value = trim(substr($line, $separator + 1));
            $length = strlen($value);
            if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") {
                $value = substr($value, 1, -1);
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('umsadEnv')) {
    /** @return mixed */
    function umsadEnv(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        return $default;
    }
}

if (!function_exists('umsadEnvBool')) {
    function umsadEnvBool(string $key, bool $default = false): bool
    {
        $value = umsadEnv($key);
        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}

if (!function_exists('umsadNormalizeAppUrl')) {
    function umsadNormalizeAppUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\r\n]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!preg_match('/^(?:localhost|(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?))$/i', $host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = $parts['path'] ?? '';
        if ($path !== '' && (!str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '..'))) {
            return null;
        }

        $path = $path === '/' ? '' : rtrim($path, '/');
        return $scheme . '://' . $host . $port . $path;
    }
}

define('APP_ROOT', dirname(__DIR__));
umsadLoadEnvironment(APP_ROOT . DIRECTORY_SEPARATOR . '.env');

// Database configuration.
$databaseHost = trim((string) umsadEnv('DB_HOST', 'localhost'));
if ($databaseHost === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $databaseHost)) {
    $databaseHost = 'localhost';
}
$databaseName = trim((string) umsadEnv('DB_NAME', 'umsad_tech_elearning'));
if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
    $databaseName = 'umsad_tech_elearning';
}
define('DB_HOST', $databaseHost);
define('DB_PORT', max(1, min(65535, (int) umsadEnv('DB_PORT', 3306))));
define('DB_USER', (string) umsadEnv('DB_USER', 'root'));
define('DB_PASS', (string) umsadEnv('DB_PASS', ''));
define('DB_NAME', $databaseName);
$databaseSocket = trim((string) umsadEnv('DB_SOCKET', ''));
if ($databaseSocket !== '' && (
    !str_starts_with($databaseSocket, '/')
    || preg_match('/[;\r\n\0]/', $databaseSocket)
)) {
    $databaseSocket = '';
}
define('DB_SOCKET', $databaseSocket);

// Application configuration. APP_URL must be an absolute http(s) URL.
$environment = strtolower((string) umsadEnv('APP_ENV', 'production'));
if (!in_array($environment, ['development', 'testing', 'production'], true)) {
    $environment = 'production';
}

$fallbackPath = '/' . rawurlencode(basename(APP_ROOT));
$appUrl = umsadNormalizeAppUrl((string) umsadEnv('APP_URL', ''))
    ?? 'http://localhost' . $fallbackPath;
$appUrlParts = parse_url($appUrl);
$appBasePath = is_array($appUrlParts) ? ($appUrlParts['path'] ?? '') : '';

define('APP_NAME', trim((string) umsadEnv('APP_NAME', 'Umsad Tech E-Learning')) ?: 'Umsad Tech E-Learning');
define('APP_URL', $appUrl);
define('APP_BASE_PATH', $appBasePath === '/' ? '' : rtrim($appBasePath, '/'));
define('APP_ENV', $environment);

// Session configuration.
$sessionLifetime = (int) umsadEnv('SESSION_LIFETIME', 86400);
$sessionLifetime = max(300, min($sessionLifetime, 2592000));
$sessionName = (string) umsadEnv('SESSION_COOKIE_NAME', 'UMSADSESSID');
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,47}$/', $sessionName)) {
    $sessionName = 'UMSADSESSID';
}
$cookiePath = APP_BASE_PATH !== '' ? APP_BASE_PATH : '/';
$defaultSecureCookie = str_starts_with(APP_URL, 'https://');

define('SESSION_LIFETIME', $sessionLifetime);
define('SESSION_COOKIE_NAME', $sessionName);
define('SESSION_COOKIE_PATH', $cookiePath);
define('SESSION_COOKIE_SECURE', umsadEnvBool('SESSION_COOKIE_SECURE', $defaultSecureCookie));
define('SESSION_COOKIE_SAMESITE', 'Lax');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', SESSION_COOKIE_SECURE ? '1' : '0');
    ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);
    session_name(SESSION_COOKIE_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => SESSION_COOKIE_PATH,
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => SESSION_COOKIE_SAMESITE,
    ]);
}

// Security and payment configuration.
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_HASH_OPTIONS', ['cost' => max(10, min(14, (int) umsadEnv('PASSWORD_COST', 12)))]);
$paystackPublicKey = trim((string) umsadEnv('PAYSTACK_PUBLIC_KEY', ''));
$paystackSecretKey = trim((string) umsadEnv('PAYSTACK_SECRET_KEY', ''));
$paystackPublicMode = preg_match('/^pk_(test|live)_[A-Za-z0-9]{20,}$/', $paystackPublicKey, $publicKeyMatch)
    ? $publicKeyMatch[1]
    : null;
$paystackSecretMode = preg_match('/^sk_(test|live)_[A-Za-z0-9]{20,}$/', $paystackSecretKey, $secretKeyMatch)
    ? $secretKeyMatch[1]
    : null;
$paystackMode = $paystackPublicMode !== null && $paystackPublicMode === $paystackSecretMode
    ? $paystackPublicMode
    : null;

define('PAYSTACK_PUBLIC_KEY', $paystackPublicKey);
define('PAYSTACK_SECRET_KEY', $paystackSecretKey);
define('PAYSTACK_MODE', $paystackMode ?? 'unconfigured');
define('PAYSTACK_CONFIGURED', $paystackMode !== null);
define('PAYSTACK_IS_LIVE', $paystackMode === 'live');
define('PAYSTACK_CURRENCY', 'NGN');

// Authenticated SMTP and email-verification configuration.
// Invalid values are retained only as safe fallbacks; MAIL_CONFIGURED remains
// false unless the complete enabled configuration passes every check.
$mailEnabled = umsadEnvBool('MAIL_ENABLED', false);
$mailHostInput = trim((string) umsadEnv('MAIL_HOST', ''));
$mailHostIsValid = $mailHostInput !== ''
    && strlen($mailHostInput) <= 253
    && !preg_match('/[\x00-\x20\x7f]/', $mailHostInput)
    && !str_contains($mailHostInput, ':')
    && !str_contains($mailHostInput, '/')
    && !str_contains($mailHostInput, '\\')
    && ($mailHostInput === 'localhost'
        || filter_var($mailHostInput, FILTER_VALIDATE_IP) !== false
        || filter_var($mailHostInput, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
$mailHost = $mailHostIsValid ? strtolower($mailHostInput) : '';

$mailEncryptionInput = strtolower(trim((string) umsadEnv('MAIL_ENCRYPTION', 'tls')));
$mailEncryptionIsValid = in_array($mailEncryptionInput, ['tls', 'smtps'], true);
$mailEncryption = $mailEncryptionIsValid ? $mailEncryptionInput : 'tls';
$mailPortInput = filter_var(umsadEnv('MAIL_PORT', $mailEncryption === 'smtps' ? 465 : 587), FILTER_VALIDATE_INT);
$mailPortIsValid = $mailPortInput !== false && $mailPortInput >= 1 && $mailPortInput <= 65535;
$mailPort = $mailPortIsValid ? (int) $mailPortInput : ($mailEncryption === 'smtps' ? 465 : 587);
$mailPortMatchesEncryption = ($mailEncryption === 'smtps' && $mailPort === 465)
    || ($mailEncryption === 'tls' && in_array($mailPort, [25, 587, 2525], true));

$mailUsernameInput = trim((string) umsadEnv('MAIL_USERNAME', ''));
if ($mailUsernameInput === '') {
    $mailUsernameInput = trim((string) umsadEnv('MAIL_USER', ''));
}
$mailUsernameIsValid = $mailUsernameInput !== ''
    && strlen($mailUsernameInput) <= 255
    && !preg_match('/[\r\n\x00]/', $mailUsernameInput);
$mailUsername = $mailUsernameIsValid ? $mailUsernameInput : '';

$mailPassword = (string) umsadEnv('MAIL_PASSWORD', '');
if ($mailPassword === '') {
    $mailPassword = (string) umsadEnv('MAIL_PASS', '');
}
$mailPasswordIsValid = $mailPassword !== '' && !preg_match('/[\r\n\x00]/', $mailPassword);

$mailFromInput = trim((string) umsadEnv('MAIL_FROM_ADDRESS', ''));
if ($mailFromInput === '') {
    $mailFromInput = trim((string) umsadEnv('MAIL_FROM', ''));
}
$mailFromIsValid = filter_var($mailFromInput, FILTER_VALIDATE_EMAIL) !== false
    && strlen($mailFromInput) <= 255
    && !preg_match('/[\r\n]/', $mailFromInput);
$mailFromAddress = $mailFromIsValid ? $mailFromInput : '';

$mailFromNameInput = trim((string) umsadEnv('MAIL_FROM_NAME', APP_NAME));
$mailFromNameIsValid = $mailFromNameInput !== ''
    && strlen($mailFromNameInput) <= 120
    && !preg_match('/[\r\n\x00]/', $mailFromNameInput);
$mailFromName = $mailFromNameIsValid ? $mailFromNameInput : APP_NAME;

$mailReplyToInput = trim((string) umsadEnv('MAIL_REPLY_TO_ADDRESS', ''));
$mailReplyToIsValid = $mailReplyToInput === ''
    || (filter_var($mailReplyToInput, FILTER_VALIDATE_EMAIL) !== false
        && strlen($mailReplyToInput) <= 255
        && !preg_match('/[\r\n]/', $mailReplyToInput));
$mailReplyToAddress = $mailReplyToIsValid ? $mailReplyToInput : '';

$mailTimeoutInput = filter_var(umsadEnv('MAIL_TIMEOUT', 10), FILTER_VALIDATE_INT);
$mailTimeout = $mailTimeoutInput === false ? 10 : max(5, min(30, (int) $mailTimeoutInput));
$mailRuntimeIsReady = is_file(APP_ROOT . '/vendor/autoload.php')
    && (APP_ENV !== 'production' || str_starts_with(APP_URL, 'https://'));

define('MAIL_ENABLED', $mailEnabled);
define('MAIL_HOST', $mailHost);
define('MAIL_PORT', $mailPort);
define('MAIL_USERNAME', $mailUsername);
define('MAIL_PASSWORD', $mailPassword);
define('MAIL_ENCRYPTION', $mailEncryption);
define('MAIL_FROM_ADDRESS', $mailFromAddress);
define('MAIL_FROM_NAME', $mailFromName);
define('MAIL_REPLY_TO_ADDRESS', $mailReplyToAddress);
define('MAIL_TIMEOUT', $mailTimeout);
define('MAIL_CONFIGURED', $mailEnabled
    && $mailHostIsValid
    && $mailPortIsValid
    && $mailEncryptionIsValid
    && $mailPortMatchesEncryption
    && $mailUsernameIsValid
    && $mailPasswordIsValid
    && $mailFromIsValid
    && $mailFromNameIsValid
    && $mailReplyToIsValid
    && $mailRuntimeIsReady);

$emailVerificationTtlInput = filter_var(umsadEnv('EMAIL_VERIFICATION_TTL', 86400), FILTER_VALIDATE_INT);
$emailVerificationCooldownInput = filter_var(umsadEnv('EMAIL_VERIFICATION_RESEND_COOLDOWN', 60), FILTER_VALIDATE_INT);
$emailVerificationMaxInput = filter_var(umsadEnv('EMAIL_VERIFICATION_MAX_PER_HOUR', 5), FILTER_VALIDATE_INT);

define('EMAIL_VERIFICATION_TTL', $emailVerificationTtlInput === false
    ? 86400
    : max(3600, min(604800, (int) $emailVerificationTtlInput)));
define('EMAIL_VERIFICATION_RESEND_COOLDOWN', $emailVerificationCooldownInput === false
    ? 60
    : max(30, min(3600, (int) $emailVerificationCooldownInput)));
define('EMAIL_VERIFICATION_MAX_PER_HOUR', $emailVerificationMaxInput === false
    ? 5
    : max(1, min(10, (int) $emailVerificationMaxInput)));

$passwordResetTtlInput = filter_var(umsadEnv('PASSWORD_RESET_TTL', 3600), FILTER_VALIDATE_INT);
$passwordResetCooldownInput = filter_var(umsadEnv('PASSWORD_RESET_COOLDOWN', 60), FILTER_VALIDATE_INT);
$passwordResetMaxInput = filter_var(umsadEnv('PASSWORD_RESET_MAX_PER_HOUR', 5), FILTER_VALIDATE_INT);

define('PASSWORD_RESET_TTL', $passwordResetTtlInput === false
    ? 3600
    : max(900, min(86400, (int) $passwordResetTtlInput)));
define('PASSWORD_RESET_COOLDOWN', $passwordResetCooldownInput === false
    ? 60
    : max(30, min(3600, (int) $passwordResetCooldownInput)));
define('PASSWORD_RESET_MAX_PER_HOUR', $passwordResetMaxInput === false
    ? 5
    : max(1, min(10, (int) $passwordResetMaxInput)));

$timezone = (string) umsadEnv('TIMEZONE', 'Africa/Lagos');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Africa/Lagos';
}
date_default_timezone_set($timezone);

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
