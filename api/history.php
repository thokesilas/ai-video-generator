<?php
/**
 * GET  api/history.php[?limit=25&offset=0]     -> paginated list (metadata only)
 * GET  api/history.php?id=<jobId>              -> single entry
 * POST api/history.php {action,...}            -> mutate (CSRF protected)
 *
 * Listing never hits OpenRouter, so opening the page does not poll or spend.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$method = lvs_api_init(array('GET', 'POST'));

$config = lvs_load_config();

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (string) $_GET['id'];
        if (!lvs_valid_job_id($id)) {
            lvs_json_error('bad_id', 'Invalid job id.', 400);
        }
        $job = lvs_get_job($id);
        if (!$job) {
            lvs_json_error('not_found', 'Unknown job id.', 404);
        }
        lvs_json(array('ok' => true, 'job' => lvs_public_job($job)));
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    $limit = max(1, min(100, $limit));

    $all = lvs_load_history();
    $total = count($all);
    $slice = array_slice($all, $offset, $limit);
    $public = array();
    foreach ($slice as $job) {
        $public[] = lvs_public_job($job);
    }

    lvs_json(array(
        'ok'      => true,
        'total'   => $total,
        'offset'  => $offset,
        'limit'   => $limit,
        'jobs'    => $public,
        'storage_bytes' => lvs_local_storage_bytes(),
        'storage_limit_mb' => (int) $config['max_local_storage_mb'],
    ));
}

// ---- POST mutations --------------------------------------------------------
lvs_require_csrf();
$input = lvs_read_json_body();
$action = isset($input['action']) ? (string) $input['action'] : '';

if ($action === 'clear') {
    lvs_clear_history();
    lvs_json(array('ok' => true));
}

if ($action === 'delete') {
    $id = isset($input['id']) ? (string) $input['id'] : '';
    if (!lvs_valid_job_id($id)) {
        lvs_json_error('bad_id', 'Invalid job id.', 400);
    }
    if (!lvs_delete_job($id)) {
        lvs_json_error('not_found', 'Unknown job id.', 404);
    }
    lvs_json(array('ok' => true));
}

if ($action === 'save_local') {
    if (empty($config['allow_local_save'])) {
        lvs_json_error('disabled', 'Local saving is disabled in settings.', 403);
    }
    if (empty($config['openrouter_api_key'])) {
        lvs_json_error('no_key', 'No OpenRouter API key configured.', 409);
    }
    $id = isset($input['id']) ? (string) $input['id'] : '';
    $index = isset($input['index']) ? max(0, (int) $input['index']) : 0;
    if (!lvs_valid_job_id($id)) {
        lvs_json_error('bad_id', 'Invalid job id.', 400);
    }
    $job = lvs_get_job($id);
    if (!$job) {
        lvs_json_error('not_found', 'Unknown job id.', 404);
    }
    if (($job['status'] ?? '') !== 'completed') {
        lvs_json_error('not_ready', 'Only completed videos can be saved locally.', 409);
    }

    $url = lvs_resolve_content_url($job, $index);
    $adapter = lvs_get_adapter();
    $download = lvs_http_download($url, $adapter->authHeaders());
    if (!$download['ok']) {
        lvs_json_error('download_failed', 'Could not download the video: ' . $download['error'], 502);
    }

    $name = lvs_store_local_video($id, $index, $download['body'], 'mp4');
    if ($name === null) {
        lvs_json_error('storage_full', 'Could not save locally (storage limit reached or disk write failed).', 507);
    }

    $files = isset($job['local_files']) && is_array($job['local_files']) ? $job['local_files'] : array();
    $files[$index] = $name;
    $updated = lvs_update_job($id, array('local' => true, 'local_files' => $files));

    lvs_json(array('ok' => true, 'job' => lvs_public_job($updated ? $updated : $job)));
}

if ($action === 'delete_local') {
    $id = isset($input['id']) ? (string) $input['id'] : '';
    if (!lvs_valid_job_id($id)) {
        lvs_json_error('bad_id', 'Invalid job id.', 400);
    }
    $job = lvs_get_job($id);
    if (!$job) {
        lvs_json_error('not_found', 'Unknown job id.', 404);
    }
    lvs_delete_local_files($job);
    $updated = lvs_update_job($id, array('local' => false, 'local_files' => array()));
    lvs_json(array('ok' => true, 'job' => lvs_public_job($updated ? $updated : $job)));
}

lvs_json_error('bad_action', 'Unknown action.', 400);
