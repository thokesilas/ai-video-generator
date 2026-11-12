<?php
/**
 * GET api/status.php?id=<jobId>
 * Polls the real provider status for a job and updates local metadata.
 * Never reports completion before the provider confirms it.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
lvs_api_init(array('GET'));

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_json_error('no_key', 'No OpenRouter API key configured.', 409);
}

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if (!lvs_valid_job_id($id)) {
    lvs_json_error('bad_id', 'Invalid job id.', 400);
}

$job = lvs_get_job($id);
if (!$job) {
    lvs_json_error('not_found', 'Unknown job id (not in local history).', 404);
}

$adapter = lvs_get_adapter();
$result = $adapter->getJob($id);

if (!$result['ok']) {
    // Surface the error but keep the stored job; do not fabricate a status.
    lvs_json(array(
        'ok'    => false,
        'job'   => lvs_public_job($job),
        'error' => $result['error'],
    ), 502);
}

$raw = $result['job'];
$status = isset($raw['status']) ? (string) $raw['status'] : 'unknown';

$patch = array(
    'status'        => $status,
    'updated_at'    => time(),
    'generation_id' => isset($raw['generation_id']) ? $raw['generation_id'] : null,
    'error'         => isset($raw['error']) ? $raw['error'] : null,
);

if (isset($raw['unsigned_urls']) && is_array($raw['unsigned_urls'])) {
    $patch['unsigned_urls'] = array_values($raw['unsigned_urls']);
}
if (isset($raw['urls']) && is_array($raw['urls'])) {
    // Signed expiring URLs are kept server-side only for optional quick preview.
    $patch['signed_urls'] = array_values($raw['urls']);
}
if (isset($raw['urls_expires_at'])) {
    $patch['signed_urls_expires_at'] = $raw['urls_expires_at'];
}
if (isset($raw['usage']['cost'])) {
    $patch['cost'] = $raw['usage']['cost'];
}

$updated = lvs_update_job($id, $patch);
$public = lvs_public_job($updated ? $updated : array_merge($job, $patch));

lvs_json(array('ok' => true, 'job' => $public));
