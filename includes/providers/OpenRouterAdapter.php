<?php
/**
 * OpenRouter video generation adapter.
 *
 * Implements the documented asynchronous workflow:
 *   POST /api/v1/videos            -> submit job (returns id, polling_url, status)
 *   GET  /api/v1/videos/{id}       -> poll status
 *   GET  /api/v1/videos/{id}/content?index=N -> download MP4 (needs Authorization)
 *   GET  /api/v1/videos/models     -> capability discovery
 *
 * Reference: https://openrouter.ai/docs/guides/overview/multimodal/video-generation
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/AdapterInterface.php';

class LvsOpenRouterAdapter implements LvsVideoProviderAdapter
{
    const BASE_URL = 'https://openrouter.ai/api/v1';
    const MODELS_CACHE_TTL = 3600;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $referer;

    public function __construct($apiKey, $referer = '')
    {
        $this->apiKey = (string) $apiKey;
        $this->referer = (string) $referer;
        if ($this->referer === '') {
            $this->referer = 'http://localhost/local-video-studio/';
        }
    }

    public function getName()
    {
        return 'OpenRouter';
    }

    public function authHeaders()
    {
        return array(
            'Authorization: Bearer ' . $this->apiKey,
            'HTTP-Referer: ' . $this->referer,
            'X-Title: Local AI Video Studio',
        );
    }

    private function jsonHeaders()
    {
        return array_merge($this->authHeaders(), array('Content-Type: application/json'));
    }

    private function cachePath()
    {
        return LVS_STORAGE_DIR . '/cache_models.json';
    }

    public function listModels($forceRefresh = false)
    {
        if (!$forceRefresh) {
            $cached = $this->readModelsCache();
            if ($cached !== null) {
                return array('ok' => true, 'models' => $cached, 'cached' => true, 'error' => null);
            }
        }

        $timeout = (int) lvs_load_config()['request_timeout_seconds'];
        $res = lvs_http_request('GET', self::BASE_URL . '/videos/models', $this->authHeaders(), null, $timeout);

        if ($res['status'] === 0) {
            return array('ok' => false, 'models' => array(), 'cached' => false,
                'error' => array('code' => 'network_error', 'message' => 'Could not reach OpenRouter: ' . $res['error']));
        }
        if ($res['status'] >= 400) {
            return array('ok' => false, 'models' => array(), 'cached' => false,
                'error' => lvs_map_api_error($res['status'], $res['body']));
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return array('ok' => false, 'models' => array(), 'cached' => false,
                'error' => array('code' => 'bad_response', 'message' => 'OpenRouter returned an unexpected model list.'));
        }

        $models = array();
        foreach ($decoded['data'] as $raw) {
            $normalized = $this->normalizeModel($raw);
            // Keep only models that advertise controllable durations. The list
            // also contains pure editing/upscaling/avatar models (no supported
            // durations) which cannot serve a text/image-to-video duration flow.
            if (empty($normalized['supported_durations'])) {
                continue;
            }
            $models[] = $normalized;
        }

        $this->writeModelsCache($models);

        return array('ok' => true, 'models' => $models, 'cached' => false, 'error' => null);
    }

    /**
     * Reduce a raw OpenRouter video model object to a stable, small shape.
     *
     * @param array $raw
     * @return array
     */
    private function normalizeModel($raw)
    {
        $durations = isset($raw['supported_durations']) && is_array($raw['supported_durations'])
            ? array_values(array_map('intval', $raw['supported_durations'])) : array();
        sort($durations);

        return array(
            'id'                       => isset($raw['id']) ? (string) $raw['id'] : '',
            'canonical_slug'           => isset($raw['canonical_slug']) ? (string) $raw['canonical_slug'] : '',
            'name'                     => isset($raw['name']) ? (string) $raw['name'] : (isset($raw['id']) ? (string) $raw['id'] : ''),
            'description'              => isset($raw['description']) ? (string) $raw['description'] : '',
            'supported_durations'      => $durations,
            'supported_resolutions'    => isset($raw['supported_resolutions']) && is_array($raw['supported_resolutions']) ? array_values($raw['supported_resolutions']) : array(),
            'supported_aspect_ratios'  => isset($raw['supported_aspect_ratios']) && is_array($raw['supported_aspect_ratios']) ? array_values($raw['supported_aspect_ratios']) : array(),
            'supported_sizes'          => isset($raw['supported_sizes']) && is_array($raw['supported_sizes']) ? array_values($raw['supported_sizes']) : array(),
            'supported_frame_images'   => isset($raw['supported_frame_images']) && is_array($raw['supported_frame_images']) ? array_values($raw['supported_frame_images']) : array(),
            'generate_audio'           => isset($raw['generate_audio']) ? (bool) $raw['generate_audio'] : null,
            'seed'                     => isset($raw['seed']) ? (bool) $raw['seed'] : null,
            'pricing_skus'             => isset($raw['pricing_skus']) && is_array($raw['pricing_skus']) ? $raw['pricing_skus'] : array(),
            'allowed_passthrough_parameters' => isset($raw['allowed_passthrough_parameters']) && is_array($raw['allowed_passthrough_parameters']) ? array_values($raw['allowed_passthrough_parameters']) : array(),
        );
    }

    private function readModelsCache()
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ts']) || !isset($data['models'])) {
            return null;
        }
        if ((time() - (int) $data['ts']) > self::MODELS_CACHE_TTL) {
            return null;
        }
        return is_array($data['models']) ? $data['models'] : null;
    }

    private function writeModelsCache(array $models)
    {
        $payload = json_encode(array('ts' => time(), 'models' => $models), JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->cachePath(), $payload, LOCK_EX);
    }

    public function submit(array $params)
    {
        $cfg = lvs_load_config();
        $timeout = (int) $cfg['request_timeout_seconds'];
        $body = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return array('ok' => false, 'job' => array(),
                'error' => array('code' => 'encode_error', 'message' => 'Could not encode request body.'));
        }

        $res = lvs_http_request('POST', self::BASE_URL . '/videos', $this->jsonHeaders(), $body, $timeout);

        if ($res['status'] === 0) {
            return array('ok' => false, 'job' => array(),
                'error' => array('code' => 'network_error', 'message' => 'Could not reach OpenRouter: ' . (string) $res['error']));
        }
        if ($res['status'] >= 400) {
            return array('ok' => false, 'job' => array(), 'error' => lvs_map_api_error($res['status'], $res['body']));
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded) || empty($decoded['id'])) {
            return array('ok' => false, 'job' => array(),
                'error' => array('code' => 'bad_response', 'message' => 'OpenRouter did not return a job id.', 'http' => $res['status']));
        }

        return array('ok' => true, 'job' => $decoded, 'error' => null);
    }

    public function getJob($jobId)
    {
        $cfg = lvs_load_config();
        $timeout = (int) $cfg['request_timeout_seconds'];
        $url = self::BASE_URL . '/videos/' . rawurlencode($jobId);
        $res = lvs_http_request('GET', $url, $this->authHeaders(), null, $timeout);

        if ($res['status'] === 0) {
            return array('ok' => false, 'job' => array(),
                'error' => array('code' => 'network_error', 'message' => 'Could not reach OpenRouter: ' . (string) $res['error']));
        }
        if ($res['status'] >= 400) {
            return array('ok' => false, 'job' => array(), 'error' => lvs_map_api_error($res['status'], $res['body']));
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'job' => array(),
                'error' => array('code' => 'bad_response', 'message' => 'OpenRouter returned an unexpected job status.'));
        }
        return array('ok' => true, 'job' => $decoded, 'error' => null);
    }

    public function contentUrl($jobId, $index = 0)
    {
        return self::BASE_URL . '/videos/' . rawurlencode($jobId) . '/content?index=' . (int) $index;
    }

    /**
     * Best-effort account/key info for the "Test connection" action.
     *
     * @return array{ok:bool,data:?array,error:?string,status:int}
     */
    public function getKeyInfo()
    {
        $res = lvs_http_request('GET', self::BASE_URL . '/key', $this->authHeaders(), null, 20);
        if ($res['status'] >= 400 || $res['status'] === 0) {
            return array('ok' => false, 'data' => null, 'status' => $res['status'],
                'error' => $res['status'] ? lvs_map_api_error($res['status'], $res['body'])['message'] : (string) $res['error']);
        }
        $decoded = json_decode($res['body'], true);
        return array('ok' => true, 'data' => is_array($decoded) ? $decoded : null, 'error' => null, 'status' => $res['status']);
    }

    /**
     * Best-effort credit balance. Not all accounts expose this.
     *
     * @return array{ok:bool,data:?array}
     */
    public function getCredits()
    {
        $res = lvs_http_request('GET', self::BASE_URL . '/credits', $this->authHeaders(), null, 20);
        if ($res['status'] >= 400 || $res['status'] === 0) {
            return array('ok' => false, 'data' => null);
        }
        $decoded = json_decode($res['body'], true);
        return array('ok' => is_array($decoded), 'data' => is_array($decoded) ? $decoded : null);
    }
}
