<?php
/**
 * POST api/test.php  (CSRF protected)
 * Read-only connection test. Lists video models and reads key/credit info.
 * This never submits a generation request, so it cannot spend credits.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
lvs_api_init(array('POST'));

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_json_error('no_key', 'No OpenRouter API key configured.', 409);
}

lvs_require_csrf();

$result = lvs_connection_test();
if (!$result['ok']) {
    lvs_json(array('ok' => false, 'error' => $result['error']), 502);
}

lvs_json(array('ok' => true, 'result' => $result));
