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
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
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
function redirect($path) {
    header('Location: ' . APP_URL . $path);
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
    return '₦' . number_format($amount, 2);
}

/**
 * Generate random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
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
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    $fileSize = $file['size'];
    if ($fileSize > $maxSize) {
        return ['success' => false, 'message' => 'File size exceeds limit'];
    }

    $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'message' => 'File type not allowed'];
    }

    return ['success' => true, 'message' => 'File is valid'];
}

/**
 * Upload file
 */
function uploadFile($file, $uploadDir = 'uploads/') {
    $validation = validateUpload($file);
    if (!$validation['success']) {
        return $validation;
    }

    $fileName = generateRandomString(16) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $filePath = $uploadDir . $fileName;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => true, 'message' => 'File uploaded successfully', 'file' => $filePath];
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
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}
