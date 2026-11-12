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
<title>Settings · <?php echo lvs_e($title); ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo lvs_e(LVS_VERSION); ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="logo">🎬</span>
        <span><?php echo lvs_e($title); ?><small>Local · OpenRouter video generation</small></span>
    </div>
    <nav>
        <a class="nav-link" href="index.php">Studio</a>
        <a class="nav-link active" href="settings.php">Settings</a>
    </nav>
</header>

<div class="wrap">
    <div class="grid">
        <div>
            <section class="panel">
                <h2>OpenRouter API key</h2>
                <div class="notice info">
                    The key is stored server-side in <code class="inline">config/config.php</code>, which is blocked from web
                    access and ignored by git. It is never sent to the browser or written to logs.
                </div>

                <label class="field">
                    <span>Current key</span>
                    <input type="text" id="maskedKey" value="<?php echo lvs_e($hasKey ? lvs_mask_key($config['openrouter_api_key']) : 'No key configured'); ?>" readonly>
                </label>

                <label class="field">
                    <span>New API key <?php echo $hasKey ? '(leave blank to keep current)' : ''; ?></span>
                    <input type="password" id="apiKey" placeholder="sk-or-..." autocomplete="off" spellcheck="false">
                </label>

                <div class="row">
                    <button class="btn primary" id="saveKeyBtn">Save key</button>
                    <button class="btn" id="testBtn" <?php echo $hasKey ? '' : 'disabled'; ?>>Test connection</button>
                    <button class="btn danger" id="clearKeyBtn" <?php echo $hasKey ? '' : 'disabled'; ?>>Clear key</button>
                </div>
                <div id="keyResult" class="hint mt"></div>
            </section>

            <section class="panel">
                <h2>Behaviour</h2>

                <div class="setting-row">
                    <div class="lbl">HTTP referer<small>Sent as HTTP-Referer to OpenRouter</small></div>
                    <input type="text" id="httpReferer" value="<?php echo lvs_e($config['http_referer']); ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Poll interval (seconds)<small>How often the browser checks job status</small></div>
                    <input type="number" id="pollInterval" min="3" max="120" value="<?php echo (int) $config['poll_interval_seconds']; ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Request timeout (seconds)<small>Per API request</small></div>
                    <input type="number" id="requestTimeout" min="5" max="300" value="<?php echo (int) $config['request_timeout_seconds']; ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Max local storage (MB)<small>0 disables local saving</small></div>
                    <input type="number" id="storageMb" min="0" max="20480" value="<?php echo (int) $config['max_local_storage_mb']; ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Max history entries<small>Older entries are dropped</small></div>
                    <input type="number" id="historyEntries" min="10" max="2000" value="<?php echo (int) $config['max_history_entries']; ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Max upload size (MB)<small>Image-to-video source images</small></div>
                    <input type="number" id="uploadMb" min="1" max="32" value="<?php echo (int) $config['max_upload_mb']; ?>">
                </div>
                <div class="setting-row">
                    <div class="lbl">Generations per minute<small>Local rate limit</small></div>
                    <input type="number" id="genPerMin" min="1" max="60" value="<?php echo (int) $config['generations_per_minute']; ?>">
                </div>

                <label class="check"><input type="checkbox" id="allowSave" <?php echo $config['allow_local_save'] ? 'checked' : ''; ?>> Allow saving videos locally</label>
                <label class="check"><input type="checkbox" id="rateLimit" <?php echo $config['enable_rate_limit'] ? 'checked' : ''; ?>> Enable local rate limiting</label>

                <button class="btn primary mt" id="saveSettingsBtn">Save settings</button>
                <div id="settingsResult" class="hint mt"></div>
            </section>
        </div>

        <div>
            <section class="panel">
                <h2>Server diagnostics</h2>
                <div id="diagList"></div>
                <button class="btn small mt" id="refreshModelsBtn">Refresh model cache</button>
                <div id="refreshResult" class="hint mt"></div>
            </section>

            <section class="panel">
                <h2>About &amp; limitations</h2>
                <div class="hint" style="line-height:1.7">
                    <p><b>Tested vs untested.</b> All non-billable parts (pages, model listing, validation, error handling, image upload
                    validation, file protection) are testable without credits. Actual clip generation can only be verified with a real
                    API key and available credits. No generation request is ever sent automatically.</p>
                    <p><b>Duration.</b> OpenRouter's video models each publish a fixed set of supported clip lengths (for example 4/6/8s or
                    5/10s). A 20-second target is split into several clips. Separate clips are independent generations and are not a
                    seamless video; stitching requires a tool such as FFmpeg.</p>
                    <p><b>Security.</b> This is a local development app. Do not expose it to the public internet without adding
                    authentication and hardening.</p>
                    <p>Version <?php echo lvs_e(LVS_VERSION); ?> · provider: OpenRouter · no database required.</p>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="toast-host" id="toasts"></div>

<script>
window.LVS = {
    csrf: <?php echo json_encode($csrf); ?>,
    diagnostics: <?php echo json_encode($diag); ?>,
    version: <?php echo json_encode(LVS_VERSION); ?>
};
</script>
<script src="assets/js/settings.js?v=<?php echo lvs_e(LVS_VERSION); ?>"></script>
</body>
</html>
