<?php
/**
 * GET api/stream.php?id=<jobId>&index=0
 * Streams a completed video through the server so the API key is never
 * exposed to the browser and the output can be played even when OpenRouter
 * only offers an authenticated URL. Supports HTTP Range for seeking.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

set_exception_handler(function () {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Stream error.';
});

function lvs_stream_fail($status, $message)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $message;
    exit;
}

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_stream_fail(409, 'No OpenRouter API key configured.');
}

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$index = isset($_GET['index']) ? max(0, (int) $_GET['index']) : 0;
if (!lvs_valid_job_id($id)) {
    lvs_stream_fail(400, 'Invalid job id.');
}

$job = lvs_get_job($id);
if (!$job) {
    lvs_stream_fail(404, 'Unknown job id.');
}
if (($job['status'] ?? '') !== 'completed') {
    lvs_stream_fail(409, 'The video is not ready yet (status: ' . ($job['status'] ?? 'unknown') . ').');
}

$url = lvs_resolve_content_url($job, $index);
$adapter = lvs_get_adapter();
$range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

$result = lvs_proxy_stream(
    $url,
    $adapter->authHeaders(),
    $range,
    array('Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex')
);

if (!$result['ok']) {
    if (!headers_sent()) {
        lvs_stream_fail(502, 'Could not fetch the video from the provider. The link may have expired.');
    }
    exit;
}
