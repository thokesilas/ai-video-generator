<?php
/**
 * POST api/generate.php
 *
 * Two actions:
 *   action=plan   -> compute how to reach the target duration (no cost incurred)
 *   action=submit -> submit ONE clip to OpenRouter
 *
 * Splitting plan/submit keeps multi-clip (e.g. 20s) generation explicit and
 * avoids automatically spending credits on a batch without the user's intent.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
lvs_api_init(array('POST'));

$config = lvs_load_config();
if (empty($config['openrouter_api_key'])) {
    lvs_json_error('no_key', 'No OpenRouter API key configured. Add one in Settings.', 409);
}

// State-changing (may spend credits) -> require a valid CSRF token.
lvs_require_csrf();

$input = lvs_read_json_body();
$action = isset($input['action']) ? (string) $input['action'] : 'submit';

$modelSlug = isset($input['model']) ? trim((string) $input['model']) : '';
if (!lvs_valid_model_slug($modelSlug)) {
    lvs_json_error('bad_model', 'A valid model id is required (e.g. google/veo-3.1).', 400);
}

$adapter = lvs_get_adapter();
$modelsResult = $adapter->listModels(false);
if (!$modelsResult['ok']) {
    lvs_json_error(
        isset($modelsResult['error']['code']) ? $modelsResult['error']['code'] : 'models_failed',
        isset($modelsResult['error']['message']) ? $modelsResult['error']['message'] : 'Could not load models.',
        502
    );
}

$modelMeta = null;
foreach ($modelsResult['models'] as $m) {
    if (strcasecmp($m['id'], $modelSlug) === 0) {
        $modelMeta = $m;
        break;
    }
}

$warnings = array();
$modelKnown = $modelMeta !== null;

if (!$modelKnown) {
    // Custom model id that is not in OpenRouter's video models list. We allow it
    // (so brand-new models can be tried), but capabilities are unknown, so
    // model-specific validation is skipped and the provider's own error is
    // surfaced untouched.
    $modelMeta = array(
        'id'                      => $modelSlug,
        'name'                    => $modelSlug,
        'supported_durations'     => array(),
        'supported_resolutions'   => array(),
        'supported_aspect_ratios' => array(),
        'supported_frame_images'  => array(),
        'generate_audio'          => null,
        'seed'                    => null,
        'pricing_skus'            => array(),
    );
    $warnings[] = 'Custom model "' . $modelSlug . '" is not in OpenRouter\'s video model list. Capabilities are unknown, so duration/resolution/aspect checks against the model are skipped and the provider may reject the request.';
}

$targetDuration = isset($input['target_duration']) ? (int) $input['target_duration'] : 0;
$aspectRatio = isset($input['aspect_ratio']) ? (string) $input['aspect_ratio'] : '';
$resolution = isset($input['resolution']) ? (string) $input['resolution'] : '';

// Always validate against the safe allow-lists.
if ($aspectRatio !== '' && !lvs_valid_aspect_ratio($aspectRatio)) {
    lvs_json_error('bad_ratio', 'Unsupported aspect ratio.', 400);
}
if ($resolution !== '' && !lvs_valid_resolution($resolution)) {
    lvs_json_error('bad_resolution', 'Unsupported resolution.', 400);
}

// Model-specific validation only when the model is known.
if ($modelKnown) {
    if ($aspectRatio !== '' && !empty($modelMeta['supported_aspect_ratios']) && !in_array($aspectRatio, $modelMeta['supported_aspect_ratios'], true)) {
        lvs_json_error('ratio_not_supported', 'This model does not support aspect ratio ' . $aspectRatio . '. Supported: ' . implode(', ', $modelMeta['supported_aspect_ratios']), 400);
    }
    if ($resolution !== '' && !empty($modelMeta['supported_resolutions']) && !in_array($resolution, $modelMeta['supported_resolutions'], true)) {
        lvs_json_error('resolution_not_supported', 'This model does not support resolution ' . $resolution . '. Supported: ' . implode(', ', $modelMeta['supported_resolutions']), 400);
    }
}

$supportedDurations = $modelMeta['supported_durations'];

if ($modelKnown) {
    if (empty($supportedDurations)) {
        lvs_json_error('no_durations', 'This model did not advertise supported durations; generation is not safe to attempt.', 400);
    }
    $clips = lvs_plan_clips($supportedDurations, $targetDuration > 0 ? $targetDuration : max($supportedDurations));
} else {
    $single = $targetDuration > 0 ? $targetDuration : 5;
    if ($single < 1 || $single > 60) {
        lvs_json_error('bad_duration', 'For a custom model, the target duration must be between 1 and 60 seconds.', 400);
    }
    $clips = array($single);
}
if (empty($clips)) {
    lvs_json_error('bad_duration', 'Could not plan a generation for the requested duration.', 400);
}

$planAudio = !empty($input['generate_audio']);
$perClipCost = lvs_estimate_cost($modelMeta, max($clips), $resolution, $planAudio);
$totalCost = null;
if ($perClipCost !== null) {
    $totalCost = 0.0;
    foreach ($clips as $c) {
        $cCost = lvs_estimate_cost($modelMeta, $c, $resolution, $planAudio);
        if ($cCost === null) {
            $totalCost = null;
            break;
        }
        $totalCost += $cCost;
    }
}

$plan = array(
    'model'              => $modelMeta['id'],
    'model_name'         => $modelMeta['name'],
    'known_model'        => $modelKnown,
    'supported_durations' => $supportedDurations,
    'max_single_duration' => empty($supportedDurations) ? null : max($supportedDurations),
    'target_duration'    => $targetDuration,
    'clip_durations'     => $clips,
    'clip_count'         => count($clips),
    'total_planned_seconds' => array_sum($clips),
    'estimated_cost_per_clip' => $perClipCost,
    'estimated_total_cost' => $totalCost,
    'reaches_single_request' => count($clips) === 1,
);

if ($action === 'plan') {
    lvs_json(array('ok' => true, 'plan' => $plan, 'warnings' => $warnings));
}

if ($action !== 'submit') {
    lvs_json_error('bad_action', 'Unknown action.', 400);
}

// ---- Submit a single clip --------------------------------------------------

$prompt = isset($input['prompt']) ? trim((string) $input['prompt']) : '';
if ($prompt === '') {
    lvs_json_error('missing_prompt', 'A prompt is required.', 400);
}
if (strlen($prompt) > 4000) {
    lvs_json_error('prompt_too_long', 'Prompt is too long (max 4000 characters).', 400);
}

$negativePrompt = isset($input['negative_prompt']) ? trim((string) $input['negative_prompt']) : '';
if (strlen($negativePrompt) > 1000) {
    lvs_json_error('negative_too_long', 'Negative prompt is too long (max 1000 characters).', 400);
}

$clipIndex = isset($input['clip_index']) ? max(0, (int) $input['clip_index']) : 0;
$clipDuration = isset($input['clip_duration']) ? (int) $input['clip_duration'] : $clips[min($clipIndex, count($clips) - 1)];
if ($modelKnown) {
    if (!in_array($clipDuration, $supportedDurations, true)) {
        lvs_json_error('bad_clip_duration', 'Clip duration must be one of: ' . implode(', ', $supportedDurations), 400);
    }
} else {
    if ($clipDuration < 1 || $clipDuration > 60) {
        lvs_json_error('bad_clip_duration', 'For a custom model, the clip duration must be between 1 and 60 seconds.', 400);
    }
}

if (!lvs_rate_limit('generate', (int) $config['generations_per_minute'], 60)) {
    lvs_json_error('rate_limited', 'Too many generation requests in a short window. Wait a minute and try again.', 429);
}

// Audio
$generateAudio = !empty($input['generate_audio']);
$requestAudio = $generateAudio;
if ($generateAudio && $modelMeta['generate_audio'] === false) {
    $requestAudio = false;
    $warnings[] = 'This model does not advertise audio generation; the audio request was dropped.';
}

// Seed
$requestSeed = null;
if (isset($input['seed']) && $input['seed'] !== '' && $input['seed'] !== null) {
    $seed = (int) $input['seed'];
    if ($modelMeta['seed'] === true) {
        $requestSeed = $seed;
    } elseif ($modelMeta['seed'] === false) {
        $warnings[] = 'This model does not support deterministic seeds; the seed was dropped.';
    } else {
        $requestSeed = $seed; // capability unknown, pass through
    }
}

// Image-to-video: up to two frames (first and/or last), as supported by the
// model's frame_images capability.
$frameImages = array();

$firstToken = null;
if (!empty($input['image_token_first'])) {
    $firstToken = (string) $input['image_token_first'];
} elseif (!empty($input['image_token'])) {
    $firstToken = (string) $input['image_token']; // back-compat with single upload
}
$frameRequests = array();
if ($firstToken !== null) {
    $frameRequests['first_frame'] = $firstToken;
}
if (!empty($input['image_token_last'])) {
    $frameRequests['last_frame'] = (string) $input['image_token_last'];
}

$warnedNoFrameSupport = false;
foreach ($frameRequests as $frameType => $token) {
    $imagePath = lvs_find_upload($token);
    if ($imagePath === null) {
        lvs_json_error('bad_image', 'The uploaded ' . $frameType . ' image could not be found or is not a valid token.', 400);
    }
    if ($modelKnown && !empty($modelMeta['supported_frame_images']) && !in_array($frameType, $modelMeta['supported_frame_images'], true)) {
        lvs_json_error('image_not_supported', 'This model does not support the ' . $frameType . '. Supported frames: ' . implode(', ', $modelMeta['supported_frame_images']), 400);
    }
    if (empty($modelMeta['supported_frame_images']) && !$warnedNoFrameSupport) {
        $warnings[] = 'This model did not advertise frame-image support; the image(s) will be sent but the provider may reject them.';
        $warnedNoFrameSupport = true;
    }
    $binary = @file_get_contents($imagePath);
    if ($binary === false) {
        lvs_json_error('bad_image', 'Could not read the uploaded image.', 400);
    }
    $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $type = $finfo ? $finfo->file($imagePath) : 'image/png';
    if (!in_array($type, array('image/png', 'image/jpeg', 'image/webp'), true)) {
        lvs_json_error('bad_image_type', 'Unsupported image type.', 400);
    }
    $frameImages[] = array(
        'type'       => 'image_url',
        'image_url'  => array('url' => 'data:' . $type . ';base64,' . base64_encode($binary)),
        'frame_type' => $frameType,
    );
}

// Provider passthrough (advanced). Must be a JSON object.
$provider = null;
if (isset($input['provider_passthrough']) && is_array($input['provider_passthrough']) && !empty($input['provider_passthrough'])) {
    $provider = $input['provider_passthrough'];
}

// Continuation support: only pass previous_job_id when explicitly requested.
$previousJobId = null;
if (!empty($input['previous_job_id']) && lvs_valid_job_id($input['previous_job_id'])) {
    $previousJobId = (string) $input['previous_job_id'];
}

$request = array(
    'model'  => $modelMeta['id'],
    'prompt' => $prompt,
    'duration' => $clipDuration,
);
if ($aspectRatio !== '') {
    $request['aspect_ratio'] = $aspectRatio;
}
if ($resolution !== '') {
    $request['resolution'] = $resolution;
}
if ($requestAudio) {
    $request['generate_audio'] = true;
}
if ($requestSeed !== null) {
    $request['seed'] = $requestSeed;
}
if (!empty($frameImages)) {
    $request['frame_images'] = $frameImages;
}
if ($previousJobId !== null) {
    $request['previous_job_id'] = $previousJobId;
}
if ($provider !== null) {
    $request['provider'] = $provider;
}

$submit = $adapter->submit($request);
if (!$submit['ok']) {
    lvs_json(array(
        'ok'       => false,
        'error'    => $submit['error'],
        'warnings' => $warnings,
    ), isset($submit['error']['http']) && $submit['error']['http'] >= 400 ? $submit['error']['http'] : 502);
}

$raw = $submit['job'];
$jobId = (string) $raw['id'];
if (!lvs_valid_job_id($jobId)) {
    lvs_json_error('bad_response', 'Provider returned an unexpected job id.', 502);
}

$job = array(
    'id'             => $jobId,
    'batch_id'       => isset($input['batch_id']) && preg_match('#^[a-z0-9]{6,32}$#i', (string) $input['batch_id']) ? (string) $input['batch_id'] : null,
    'clip_index'     => $clipIndex,
    'created_at'     => time(),
    'updated_at'     => time(),
    'model'          => $modelMeta['id'],
    'model_name'     => $modelMeta['name'],
    'prompt'         => $prompt,
    'negative_prompt' => $negativePrompt,
    'duration'       => $clipDuration,
    'aspect_ratio'   => $aspectRatio,
    'resolution'     => $resolution,
    'generate_audio' => $requestAudio,
    'status'         => isset($raw['status']) ? (string) $raw['status'] : 'pending',
    'generation_id'  => isset($raw['generation_id']) ? $raw['generation_id'] : null,
    'unsigned_urls'  => array(),
    'cost'           => null,
    'error'          => null,
    'local'          => false,
    'local_files'    => array(),
);

lvs_upsert_job($job);

lvs_json(array(
    'ok'       => true,
    'job'      => lvs_public_job($job),
    'warnings' => $warnings,
    'plan'     => $plan,
));
