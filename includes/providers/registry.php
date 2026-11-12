<?php
/**
 * Provider registry and shared provider-agnostic helpers.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/OpenRouterAdapter.php';

/**
 * Return the active provider adapter (OpenRouter).
 *
 * @return LvsOpenRouterAdapter
 */
function lvs_get_adapter()
{
    $cfg = lvs_load_config();
    return new LvsOpenRouterAdapter($cfg['openrouter_api_key'], $cfg['http_referer']);
}

/**
 * Run a read-only connection test against OpenRouter.
 * Lists video models (definitive check) and gathers best-effort key/credit
 * info. Never submits a generation job, so it cannot spend credits.
 *
 * @return array
 */
function lvs_connection_test()
{
    $adapter = lvs_get_adapter();
    $out = array(
        'ok'          => false,
        'provider'    => $adapter->getName(),
        'models_count' => 0,
        'models_cached' => false,
        'key_info'    => null,
        'credits'     => null,
        'error'       => null,
    );

    // The /key endpoint absolutely requires a valid key, whereas the video
    // models listing is public. So the key check is the definitive one.
    $key = $adapter->getKeyInfo();
    if (!$key['ok']) {
        $out['error'] = array(
            'code'    => $key['status'] === 401 ? 'invalid_key' : 'key_check_failed',
            'message' => 'The API key was rejected by OpenRouter'
                . ($key['error'] ? ': ' . $key['error'] : '.'),
            'http'    => $key['status'],
        );
        return $out;
    }
    if (is_array($key['data'])) {
        $data = isset($key['data']['data']) && is_array($key['data']['data']) ? $key['data']['data'] : $key['data'];
        $out['key_info'] = array(
            'label'         => isset($data['label']) ? $data['label'] : null,
            'usage'         => isset($data['usage']) ? $data['usage'] : null,
            'limit'         => isset($data['limit']) ? $data['limit'] : null,
            'limit_remaining' => isset($data['limit_remaining']) ? $data['limit_remaining'] : null,
            'is_free_tier'  => isset($data['is_free_tier']) ? $data['is_free_tier'] : null,
            'rate_limit'    => isset($data['rate_limit']) ? $data['rate_limit'] : null,
        );
    }

    $models = $adapter->listModels(true);
    if (!$models['ok']) {
        $out['error'] = $models['error'];
        return $out;
    }
    $out['models_count'] = count($models['models']);
    $out['models_cached'] = (bool) $models['cached'];

    $credits = $adapter->getCredits();
    if ($credits['ok'] && is_array($credits['data'])) {
        $c = isset($credits['data']['data']) && is_array($credits['data']['data']) ? $credits['data']['data'] : $credits['data'];
        $out['credits'] = array(
            'total_credits' => isset($c['total_credits']) ? $c['total_credits'] : null,
            'total_usage'   => isset($c['total_usage']) ? $c['total_usage'] : null,
        );
    }

    $out['ok'] = true;
    return $out;
}

/**
 * Resolve the upstream content URL for a job output, preferring the stored
 * unsigned URL (which keeps working as long as the output exists) and falling
 * back to the canonical OpenRouter content endpoint. Only HTTPS URLs on the
 * openrouter.ai domain are trusted.
 *
 * @param array $job
 * @param int   $index
 * @return string
 */
function lvs_resolve_content_url(array $job, $index = 0)
{
    $index = (int) $index;
    if (isset($job['unsigned_urls'][$index]) && is_string($job['unsigned_urls'][$index])) {
        $url = $job['unsigned_urls'][$index];
        $parts = parse_url($url);
        if (isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https'
            && ($parts['host'] === 'openrouter.ai' || substr($parts['host'], -14) === '.openrouter.ai')) {
            return $url;
        }
    }
    return lvs_get_adapter()->contentUrl($job['id'], $index);
}

/**
 * Estimate the cost of a single clip using a model's pricing SKUs.
 *
 * OpenRouter video models expose a wide variety of pricing SKU names, e.g.
 * "duration_seconds_with_audio", "duration_seconds_720p",
 * "cents_per_video_output_second_480p" and token-based SKUs such as
 * "video_tokens". Only per-second rates can be estimated reliably; token-based
 * and resolution-independent (upscaling) SKUs return null so the UI can say
 * "unknown" instead of inventing a number.
 *
 * @param array  $model      Normalized model metadata.
 * @param int    $duration   Seconds.
 * @param string $resolution Requested resolution (e.g. 720p, 1080p, 2K).
 * @param bool   $audio      Whether audio generation is requested.
 * @return float|null Price in USD, or null when unknown.
 */
