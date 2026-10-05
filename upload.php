<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Handle QR File Upload
if (!isset($_FILES['qr_file']) || $_FILES['qr_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'status' => false,
        'message' => 'No file received or upload error occurred. Error code: ' . ($_FILES['qr_file']['error'] ?? 'none')
    ]);
    exit();
}

$file = $_FILES['qr_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['status' => false, 'message' => 'Invalid format. Allowed: JPG, PNG, WebP.']);
    exit();
}

$assets_dir = __DIR__ . '/assets/';
if (!is_dir($assets_dir)) {
    @mkdir($assets_dir, 0777, true);
}

// Save as qr.png and overwrite bangla-qr-default.jpg
$target_qr = $assets_dir . 'qr.png';
$target_default = $assets_dir . 'bangla-qr-default.jpg';

$copied = @move_uploaded_file($file['tmp_name'], $target_qr);
if (!$copied) {
    $content = @file_get_contents($file['tmp_name']);
    if ($content) {
        @file_put_contents($target_qr, $content);
        $copied = true;
    }
}

if (!$copied && !file_exists($target_qr)) {
    echo json_encode([
        'status' => false,
        'message' => 'Failed to write image file. Please verify folder write permissions on assets directory.'
    ]);
    exit();
}

@copy($target_qr, $target_default);

// Extract Base Bangla QR EMVCo Payload from the uploaded QR image
$decoded_payload = null;
if (function_exists('curl_init') && file_exists($target_qr)) {
    $ch = curl_init();
    $mime = function_exists('mime_content_type') ? @mime_content_type($target_qr) : 'image/png';
    if (!$mime) $mime = 'image/png';
    $cfile = new CURLFile($target_qr, $mime, basename($target_qr));
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.qrserver.com/v1/read-qr-code/',
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $parsed = @json_decode($res, true);
        if (!empty($parsed[0]['symbol'][0]['data'])) {
            $decoded_payload = trim($parsed[0]['symbol'][0]['data']);
        }
    }
}

$msg = 'Bangla QR image uploaded and saved successfully!';
if (!empty($decoded_payload)) {
    $msg .= ' Base Bangla QR Payload was automatically extracted.';
}

echo json_encode([
    'status' => true,
    'message' => $msg,
    'payload' => $decoded_payload,
    'timestamp' => time()
]);
exit();
