<?php
/**
 * Helper Functions
 */

/**
 * Sanitize input
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }

    if ($data === null) {
        return '';
    }

    return htmlspecialchars(trim((string) $data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Redirect to URL
 */
function appUrl(string $path = ''): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return APP_URL;
    }

    return APP_URL . '/' . ltrim($path, '/');
}

/**
 * Return a normalized Paystack reference or an empty string when invalid.
 */
function paymentReference($value): string {
    if (!is_string($value)) {
        return '';
    }

    $reference = trim((string) $value);

    if ($reference === ''
        || strlen($reference) > 100
        || preg_match('/^[A-Za-z0-9._=-]+$/', $reference) !== 1) {
        return '';
    }

    return $reference;
}

/**
 * Prevent exported text from being interpreted as a spreadsheet formula.
 */
function spreadsheetSafeCell($value): string {
    $cell = (string) ($value ?? '');

    if (preg_match('/^(?:[=+\-@\t\r\n]|[\x00-\x20]+[=+\-@])/', $cell) === 1) {
        return "'" . $cell;
    }

    return $cell;
}

/**
 * Redirect only within this application.
 */
function redirect($path, int $statusCode = 302) {
    $path = trim((string) $path);
    $location = appUrl($path);

    if ($path === APP_URL || str_starts_with($path, APP_URL . '/')) {
        $location = $path;
    }

    if (preg_match('/[\r\n]/', $location)) {
        $location = APP_URL;
    }

    header('Location: ' . $location, true, $statusCode);
    exit;
}

/**
 * Format date
 */
function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    $numericAmount = (float) $amount;
    $decimals = abs($numericAmount - round($numericAmount)) < 0.00001 ? 0 : 2;

    return '₦' . number_format($numericAmount, $decimals);
}

/**
 * Generate random string
 */
function generateRandomString($length = 32) {
    $length = max(1, (int) $length);
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}

/**
 * Check if field has error
 */
function hasError($field, $errors = []) {
    return isset($errors[$field]);
}

/**
 * Get error message
 */
function getError($field, $errors = []) {
    return $errors[$field] ?? '';
}

/**
 * Validate file upload
 */
function validateUpload($file, $maxSize = 5242880, $allowedTypes = ['jpeg', 'jpg', 'png', 'pdf']) {
    if (!is_array($file)
        || !isset($file['error'], $file['size'], $file['name'], $file['tmp_name'])
        || (int) $file['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    $fileSize = (int) $file['size'];
    if ($fileSize <= 0 || $fileSize > max(1, (int) $maxSize)) {
        return ['success' => false, 'message' => 'File size exceeds limit'];
    }

    $mimeMap = [
        'jpeg' => ['image/jpeg'],
        'jpg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
    ];
    $allowedTypes = array_values(array_unique(array_map('strtolower', $allowedTypes)));
    $fileType = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileType, $allowedTypes, true) || !isset($mimeMap[$fileType])) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $fileInfo->file((string) $file['tmp_name']);
    if (!is_string($detectedMime) || !in_array($detectedMime, $mimeMap[$fileType], true)) {
        return ['success' => false, 'message' => 'File content does not match its extension'];
    }

    return [
        'success' => true,
        'message' => 'File is valid',
        'extension' => $fileType,
        'mime_type' => $detectedMime,
    ];
}

/**
 * Upload file
 */
function uploadFile($file, $uploadDir = 'uploads/') {
    $validation = validateUpload($file);
    if (!$validation['success']) {
        return $validation;
    }

    $root = realpath(APP_ROOT);
    if ($root === false) {
        return ['success' => false, 'message' => 'Upload storage is unavailable'];
    }

    $uploadDir = str_replace('\\', '/', trim((string) $uploadDir));
    if ($uploadDir === '' || str_contains($uploadDir, "\0")) {
        return ['success' => false, 'message' => 'Invalid upload directory'];
    }

    $isAbsolute = str_starts_with($uploadDir, '/');
    $segments = explode('/', $uploadDir);
    foreach ($segments as $segment) {
        if ($segment === '..') {
            return ['success' => false, 'message' => 'Invalid upload directory'];
        }
    }

    $targetDir = $isAbsolute
        ? rtrim($uploadDir, '/')
        : $root . '/' . trim($uploadDir, '/');
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($targetDir !== $root && !str_starts_with($targetDir . DIRECTORY_SEPARATOR, $rootPrefix)) {
        return ['success' => false, 'message' => 'Invalid upload directory'];
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return ['success' => false, 'message' => 'Upload storage is unavailable'];
    }

    $resolvedDir = realpath($targetDir);
    if ($resolvedDir === false
        || ($resolvedDir !== $root && !str_starts_with($resolvedDir . DIRECTORY_SEPARATOR, $rootPrefix))) {
        return ['success' => false, 'message' => 'Invalid upload directory'];
    }

    $fileName = generateRandomString(32) . '.' . strtolower($validation['extension']);
    $filePath = $resolvedDir . DIRECTORY_SEPARATOR . $fileName;

    if (move_uploaded_file((string) $file['tmp_name'], $filePath)) {
        chmod($filePath, 0644);
        $relativePath = ltrim(substr($filePath, strlen($root)), DIRECTORY_SEPARATOR);
        return [
            'success' => true,
            'message' => 'File uploaded successfully',
            'file' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
        ];
    } else {
        return ['success' => false, 'message' => 'Failed to upload file'];
    }
}

/**
 * Get base64 encoded file
 */
function getFileBase64($filePath) {
    if (file_exists($filePath)) {
        return base64_encode(file_get_contents($filePath));
    }
    return null;
}

