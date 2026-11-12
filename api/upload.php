<?php
/**
 * POST api/upload.php  (multipart form field "image")
 * Validates and stores an image for image-to-video. Returns an opaque token;
 * the original file name is never used (path traversal prevention).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
lvs_api_init(array('POST'));

$config = lvs_load_config();

lvs_require_csrf();

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    lvs_json_error('no_file', 'No image was uploaded.', 400);
}

$file = $_FILES['image'];
if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $messages = array(
        UPLOAD_ERR_INI_SIZE   => 'The image exceeds the server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'The image exceeds the allowed size.',
        UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No image was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
    );
    $code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    lvs_json_error('upload_error', isset($messages[$code]) ? $messages[$code] : 'Upload failed.', 400);
}

$maxBytes = max(1, (int) $config['max_upload_mb']) * 1024 * 1024;
if ((int) $file['size'] > $maxBytes) {
    lvs_json_error('too_large', 'Image is larger than the ' . (int) $config['max_upload_mb'] . ' MB limit.', 413);
}

if (!is_uploaded_file($file['tmp_name'])) {
    lvs_json_error('bad_upload', 'Invalid upload.', 400);
}

// Validate real MIME type (not the client supplied one).
$allowed = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
$mime = null;
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
}
if ($mime === null || !isset($allowed[$mime])) {
    lvs_json_error('bad_type', 'Unsupported image type. Use PNG, JPEG or WebP.', 415);
}

// Confirm it is actually a decodable image.
$info = @getimagesize($file['tmp_name']);
if ($info === false || empty($info[0]) || empty($info[1])) {
    lvs_json_error('bad_image', 'The file is not a valid image.', 400);
}
if ((int) $info[0] > 8192 || (int) $info[1] > 8192) {
    lvs_json_error('too_big_dims', 'Image dimensions are too large.', 400);
}

if (!lvs_rate_limit('upload', 30, 60)) {
    lvs_json_error('rate_limited', 'Too many uploads. Wait a moment.', 429);
}

lvs_prune_uploads(7200);

$token = bin2hex(random_bytes(16));
$ext = $allowed[$mime];
$dest = LVS_STORAGE_DIR . '/uploads/up_' . $token . '.' . $ext;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    lvs_json_error('store_failed', 'Could not store the uploaded image.', 500);
}
@chmod($dest, 0600);

lvs_json(array(
    'ok'       => true,
    'token'    => $token,
    'width'    => (int) $info[0],
    'height'   => (int) $info[1],
    'mime'     => $mime,
    'bytes'    => (int) $file['size'],
    'expires_in' => 7200,
));
