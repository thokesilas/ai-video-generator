<?php
/**
 * GET api/models.php[?refresh=1]
 * Lists video generation models with their normalized capabilities.
 * The API key is used server-side only and never returned.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
lvs_api_init(array('GET'));

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_json_error('no_key', 'No OpenRouter API key configured. Add one in Settings.', 409);
}

$force = isset($_GET['refresh']) && $_GET['refresh'] !== '' && $_GET['refresh'] !== '0';
$adapter = lvs_get_adapter();
$result = $adapter->listModels($force);

if (!$result['ok']) {
    lvs_json_error(
        isset($result['error']['code']) ? $result['error']['code'] : 'models_failed',
        isset($result['error']['message']) ? $result['error']['message'] : 'Could not load models.',
        isset($result['error']['http']) && $result['error']['http'] >= 400 ? $result['error']['http'] : 502
    );
}

lvs_json(array(
    'ok'      => true,
    'cached'  => (bool) $result['cached'],
    'count'   => count($result['models']),
    'models'  => $result['models'],
));
