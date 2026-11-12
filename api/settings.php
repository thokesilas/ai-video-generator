<?php
/**
 * GET  api/settings.php            -> safe config view + diagnostics
 * POST api/settings.php {action}   -> save | clear_key | test (CSRF protected)
 *
 * The API key is write-only from the browser's perspective: it is accepted on
 * save but only ever returned masked.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$method = lvs_api_init(array('GET', 'POST'));

$config = lvs_load_config();

if ($method === 'GET') {
    lvs_json(array(
        'ok'          => true,
        'version'     => LVS_VERSION,
        'provider'    => lvs_get_adapter()->getName(),
        'has_key'     => !empty($config['openrouter_api_key']),
        'masked_key'  => lvs_mask_key($config['openrouter_api_key']),
        'settings'    => array(
            'app_title'               => $config['app_title'],
            'http_referer'            => $config['http_referer'],
            'poll_interval_seconds'   => (int) $config['poll_interval_seconds'],
            'max_local_storage_mb'    => (int) $config['max_local_storage_mb'],
            'max_history_entries'     => (int) $config['max_history_entries'],
            'request_timeout_seconds' => (int) $config['request_timeout_seconds'],
            'generation_timeout_seconds' => (int) $config['generation_timeout_seconds'],
            'max_upload_mb'           => (int) $config['max_upload_mb'],
            'allow_local_save'        => (bool) $config['allow_local_save'],
            'enable_rate_limit'       => (bool) $config['enable_rate_limit'],
            'generations_per_minute'  => (int) $config['generations_per_minute'],
        ),
        'diagnostics' => lvs_diagnostics(),
        'storage_bytes' => lvs_local_storage_bytes(),
        'csrf'        => lvs_csrf_token(),
    ));
}

// ---- POST ------------------------------------------------------------------
lvs_require_csrf();
$input = lvs_read_json_body();
$action = isset($input['action']) ? (string) $input['action'] : '';

if ($action === 'test') {
    if (empty($config['openrouter_api_key'])) {
        lvs_json_error('no_key', 'No API key configured yet.', 409);
    }
    $result = lvs_connection_test();
    if (!$result['ok']) {
        lvs_json(array('ok' => false, 'error' => $result['error']), 502);
    }
    lvs_json(array('ok' => true, 'result' => $result));
}

if ($action === 'clear_key') {
    $config['openrouter_api_key'] = '';
    if (!lvs_save_config($config)) {
        lvs_json_error('save_failed', 'Could not write the configuration file.', 500);
    }
    lvs_json(array('ok' => true, 'has_key' => false));
}

if ($action === 'save') {
    // API key: only replace when a real new value is supplied.
    $currentMasked = lvs_mask_key($config['openrouter_api_key']);
    if (array_key_exists('api_key', $input)) {
        $newKey = trim((string) $input['api_key']);
        if ($newKey !== '' && $newKey !== $currentMasked) {
            if (strlen($newKey) < 16 || strpos($newKey, ' ') !== false) {
                lvs_json_error('bad_key', 'That does not look like a valid OpenRouter API key.', 400);
            }
            $config['openrouter_api_key'] = $newKey;
        }
    }

    // Non-secret settings.
    $s = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : array();

    if (isset($s['app_title'])) {
        $t = trim((string) $s['app_title']);
        $config['app_title'] = $t !== '' ? substr($t, 0, 60) : 'Local AI Video Studio';
    }
    if (isset($s['http_referer'])) {
        $r = trim((string) $s['http_referer']);
        if ($r === '' || filter_var($r, FILTER_VALIDATE_URL) === false) {
            lvs_json_error('bad_referer', 'The HTTP referer must be a valid URL.', 400);
        }
        $config['http_referer'] = substr($r, 0, 200);
    }

    $intFields = array(
        'poll_interval_seconds'   => array(3, 120),
        'max_local_storage_mb'    => array(0, 20480),
        'max_history_entries'     => array(10, 2000),
        'request_timeout_seconds' => array(5, 300),
        'generation_timeout_seconds' => array(60, 3600),
        'max_upload_mb'           => array(1, 32),
        'generations_per_minute'  => array(1, 60),
    );
    foreach ($intFields as $field => $range) {
        if (isset($s[$field])) {
            $v = (int) $s[$field];
            if ($v < $range[0] || $v > $range[1]) {
                lvs_json_error('bad_setting', $field . ' must be between ' . $range[0] . ' and ' . $range[1] . '.', 400);
            }
            $config[$field] = $v;
        }
    }
    if (isset($s['allow_local_save'])) {
        $config['allow_local_save'] = (bool) $s['allow_local_save'];
    }
    if (isset($s['enable_rate_limit'])) {
        $config['enable_rate_limit'] = (bool) $s['enable_rate_limit'];
    }

    if (!lvs_save_config($config)) {
        lvs_json_error('save_failed', 'Could not write the configuration file. Check that the config directory is writable.', 500);
    }

    lvs_json(array(
        'ok'         => true,
        'has_key'    => !empty($config['openrouter_api_key']),
        'masked_key' => lvs_mask_key($config['openrouter_api_key']),
    ));
}

lvs_json_error('bad_action', 'Unknown action.', 400);
