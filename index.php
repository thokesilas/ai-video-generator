<?php
require_once __DIR__ . '/includes/bootstrap.php';

$config = lvs_load_config();
$hasKey = !empty($config['openrouter_api_key']);
$csrf = lvs_csrf_token();
$title = $config['app_title'];
$diag = lvs_diagnostics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo lvs_e($csrf); ?>">
<title><?php echo lvs_e($title); ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo lvs_e(LVS_VERSION); ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="logo">🎬</span>
        <span><?php echo lvs_e($title); ?><small>Local · OpenRouter video generation</small></span>
    </div>
    <nav>
        <span id="keyChip" class="badge <?php echo $hasKey ? 'completed' : 'failed'; ?>">
            <span class="dot"></span><span id="keyChipText"><?php echo $hasKey ? 'API key set' : 'No API key'; ?></span>
        </span>
        <a class="nav-link active" href="index.php">Studio</a>
        <a class="nav-link" href="settings.php">Settings</a>
    </nav>
</header>

<div class="wrap">
<?php if (!$diag['curl'] && !$diag['allow_url_fopen']): ?>
    <div class="notice err">
        Neither the PHP <b>cURL</b> extension nor <code class="inline">allow_url_fopen</code> is available.
        Enable the cURL extension in XAMPP (php.ini) to make API requests.
    </div>
<?php endif; ?>

<?php if (!$hasKey): ?>
    <div class="notice">
        No OpenRouter API key configured yet. Open <a href="settings.php">Settings</a> to add your key.
        Video generation is disabled until a key is set.
    </div>
<?php endif; ?>

