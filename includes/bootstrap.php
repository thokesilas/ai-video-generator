<?php
/**
 * Shared bootstrap. Every entry point (pages and API endpoints) includes this.
 */

if (defined('LVS_ROOT')) {
    return; // already loaded
}

define('LVS_ROOT', dirname(__DIR__));
define('LVS_INCLUDES_DIR', LVS_ROOT . '/includes');
define('LVS_CONFIG_DIR', LVS_ROOT . '/config');
define('LVS_STORAGE_DIR', LVS_ROOT . '/storage');
define('LVS_VERSION', '1.0.0');

require_once LVS_INCLUDES_DIR . '/config.php';
require_once LVS_INCLUDES_DIR . '/errors.php';
require_once LVS_INCLUDES_DIR . '/security.php';
require_once LVS_INCLUDES_DIR . '/http.php';
require_once LVS_INCLUDES_DIR . '/storage.php';
require_once LVS_INCLUDES_DIR . '/providers/registry.php';

// Never leak errors to the browser. They are logged when possible.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Ensure runtime directories exist.
foreach (array(LVS_STORAGE_DIR, LVS_STORAGE_DIR . '/videos', LVS_STORAGE_DIR . '/uploads') as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// Secure-ish session defaults (local HTTP, so no secure cookie flag).
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    @session_start();
}

/**
 * Emit a JSON response and terminate.
 *
 * @param mixed $data
 * @param int   $status
 * @return never
 */
function lvs_json($data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function lvs_json_error($code, $message, $status = 400, array $extra = array())
{
    lvs_json(array_merge(array(
        'ok'    => false,
        'error' => array('code' => $code, 'message' => $message),
    ), $extra), $status);
}

/**
 * Read the JSON request body (if any).
 *
 * @return array
 */
function lvs_read_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

/**
 * Initialise an API endpoint: JSON output, method guard, error trapping.
 *
 * @param array $allowedMethods
 */
function lvs_api_init(array $allowedMethods = array('GET', 'POST'))
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    set_exception_handler(function ($e) {
        error_log('[LVS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        lvs_json_error('server_error', 'An unexpected server error occurred.', 500);
    });

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if (!in_array($method, $allowedMethods, true)) {
        header('Allow: ' . implode(', ', $allowedMethods));
        lvs_json_error('method_not_allowed', 'HTTP method ' . $method . ' is not allowed here.', 405);
    }
    return $method;
}

/**
 * Diagnostics used by the settings page.
 *
 * @return array
 */
function lvs_diagnostics()
{
    return array(
        'php_version'   => PHP_VERSION,
        'curl'          => function_exists('curl_init'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'fileinfo'      => class_exists('finfo'),
        'json'          => function_exists('json_encode'),
        'gd'            => function_exists('imagecreatetruecolor'),
        'storage_writable' => is_writable(LVS_STORAGE_DIR),
        'config_writable'  => is_writable(LVS_CONFIG_DIR),
        'open_basedir'  => ini_get('open_basedir') ?: '',
        'max_upload'    => ini_get('upload_max_filesize'),
        'post_max'      => ini_get('post_max_size'),
        'os'            => PHP_OS,
    );
}