function lvs_estimate_cost(array $model, $duration, $resolution = '', $audio = false)
{
    if (empty($model['pricing_skus']) || !is_array($model['pricing_skus'])) {
        return null;
    }

    $resToken = strtolower(trim((string) $resolution));
    $resOrder = array('480p', '720p', '768p', '1080p', '4k', '2k', '1k');
    $best = null;

    foreach ($model['pricing_skus'] as $key => $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $k = strtolower((string) $key);

        if (strpos($k, 'token') !== false) {
            continue; // token based: cannot convert to per-second cost
        }
        if (strpos($k, 'megapixel') !== false) {
            continue; // upscaling, resolution dependent, not a generation rate
        }
        if (strpos($k, 'minimum') !== false || strpos($k, 'reference') !== false) {
            continue;
        }
        $isPerSecond = (strpos($k, 'second') !== false) || (strpos($k, 'duration') !== false);
        if (!$isPerSecond) {
            continue;
        }

        $rate = (float) $value;
        if (strpos($k, 'cent') !== false) {
            $rate = $rate / 100.0; // cents -> dollars
        }

        $keyRes = null;
        foreach ($resOrder as $rk) {
            if (strpos($k, $rk) !== false) {
                $keyRes = $rk;
                break;
            }
        }

        $score = 0;
        if ($resToken !== '') {
            if ($keyRes !== null) {
                $score += ($keyRes === $resToken) ? 100 : -80;
            }
        } elseif ($keyRes !== null) {
            $score -= 10;
        }

        $withAudio = strpos($k, 'with_audio') !== false;
        $withoutAudio = strpos($k, 'without_audio') !== false;
        if ($audio) {
            $score += $withAudio ? 30 : ($withoutAudio ? -20 : 5);
        } else {
            $score += $withoutAudio ? 30 : ($withAudio ? -20 : 5);
        }
        if (strpos($k, 'continuation') !== false) {
            $score -= 60; // editing/continuation rate, not first-generation
        }

        if ($best === null || $score > $best['score']) {
            $best = array('score' => $score, 'rate' => $rate);
        }
    }

    if ($best === null) {
        return null;
    }
    return round($best['rate'] * (int) $duration, 4);
}

/**
 * Work out how to reach a target total duration using a model's supported
 * clip lengths. Prefers an exact match, then the smallest overshoot.
 *
 * @param array $durations Supported durations (seconds).
 * @param int   $target    Desired total seconds.
 * @return array<int> list of clip durations
 */
function lvs_plan_clips(array $durations, $target)
{
    $target = (int) $target;
    $dur = array();
    foreach ($durations as $d) {
        $d = (int) $d;
        if ($d > 0 && !in_array($d, $dur, true)) {
            $dur[] = $d;
        }
    }
    sort($dur);

    if (empty($dur) || $target <= 0) {
        return array();
    }

    $max = max($dur);
    $upper = $target + $max;
    $inf = PHP_INT_MAX;

    $dp = array_fill(0, $upper + 1, $inf);
    $pick = array_fill(0, $upper + 1, null);
    $dp[0] = 0;

    for ($s = 1; $s <= $upper; $s++) {
        foreach ($dur as $d) {
            if ($d <= $s && $dp[$s - $d] !== $inf && ($dp[$s - $d] + 1) < $dp[$s]) {
                $dp[$s] = $dp[$s - $d] + 1;
                $pick[$s] = $d;
            }
        }
    }

    $chosen = null;
    if ($dp[$target] !== $inf) {
        $chosen = $target;
    } else {
        for ($s = $target + 1; $s <= $upper; $s++) {
            if ($dp[$s] !== $inf) {
                $chosen = $s;
                break;
            }
        }
    }

    if ($chosen !== null) {
        $clips = array();
        $s = $chosen;
        while ($s > 0) {
            $d = $pick[$s];
            if ($d === null) {
                break;
            }
            $clips[] = (int) $d;
            $s -= (int) $d;
        }
        rsort($clips);
        return $clips;
    }

    // Last resort: repeat the maximum clip length.
    $n = (int) ceil($target / $max);
    $clips = array();
    for ($i = 0; $i < $n; $i++) {
        $clips[] = $max;
    }
    return $clips;
}