<div class="grid">
    <!-- LEFT: controls -->
    <div>
        <section class="panel">
            <h2>Create</h2>

            <label class="field">
                <span>Prompt</span>
                <textarea id="prompt" maxlength="4000" placeholder="Describe the video: subject, motion, camera movement, lighting, mood..."></textarea>
            </label>

            <label class="field">
                <span>Model <span class="muted">(video generation capable)</span></span>
                <select id="model" <?php echo $hasKey ? '' : 'disabled'; ?>>
                    <option value=""><?php echo $hasKey ? 'Loading models...' : 'Add an API key first'; ?></option>
                </select>
            </label>
            <div id="modelCaps" class="cap-list"></div>
            <div id="modelHint" class="hint"></div>

            <label class="field" style="margin-top:12px">
                <span>Custom model ID (optional) <span class="muted">overrides the dropdown</span></span>
                <input type="text" id="customModel" placeholder="e.g. google/veo-3.1" spellcheck="false" autocomplete="off" <?php echo $hasKey ? '' : 'disabled'; ?>>
            </label>
            <div class="hint">Type any OpenRouter video model id. If it is not in the list above, its capabilities are unknown and model-specific validation is skipped; the provider's own error (if any) is shown.</div>

            <div class="mb"></div>

            <label class="field">
                <span>Target duration (seconds)</span>
                <input type="number" id="targetDuration" min="1" max="60" value="8" <?php echo $hasKey ? '' : 'disabled'; ?>>
            </label>
            <div class="chips" id="durationChips"></div>
            <div id="durationHint" class="hint"></div>

            <div class="mb"></div>

            <label class="field">
                <span>Aspect ratio</span>
                <div class="chips" id="ratioChips">
                    <span class="chip" data-ratio="16:9">16:9 Landscape</span>
                    <span class="chip" data-ratio="9:16">9:16 Vertical</span>
                    <span class="chip" data-ratio="1:1">1:1 Square</span>
                </div>
            </label>

            <label class="field">
                <span>Resolution</span>
                <select id="resolution"><option value="">Model default</option></select>
            </label>

            <label class="check">
                <input type="checkbox" id="generateAudio"> Generate audio (if the model supports it)
            </label>

            <details class="advanced">
                <summary>Frames (image-to-video)</summary>
                <div>
                    <div class="frame-grid">
                        <div class="frame-slot">
                            <div class="frame-label">First frame</div>
                            <div class="drop" id="dropFirst">
                                <div class="frame-dropText">Click or drop an image</div>
                                <input type="file" id="imageInputFirst" accept="image/png,image/jpeg,image/webp">
                                <div class="frame-preview"></div>
                            </div>
                        </div>
                        <div class="frame-slot">
                            <div class="frame-label">Last frame</div>
                            <div class="drop" id="dropLast">
                                <div class="frame-dropText">Click or drop an image</div>
                                <input type="file" id="imageInputLast" accept="image/png,image/jpeg,image/webp">
                                <div class="frame-preview"></div>
                            </div>
                        </div>
                    </div>
                    <div class="hint" id="uploadHint">Optional. First/last frames are sent for models that support them (PNG, JPEG or WebP).</div>
                </div>
            </details>

            <details class="advanced">
                <summary>Advanced options</summary>
                <div>
                    <label class="field">
                        <span>Negative prompt (stored with the job; forwarding is provider-specific)</span>
                        <textarea id="negativePrompt" maxlength="1000" style="min-height:70px" placeholder="blurry, low quality, distorted..."></textarea>
                    </label>
                    <div class="hint warn">Negative prompts are provider-specific and are not part of OpenRouter's normalized video schema. This app stores them with the job but does not auto-forward them. Use the passthrough box below for provider-specific fields.</div>

                    <label class="field" style="margin-top:12px">
                        <span>Seed (optional, if supported)</span>
                        <input type="number" id="seed" placeholder="e.g. 42">
                    </label>

                    <label class="field">
                        <span>Provider passthrough (JSON object, optional)</span>
                        <textarea id="passthrough" class="mono" style="min-height:80px" placeholder='{"options":{"google-vertex":{"parameters":{"negativePrompt":"blurry"}}}}'></textarea>
                    </label>
                    <div class="hint">Sent verbatim as the request <code class="inline">provider</code> field. Check the model's allowed passthrough parameters first. Invalid JSON is ignored with a warning.</div>

                    <label class="check">
                        <input type="checkbox" id="continuation"> Use continuation (chain each clip from the previous one)
                    </label>
                    <div class="hint" id="continuationHint">Only models that support continuation (previous_job_id) accept this. If the provider rejects it, turn it off. Continuation is not a guarantee of a seamless 20s video.</div>
                </div>
            </details>

            <label class="check">
                <input type="checkbox" id="autoAll"> Automatically generate all clips for the target duration
            </label>

            <div id="continueBanner" class="notice info hidden">
                <span id="continueText"></span>
                <button class="btn small" id="continueCancelBtn" style="margin-left:8px">Cancel</button>
            </div>

            <div id="planBox" class="hidden mb"></div>
            <div id="generateWarning" class="hint warn"></div>

            <button class="btn primary full mt" id="generateBtn" <?php echo $hasKey ? '' : 'disabled'; ?>>
                <span id="generateBtnText">Generate video</span>
            </button>
            <div class="hint">Each request is sent to OpenRouter and may cost credits. Nothing is retried automatically.</div>
        </section>
    </div>

    <!-- RIGHT: progress + preview + history -->
    <div>
        <section class="panel">
            <h2>Progress <span class="muted" id="progressStatus"></span></h2>
            <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
            <div id="clipList"></div>
            <div id="progressEmpty" class="hint">No active generation. Fill in a prompt and press Generate.</div>
        </section>

        <section class="panel">
            <h2>Preview <span class="muted" id="previewLabel"></span></h2>
            <div class="preview-stage" id="previewStage">
                <div class="preview-empty" id="previewEmpty">Completed videos will play here. Streaming happens through this server, so the API key is never exposed.</div>
                <video id="preview" controls preload="none" playsinline></video>
            </div>
            <div class="row mt">
                <button class="btn small" id="downloadBtn" disabled>Download MP4</button>
                <button class="btn small" id="saveLocalBtn" disabled>Save locally</button>
                <button class="btn small danger" id="deleteLocalBtn" disabled>Delete local copy</button>
            </div>
            <div class="hint" id="remoteNote"></div>
        </section>

        <section class="panel">
            <h2>Recent generations
                <span class="muted" id="storageInfo"></span>
                <button class="btn small danger" id="clearHistoryBtn" style="float:right">Clear all</button>
            </h2>
            <div id="historyList"><div class="hint" id="historyEmpty">Nothing yet.</div></div>
            <button class="btn small ghost hidden" id="loadMoreBtn">Load more</button>
        </section>
    </div>
</div>
</div>

<div class="toast-host" id="toasts"></div>

<script>
window.LVS = {
    csrf: <?php echo json_encode($csrf); ?>,
    hasKey: <?php echo $hasKey ? 'true' : 'false'; ?>,
    pollInterval: <?php echo (int) $config['poll_interval_seconds']; ?>,
    generationTimeout: <?php echo (int) $config['generation_timeout_seconds']; ?>,
    maxUploadMb: <?php echo (int) $config['max_upload_mb']; ?>,
    version: <?php echo json_encode(LVS_VERSION); ?>
};
</script>
<script src="assets/js/app.js?v=<?php echo lvs_e(LVS_VERSION); ?>"></script>
</body>
</html>
