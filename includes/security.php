<?php
/**
 * Security helpers: CSRF, key masking, rate limiting, validation.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

/**
 * HTML-escape a value for output.
 *
 * @param mixed $value
 * @return string
 */
function lvs_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return (creating if needed) the per-session CSRF token.
 *
 * @return string
 */
function lvs_csrf_token()
{
    if (empty($_SESSION['lvs_csrf'])) {
        $_SESSION['lvs_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lvs_csrf'];
}

/**
 * Verify a submitted CSRF token against the session token.
 *
 * @param string|null $token
 * @return bool
 */
function lvs_csrf_verify($token)
{
    if (!is_string($token) || $token === '' || empty($_SESSION['lvs_csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['lvs_csrf'], $token);
}

/**
 * Enforce CSRF for state changing requests, aborting with JSON on failure.
 */
function lvs_require_csrf()
{
    $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!lvs_csrf_verify($token)) {
        lvs_json(array(
            'ok'    => false,
            'error' => array(
                'code'    => 'csrf',
                'message' => 'Invalid or expired session token. Reload the page and try again.',
            ),
        ), 403);
    }
}

/**
 * Mask an API key so it can be shown back to the owner without exposing it.
 *
 * @param string $key
 * @return string
 */
function lvs_mask_key($key)
{
    if (!is_string($key) || $key === '') {
        return '';
    }
    $len = strlen($key);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    $prefix = substr($key, 0, 6);
    $suffix = substr($key, -4);
    return $prefix . str_repeat('*', max(4, min(12, $len - 10))) . $suffix;
}

/**
 * Validate an OpenRouter model slug such as "google/veo-3.1".
 *
 * @param string $slug
 * @return bool
 */
function lvs_valid_model_slug($slug)
{
    return is_string($slug)
        && strlen($slug) <= 160
        && preg_match('#^[A-Za-z0-9][A-Za-z0-9._\-]*/[A-Za-z0-9][A-Za-z0-9._\-:]*$#', $slug) === 1;
}

/**
 * Validate an OpenRouter job id (used in URLs / file names).
 *
 * @param string $id
 * @return bool
 */
function lvs_valid_job_id($id)
{
    return is_string($id) && preg_match('#^[A-Za-z0-9_\-]{1,128}$#', $id) === 1;
}

/**
 * Validate an upload token (used for temp image files).
 *
 * @param string $token
 * @return bool
 */
function lvs_valid_token($token)
{
    return is_string($token) && preg_match('#^[a-f0-9]{32}$#', $token) === 1;
}

/**
 * Validate an aspect ratio string against the allow-list of safe values.
 *
 * @param string $ratio
 * @return bool
 */
function lvs_valid_aspect_ratio($ratio)
{
    $allowed = array('16:9', '9:16', '1:1', '4:3', '3:4', '3:2', '2:3', '21:9', '9:21');
    return in_array($ratio, $allowed, true);
}

/**
 * Validate a resolution against the safe allow-list.
 *
 * @param string $resolution
 * @return bool
 */
function lvs_valid_resolution($resolution)
{
    $allowed = array('480p', '720p', '768p', '1080p', '1K', '2K', '4K');
    return in_array($resolution, $allowed, true);
}

/**
 * Simple file based rate limiter (token bucket over a sliding window).
 *
 * @param string $bucket
 * @param int    $max
 * @param int    $windowSeconds
 * @return bool true when allowed, false when limited
 */
function lvs_rate_limit($bucket, $max, $windowSeconds)
{
    $cfg = lvs_load_config();
    if (empty($cfg['enable_rate_limit'])) {
        return true;
    }

    $path = LVS_STORAGE_DIR . '/ratelimit.json';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true; // fail open on storage problems
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return true;
    }

    $raw = stream_get_contents($fh);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = array();
    }

    $now = time();
    $key = $bucket;
    $hits = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : array();
    // keep only hits inside the window
    $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSeconds) {
        return is_int($t) && ($now - $t) < $windowSeconds;
    }));

    $allowed = count($hits) < $max;
    if ($allowed) {
        $hits[] = $now;
    }
    $data[$key] = $hits;

    // opportunistic cleanup of stale buckets
    foreach ($data as $k => $v) {
        if (!is_array($v)) {
            unset($data[$k]);
            continue;
        }
        $fresh = array_values(array_filter($v, function ($t) use ($now, $windowSeconds) {
            return is_int($t) && ($now - $t) < $windowSeconds;
        }));
        if (empty($fresh)) {
            unset($data[$k]);
        } else {
            $data[$k] = $fresh;
        }
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $allowed;
}