/**
 * JSON response
 */
function jsonResponse($success, $message, $data = [], ?int $statusCode = null) {
    if ($statusCode === null) {
        $statusCode = $success ? 200 : 400;
    }

    http_response_code(max(100, min(599, $statusCode)));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $payload = json_encode([
        'success' => (bool) $success,
        'message' => (string) $message,
        'data' => is_array($data) ? $data : [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        http_response_code(500);
        $payload = '{"success":false,"message":"Unable to encode the response.","data":{}}';
    }

    echo $payload;
    exit;
}

/**
 * Decode and cache a JSON request body. Malformed JSON receives a 400 response.
 */
function requestJson(): array {
    static $loaded = false;
    static $payload = [];

    if ($loaded) {
        return $payload;
    }
    $loaded = true;

    $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > 1048576) {
        jsonResponse(false, 'Request body is too large.', [], 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $payload;
    }

    if (strlen($raw) > 1048576) {
        jsonResponse(false, 'Request body is too large.', [], 413);
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        jsonResponse(false, 'Malformed JSON request body.', [], 400);
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        jsonResponse(false, 'JSON request body must be an object.', [], 400);
    }

    $payload = $decoded;
    return $payload;
}

/**
 * Make sure a session exists before reading or creating a CSRF token.
 */
function ensureSecuritySession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $started = session_start([
            'use_only_cookies' => 1,
            'use_strict_mode' => 1,
            'cookie_lifetime' => 0,
            'cookie_path' => SESSION_COOKIE_PATH,
            'cookie_secure' => SESSION_COOKIE_SECURE,
            'cookie_httponly' => true,
            'cookie_samesite' => SESSION_COOKIE_SAMESITE,
        ]);

        if (!$started) {
            throw new RuntimeException('Unable to start a secure session.');
        }
    }
}

/**
 * Get the current session's CSRF token.
 */
function csrfToken(): string {
    ensureSecuritySession();

    if (!isset($_SESSION['_csrf_token'])
        || !is_string($_SESSION['_csrf_token'])
        || strlen($_SESSION['_csrf_token']) !== 64) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Return a ready-to-echo hidden CSRF input.
 */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

/**
 * Validate a supplied token, or discover it in the request headers/body.
 */
function verifyCsrfToken(?string $token = null): bool {
    ensureSecuritySession();

    if ($token === null || $token === '') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($headerToken) && $headerToken !== '') {
            $token = $headerToken;
        } elseif (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        } elseif (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $json = requestJson();
            $token = isset($json['csrf_token']) && is_string($json['csrf_token'])
                ? $json['csrf_token']
                : null;
        }
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? null;
    return is_string($sessionToken)
        && is_string($token)
        && strlen($sessionToken) === 64
        && hash_equals($sessionToken, $token);
}

/**
 * Reject a request that does not contain this session's CSRF token.
 */
function requireCsrfToken(?string $token = null): void {
    if (!verifyCsrfToken($token)) {
        jsonResponse(false, 'Your security token is invalid or has expired. Please refresh and try again.', [], 419);
    }
}

/**
 * Convert a non-negative decimal amount to integer minor units exactly.
 */
function moneyToMinorUnits($amount, int $scale = 2): ?int {
    if ($scale < 0 || $scale > 6) {
        return null;
    }

    $value = trim((string) $amount);
    if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches)) {
        return null;
    }

    $major = ltrim($matches[1], '0');
    $major = $major === '' ? '0' : $major;
    $fraction = $matches[2] ?? '';
    if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
        return null;
    }

    $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
    $factor = 10 ** $scale;
    $maxMajor = intdiv(PHP_INT_MAX, $factor);
    if (strlen($major) > strlen((string) $maxMajor)
        || (strlen($major) === strlen((string) $maxMajor) && strcmp($major, (string) $maxMajor) > 0)) {
        return null;
    }

    return ((int) $major * $factor) + ($fraction === '' ? 0 : (int) $fraction);
}

/**
 * Format integer minor units as a fixed-scale decimal string.
 */
function minorUnitsToMoney(int $minorUnits, int $scale = 2): string {
    $scale = max(0, min(6, $scale));
    $factor = 10 ** $scale;
    if ($scale === 0) {
        return (string) $minorUnits;
    }

    return intdiv($minorUnits, $factor) . '.' . str_pad((string) ($minorUnits % $factor), $scale, '0', STR_PAD_LEFT);
}

/**
 * Normalize database course pricing so every page and API applies discounts
 * consistently. A discount is valid only when it is non-negative and lower
 * than the base price.
 *
 * @return array{valid: bool, has_discount: bool, base_minor: int, effective_minor: int, base_amount: string, effective_amount: string}
 */
function coursePriceDetails(array $course): array {
    $baseMinor = moneyToMinorUnits($course['price'] ?? null);
    $valid = $baseMinor !== null;
    $baseMinor = $baseMinor ?? 0;

    $discountMinor = null;
    if (array_key_exists('discount_price', $course) && $course['discount_price'] !== null) {
        $discountMinor = moneyToMinorUnits($course['discount_price']);
    }

    $hasDiscount = $valid
        && $discountMinor !== null
        && $discountMinor >= 0
        && $discountMinor < $baseMinor;
    $effectiveMinor = $hasDiscount ? $discountMinor : $baseMinor;

    return [
        'valid' => $valid,
        'has_discount' => $hasDiscount,
        'base_minor' => $baseMinor,
        'effective_minor' => $effectiveMinor,
        'base_amount' => minorUnitsToMoney($baseMinor),
        'effective_amount' => minorUnitsToMoney($effectiveMinor),
    ];
}
