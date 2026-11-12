-- Local AI Video Studio - OPTIONAL database schema
--
-- You do NOT need this file. The application stores generation metadata in
-- storage/history.json by default and requires no database.
--
-- This schema is provided only for users who prefer MySQL/MariaDB. To use it:
--   1. Create a database in phpMyAdmin (e.g. "video_studio").
--   2. Import this file.
--   3. You would then need to write a storage adapter that reads/writes these
--      tables (not included, to keep the default install dependency-free).
--
-- It intentionally stores only metadata and never the API key. Local video
-- file names are stored; the files themselves live under storage/videos/.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `lvs_jobs` (
    `id`              VARCHAR(128) NOT NULL,
    `batch_id`        VARCHAR(64)  DEFAULT NULL,
    `clip_index`      INT UNSIGNED NOT NULL DEFAULT 0,
    `model`           VARCHAR(160) NOT NULL,
    `model_name`      VARCHAR(190) DEFAULT NULL,
    `prompt`          TEXT,
    `negative_prompt` TEXT,
    `duration`        INT UNSIGNED DEFAULT NULL,
    `aspect_ratio`    VARCHAR(16)  DEFAULT NULL,
    `resolution`      VARCHAR(16)  DEFAULT NULL,
    `generate_audio`  TINYINT(1)   NOT NULL DEFAULT 0,
    `status`          VARCHAR(24)  NOT NULL DEFAULT 'pending',
    `generation_id`   VARCHAR(128) DEFAULT NULL,
    `cost`            DECIMAL(10,4) DEFAULT NULL,
    `error`           TEXT,
    `local`           TINYINT(1)   NOT NULL DEFAULT 0,
    `local_files`     TEXT,
    `created_at`      INT UNSIGNED NOT NULL,
    `updated_at`      INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
