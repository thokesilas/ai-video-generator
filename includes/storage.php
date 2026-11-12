<?php
/**
 * Local metadata + file storage.
 *
 * No database is required. Generation history is kept in a JSON file and
 * written atomically under an exclusive lock. Only metadata is stored by
 * default; videos stay on OpenRouter unless the user explicitly saves one
 * locally.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

function lvs_history_path()
{
    return LVS_STORAGE_DIR . '/history.json';
}

/**
 * Load all history entries (newest first).
 *
 * @return array
 */
function lvs_load_history()
{
    $path = lvs_history_path();
    if (!is_file($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array();
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/**
 * Persist history atomically.
 *
 * @param array $entries
 * @return bool
 */
function lvs_save_history(array $entries)
{
    $path = lvs_history_path();
    $tmp = $path . '.tmp';
    $json = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function lvs_get_job($id)
{
    foreach (lvs_load_history() as $job) {
        if (isset($job['id']) && $job['id'] === $id) {
            return $job;
        }
    }
    return null;
}

/**
 * Insert or update a job by id, keeping entries newest first.
 *
 * @param array $job
 * @return bool
 */
function lvs_upsert_job(array $job)
{
    if (!isset($job['id'])) {
        return false;
    }
    $entries = lvs_load_history();
    $found = false;
    foreach ($entries as $i => $existing) {
        if (isset($existing['id']) && $existing['id'] === $job['id']) {
            $entries[$i] = array_merge($existing, $job);
            $found = true;
            break;
        }
    }
    if (!$found) {
        array_unshift($entries, $job);
    }

    $cfg = lvs_load_config();
    $max = max(10, (int) $cfg['max_history_entries']);
    if (count($entries) > $max) {
        $entries = array_slice($entries, 0, $max);
    }

    return lvs_save_history($entries);
}

/**
 * Merge a patch into a job by id.
 *
 * @param string $id
 * @param array  $patch
 * @return array|null the updated job, or null if not found
 */
function lvs_update_job($id, array $patch)
{
    $entries = lvs_load_history();
    $updated = null;
    foreach ($entries as $i => $existing) {
        if (isset($existing['id']) && $existing['id'] === $id) {
            $entries[$i] = array_merge($existing, $patch);
            $updated = $entries[$i];
            break;
        }
    }
    if ($updated !== null) {
        lvs_save_history($entries);
    }
    return $updated;
}

/**
 * Remove a job (and its local files when requested).
 *
 * @param string $id
 * @return bool
 */
function lvs_delete_job($id)
{
    $entries = lvs_load_history();
    $out = array();
    $removed = null;
    foreach ($entries as $job) {
        if (isset($job['id']) && $job['id'] === $id) {
            $removed = $job;
            continue;
        }
        $out[] = $job;
    }
    if ($removed === null) {
        return false;
    }
    lvs_delete_local_files($removed);
    return lvs_save_history($out);
}

function lvs_clear_history()
{
    foreach (lvs_load_history() as $job) {
        lvs_delete_local_files($job);
    }
    return lvs_save_history(array());
}

/**
 * Delete any locally saved video files referenced by a job.
 *
 * @param array $job
 */
function lvs_delete_local_files(array $job)
{
    if (!empty($job['local_files']) && is_array($job['local_files'])) {
        foreach ($job['local_files'] as $file) {
            $safe = basename($file);
            $path = LVS_STORAGE_DIR . '/videos/' . $safe;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

/**
 * Total size of locally stored videos in bytes.
 *
 * @return int
 */
function lvs_local_storage_bytes()
{
    $bytes = 0;
    $dir = LVS_STORAGE_DIR . '/videos';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $bytes += (int) filesize($path);
            }
        }
    }
    return $bytes;
}

/**
 * Remove oldest local video files until under the configured limit.
 *
 * @param int $neededBytes extra bytes about to be written
 * @return bool true if there is room
 */
function lvs_enforce_storage_limit($neededBytes = 0)
{
    $cfg = lvs_load_config();
    $limit = max(0, (int) $cfg['max_local_storage_mb']) * 1024 * 1024;
    if ($limit <= 0) {
        return false;
    }

    $entries = lvs_load_history();

    // Build a list of local files with their age based on the owning job.
    $files = array();
    foreach ($entries as $job) {
        if (empty($job['local_files']) || !is_array($job['local_files'])) {
            continue;
        }
        foreach ($job['local_files'] as $file) {
            $safe = basename($file);
            $path = LVS_STORAGE_DIR . '/videos/' . $safe;
            if (is_file($path)) {
                $files[] = array(
                    'path' => $path,
                    'id'   => isset($job['id']) ? $job['id'] : '',
                    'mtime' => (int) filemtime($path),
                );
            }
        }
    }

    usort($files, function ($a, $b) {
        return $a['mtime'] <=> $b['mtime'];
    });

    $total = lvs_local_storage_bytes();
    foreach ($files as $f) {
        if ($total + $neededBytes <= $limit) {
            break;
        }
        $total -= (int) filesize($f['path']);
        @unlink($f['path']);
        // detach from history
        if ($f['id'] !== '') {
            $entries = lvs_load_history();
            foreach ($entries as $i => $job) {
                if (isset($job['id']) && $job['id'] === $f['id']) {
                    $kept = array();
                    if (!empty($job['local_files'])) {
                        foreach ($job['local_files'] as $lf) {
                            if (basename($lf) !== basename($f['path'])) {
                                $kept[] = $lf;
                            }
                        }
                    }
                    $entries[$i]['local_files'] = $kept;
                    $entries[$i]['local'] = !empty($kept);
                    lvs_save_history($entries);
                    break;
                }
            }
        }
    }

    return (lvs_local_storage_bytes() + $neededBytes) <= $limit;
}

/**
 * Store a video file locally for a job at a given output index.
 *
 * @param string $jobId
 * @param int    $index
 * @param string $binary
 * @param string $ext
 * @return string|null stored file name
 */
function lvs_store_local_video($jobId, $index, $binary, $ext = 'mp4')
{
    if (!lvs_enforce_storage_limit(strlen($binary))) {
        return null;
    }
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    if ($ext === '') {
        $ext = 'mp4';
    }
    $name = 'vid_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $jobId) . '_' . (int) $index . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $path = LVS_STORAGE_DIR . '/videos/' . $name;
    if (@file_put_contents($path, $binary, LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0600);
    return $name;
}

/**
 * Resolve a stored upload token to its file path (path-traversal safe).
 *
 * @param string $token
 * @return string|null
 */
function lvs_find_upload($token)
{
    if (!lvs_valid_token($token)) {
        return null;
    }
    $dir = LVS_STORAGE_DIR . '/uploads';
    foreach (array('png', 'jpg', 'jpeg', 'webp') as $ext) {
        $path = $dir . '/up_' . $token . '.' . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Delete upload files older than the given age in seconds.
 */
function lvs_prune_uploads($maxAgeSeconds = 7200)
{
    $dir = LVS_STORAGE_DIR . '/uploads';
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_file($path) && ($now - (int) filemtime($path)) > $maxAgeSeconds) {
            @unlink($path);
        }
    }
}

/**
 * Trim a job record down to what the browser is allowed to see.
 * Never includes the API key or signed (keyless) expiring URLs.
 *
 * @param array $job
 * @return array
 */
function lvs_public_job(array $job)
{
    return array(
        'id'             => $job['id'] ?? '',
        'batch_id'       => $job['batch_id'] ?? null,
        'clip_index'     => $job['clip_index'] ?? 0,
        'created_at'     => $job['created_at'] ?? null,
        'updated_at'     => $job['updated_at'] ?? null,
        'model'          => $job['model'] ?? '',
        'model_name'     => $job['model_name'] ?? '',
        'prompt'         => $job['prompt'] ?? '',
        'duration'       => $job['duration'] ?? null,
        'aspect_ratio'   => $job['aspect_ratio'] ?? null,
        'resolution'     => $job['resolution'] ?? null,
        'generate_audio' => $job['generate_audio'] ?? null,
        'status'         => $job['status'] ?? 'unknown',
        'generation_id'  => $job['generation_id'] ?? null,
        'output_count'   => isset($job['unsigned_urls']) && is_array($job['unsigned_urls']) ? count($job['unsigned_urls']) : (!empty($job['output_count']) ? (int) $job['output_count'] : 0),
        'cost'           => $job['cost'] ?? null,
        'error'          => $job['error'] ?? null,
        'local'          => !empty($job['local']),
        'local_count'    => !empty($job['local_files']) && is_array($job['local_files']) ? count($job['local_files']) : 0,
    );
}
