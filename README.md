# Cakta.co.za

# 

# Local AI Video Studio

A lightweight, local AI video-generation website that runs on **XAMPP** (Apache + PHP). It generates videos through **OpenRouter's** asynchronous video API using a PHP backend, so your API key never leaves the server. No Node.js, npm, Python, Docker, Composer, build step or GPU is required, and no database is needed.

---

## 1. What this is (and what it is not)

**It is:**

- A real PHP backend that talks to the documented OpenRouter video API (`POST /api/v1/videos`, poll `GET /api/v1/videos/{id}`, download `GET /api/v1/videos/{id}/content`).
- Capability-aware: it reads the live model list, including each model's **supported durations, resolutions, aspect ratios, audio and frame-image support** rather than guessing.
- Honest: it never fakes progress or returns placeholder videos. If a provider error or limitation occurs, you see the real message.

**It is not:**

- A local model runner. All generation happens remotely at OpenRouter.
- A magic 20-second button. Most models only generate short clips in one request; see [§6](#6-the-20-second-target).
- A public-hosting-ready application. It is a local development tool (see [§10](#10-security)).

---

## 2. Requirements

- **XAMPP** (Windows/macOS/Linux). PHP 7.4+ recommended (tested up to PHP 8.5). No Composer.
- Required PHP extensions (all shipped enabled in a standard XAMPP):
    - **cURL** — for API requests (or `allow_url_fopen` as a fallback).
    - **fileinfo** — real MIME-type checks on uploads.
    - **json** — always on.
    - **session** — CSRF tokens.
    - `gd` is optional (diagnostics only).
- An **OpenRouter account with credits** and an API key from [https://openrouter.ai/keys](https://openrouter.ai/keys).

The Settings page shows a diagnostics panel telling you exactly which extensions are available and whether `storage/` and `config/` are writable.

> Video generation on OpenRouter is billable and **not eligible for Zero Data Retention**. The provider briefly retains output so you can download it. Nothing is generated until you press **Generate video**.

---

## 3. Installation

1. Copy the whole project folder into your XAMPP web root, for example:\
   C:\\xampp\\htdocs\\local-video-studio\\
2. Start **Apache** from the XAMPP Control Panel.
3. Open [http://localhost/local-video-studio/](http://localhost/local-video-studio/) in your browser.

That's it. No terminal commands, no package installation, no compilation.

If the page reports that cURL is missing and `allow_url_fopen` is off, enable `extension=curl` in `C:\xampp\php\php.ini` and restart Apache.

---

## 4. Configure your OpenRouter key

1. Go to **Settings**.
2. Paste your key (`sk-or-...`) into **New API key** and click **Save key**.
3. Click **Test connection**.

The key is written to `config/config.php` (a PHP file that returns an array, so even a misconfigured server would execute it and leak nothing). That file:

- is blocked from web access by `config/.htaccess`,
- is ignored by git via `.gitignore`,
- is **never** sent to the browser or written to logs. Settings only ever show a masked form such as `sk-or-************1234`.

**Test connection** deliberately calls `GET /api/v1/key`, which requires a valid key, so it honestly reports an invalid key. (The video *models* listing is public, so it is not a valid key check on its own.)

---

## 5. Choosing a model

Open the **Model** dropdown (populated from `GET /api/v1/videos/models`). The list only contains true generator models that publish controllable durations; pure editing, upscaling and avatar-relip models are filtered out because they cannot serve a text/image-to-video duration flow.

Examples of what the API currently exposes (values change over time — always trust the dropdown/capability chips):

| Model | Durations (s) | Resolutions | Audio | Image-to-video |
| --- | --- | --- | --- | --- |
| `google/veo-3.1` | 4, 6, 8 | 720p, 1080p, 4K | yes | first + last frame |
| `google/veo-3.1-fast` | 4, 6, 8 | 720p, 1080p, 4K | yes | first + last frame |
| `alibaba/wan-3.0` | 2–30 | 480p, 720p, 1080p | yes | first + last frame |
| `bytedance/seedance-2.5` | 4–30 | 480p, 720p | yes | first + last frame |
| `kwaivgi/kling-video-o1` | 5, 10 | 720p | yes | first + last frame |
| `x-ai/grok-imagine-video` | 1–15 | 480p, 720p | unknown | first frame |

The app validates your duration, resolution and aspect ratio against the selected model *before* submitting, and shows the provider's own list of accepted values if you get it wrong.

**Custom model ID:** under the dropdown you can type any model id (e.g. a brand-new model that is not yet in the list). When you do, it overrides the dropdown. If the id is not in OpenRouter's video model list, capabilities are unknown, so the app skips model-specific validation, labels the result with a warning, and passes the request through so the provider's own error (if any) is shown. Selecting from the dropdown clears the custom field.

---

## 6. The 20-second target

OpenRouter video models differ in the clip lengths they accept. A few (e.g. `wan-3.0`, `seedance-2.5`) accept up to 20–30 seconds in **one** request. Many others are limited to short clips (Veo 3.1 is 4/6/8 s, Kling O1 is 5/10 s).

When your target exceeds a model's longest clip:

1. The app computes a **plan** — e.g. Veo 3.1 for a 20 s target becomes `8 + 8 + 4 s` (three separate jobs), and shows an estimated cost per clip and total.
2. You decide how to run it:
    - **Generate video** submits **one** clip at a time.
    - Tick **Automatically generate all clips for the target duration** to chain the remaining clips automatically. This is opt-in so you are never surprised by costs.
3. Optionally enable **Use continuation** to pass the previous job id as `previous_job_id`. Only models that support continuation accept it; if the provider rejects it, turn it off and the clips are generated independently.

**Important and honest:** separate clips are independent generations. They are **not** one seamless 20-second video. Stitching them into a single file requires an external tool such as **FFmpeg**, which is intentionally **not** required by this app. If no available model can produce what you asked for, the app says so rather than fabricating a result.

---

## 7. Other features

- **Frames (image-to-video):** upload a **first frame** and/or a **last frame** (PNG/JPEG/WebP, validated by real MIME type and decoded dimensions). Each is sent as a `frame_images` entry (`frame_type` `first_frame` / `last_frame`). The app checks the model's advertised `supported_frame_images` and rejects frames the model does not support. Uploads are stored under a random token — original file names are never used. (OpenRouter's `frame_images` accepts exactly first and last frame.)
- **Continue a video:** on any completed clip (in the Progress list or Recent generations) press **Continue**. The next Generate extends that video by sending its job id as `previous_job_id` (the model is switched to the one that produced it, since continuation runs on the same model/endpoint). A banner shows what you are continuing from; press **Cancel** to stop. Only models that support continuation accept `previous_job_id` — the provider's error is shown if not.
- **Mobile friendly:** the studio is responsive down to small phone viewports (tested layout targeting \~360 px wide, e.g. an Oppo A54); panels stack, actions become full-width, and inputs use 16 px text to avoid browser zoom.
- **Negative prompt:** stored with the job, but **not auto-forwarded**, because it is not part of OpenRouter's normalized video schema. Use the **Provider passthrough (JSON)** box for model-specific fields (e.g. Veo's `negativePrompt` via `provider.options`).
- **Cost estimates:** derived from each model's `pricing_skus`. Token-based pricing (e.g. Seedance) cannot be converted to a per-second rate, so the app shows **"unknown"** instead of a made-up number.
- **Preview \& download:** completed videos are streamed through `api/stream.php` and `api/download.php`, so the API key stays server-side. Seeking is supported via HTTP Range. A completed video is marked **remote**; use **Save locally** to keep a copy under `storage/videos/` (subject to the storage limit).
- **History:** metadata only, stored in `storage/history.json`. Listing history never hits OpenRouter, so opening the page costs nothing. You can delete individual entries, clear everything, or delete just the local copy.

---

## 8. How it decides status

The UI distinguishes: submitted → queued (`pending`) → generating (`in_progress`) → `completed` / `failed` / `cancelled` / `expired`, plus **timed out while polling** (the browser stops polling after `generation_timeout_seconds` but does not declare failure). Completion is only ever reported from the provider's own poll response. No fake progress percentages are shown — because the provider does not expose one.

---

## 9. Resource use

- No AI runs locally. Your machine only serves HTML/CSS/JS, makes PHP API calls, and plays video.
- Video previews are lazy: the `<video>` element only gets a `src` when you open a result.
- History is paginated; videos stay remote unless you explicitly save them.
- Only metadata is stored by default, and duplicate local files are not created (re-saving an existing output reuses the same name slot).
- Local generation requests are rate-limited (configurable, default 6/minute).

---

## 10. Security

Implemented:

- API key kept server-side only; masked in the UI; excluded from logs and responses.
- CSRF tokens on all state-changing endpoints (settings, generate, upload, history).
- Server-side validation of model slugs, job ids, aspect ratios, resolutions, durations.
- Safe uploads: size limit, real MIME check, decoded-image check, random file names, path-traversal-safe token lookup.
- `config/`, `storage/`, `includes/` and `database/` are denied by `.htaccess` (Apache 2.2 and 2.4 syntax) and also blocked by a top-level rewrite rule.
- Output escaping (`htmlspecialchars`) and `X-Content-Type-Options: nosniff`.

> This is a **local** application. Do **not** expose it to the public internet without adding authentication and server hardening first.

---

## 11. What has been tested — and what has not

Verified **without spending credits** (run locally with PHP's built-in server):

- All PHP files pass `php -l` (checked on PHP 8.5).
- Homepage and Settings pages load; assets load. No key leaks in any response.
- `config/config.php` cannot be read over HTTP (PHP executes it, returns nothing).
- Settings GET/POST, CSRF rejection without a token, key masking.
- Model listing (live), capability normalization, and filtering of non-generator models.
- Input validation: bad durations, unsupported aspect ratios, bad job ids, path traversal.
- Upload rejection of a non-image disguised with an image MIME type; a real PNG upload (with CSRF) succeeds and can be used as first and/or last frame.
- First+last frame validation per model (rejected when a model only supports `first_frame`), and that a `previous_job_id` continuation request reaches the provider.
- Error mapping for a rejected key (401), and that a failed submit leaves history empty (no fabricated completion).
- Unit tests for the clip planner and the cost estimator against real OpenRouter SKU shapes.

**Not verified (requires your real key + credits; nothing here spends anything on its own):**

- Actual generation of a clip (`POST /api/v1/videos`) and its poll/download cycle.
- Multi-clip / `previous_job_id` continuation behaviour on a specific model.
- Real image-to-video submission for a specific model.
- Saving a real completed video locally.

**Known limitations:**

- OpenRouter's video API does **not** document a cancellation endpoint, so "cancel" is not offered — only stopping local polling.
- Seamless stitching of separate clips is out of scope (FFmpeg not bundled).
- Negative prompts are not auto-forwarded (provider-specific); use passthrough JSON.

---

## 12. Project structure

```
local-video-studio/
├── index.php                 # main studio UI
├── settings.php              # API key + configuration UI
├── api/                      # JSON endpoints
│   ├── models.php            #   list video models (capabilities)
│   ├── generate.php          #   plan / submit a clip
│   ├── status.php            #   poll a job (real provider status)
│   ├── stream.php            #   stream a completed video (Range-aware)
│   ├── download.php          #   download a completed video as attachment
│   ├── history.php           #   list / delete / save-local / clear
│   ├── upload.php            #   validated image upload for image-to-video
│   ├── settings.php          #   read/save config, test connection
│   └── test.php              #   read-only connection test
├── includes/
│   ├── bootstrap.php         #   constants, session, JSON helpers, diagnostics
│   ├── config.php            #   config load/save (PHP-array file)
│   ├── security.php          #   CSRF, masking, validation, rate limiting
│   ├── http.php              #   cURL client + streaming proxy + download
│   ├── storage.php           #   JSON history + local video storage
│   ├── errors.php            #   provider error mapping
│   └── providers/
│       ├── AdapterInterface.php
│       ├── OpenRouterAdapter.php
│       └── registry.php      #   adapter factory, planner, cost estimator
├── assets/css/style.css
├── assets/js/app.js
├── assets/js/settings.js
├── config/                   # PRIVATE (denied) — config.php created on first save
├── storage/                  # PRIVATE (denied) — history.json, videos/, uploads/
├── database/schema.sql       # optional, NOT used by default (JSON storage)
├── .gitignore
└── README.md
```

---

## 13. API reference

- OpenRouter video generation guide: [https://openrouter.ai/docs/guides/overview/multimodal/video-generation](https://openrouter.ai/docs/guides/overview/multimodal/video-generation)
- Video models endpoint: `GET https://openrouter.ai/api/v1/videos/models`
- Submit: `POST https://openrouter.ai/api/v1/videos`
- Poll: `GET https://openrouter.ai/api/v1/videos/{id}`
- Content: `GET https://openrouter.ai/api/v1/videos/{id}/content?index=0`

---

## 14. Troubleshooting

| Symptom | Fix |
| --- | --- |
| "No OpenRouter API key configured" | Add a key in Settings. |
| "The API key was rejected" | Re-check the key at openrouter.ai/keys. |
| "Insufficient OpenRouter credits" | Add credits to your OpenRouter account. |
| "This model cannot produce Ns in a single request" | That is expected — use the multi-clip plan or pick a longer-duration model. |
| Video won't stream / "link may have expired" | Remote links can expire. Generate again or save locally promptly. |
| Upload rejected | Use PNG/JPEG/WebP under the configured size limit. |
| Settings won't save | Ensure `config/` is writable; check the diagnostics panel. |

```

```
