<?php
/**
 * GET api/download.php?id=<jobId>&index=0
 * Downloads a completed video as an attachment, streamed through the server
 * so the OpenRouter API key stays server-side. The provider's temporary URLs
 * are never exposed to the browser.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

set_exception_handler(function () {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Download error.';
});

function lvs_download_fail($status, $message)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_download_fail(409, 'No OpenRouter API key configured.');
}

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$index = isset($_GET['index']) ? max(0, (int) $_GET['index']) : 0;
if (!lvs_valid_job_id($id)) {
    lvs_download_fail(400, 'Invalid job id.');
}

$job = lvs_get_job($id);
if (!$job) {
    lvs_download_fail(404, 'Unknown job id.');
}
if (($job['status'] ?? '') !== 'completed') {
    lvs_download_fail(409, 'The video is not ready yet.');
}

// Build a safe, descriptive file name.
$modelPart = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($job['model'] ?? 'video'));
$name = 'video-' . trim($modelPart, '-') . '-' . substr($id, 0, 12) . '-clip' . $index . '.mp4';

$url = lvs_resolve_content_url($job, $index);
$adapter = lvs_get_adapter();
$range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

$result = lvs_proxy_stream(
    $url,
    $adapter->authHeaders(),
    $range,
    array('Content-Disposition' => 'attachment; filename="' . $name . '"')
);

if (!$result['ok']) {
    if (!headers_sent()) {
        lvs_download_fail(502, 'Could not download the video from the provider. The link may have expired.');
    }
    exit;
}
