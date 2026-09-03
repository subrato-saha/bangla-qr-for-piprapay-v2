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
if ($copied) {
    @copy($target_qr, $target_default);
    echo json_encode([
        'status' => true,
        'message' => 'Bangla QR image uploaded and saved successfully!',
        'timestamp' => time()
    ]);
} else {
    $content = @file_get_contents($file['tmp_name']);
    if ($content && @file_put_contents($target_qr, $content)) {
        @copy($target_qr, $target_default);
        echo json_encode([
            'status' => true,
            'message' => 'Bangla QR image saved successfully!',
            'timestamp' => time()
        ]);
    } else {
        echo json_encode([
            'status' => false,
            'message' => 'Failed to write image file. Please verify folder write permissions on assets directory.'
        ]);
    }
}
exit();
