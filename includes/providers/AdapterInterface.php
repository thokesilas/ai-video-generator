<?php
/**
 * Provider adapter contract.
 *
 * The application is built around this interface so that additional video
 * providers can be added later without touching the frontend or endpoints.
 * Only the OpenRouter adapter ships today.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

interface LvsVideoProviderAdapter
{
    /**
     * Human readable provider name.
     *
     * @return string
     */
    public function getName();

    /**
     * List video generation models with normalized capabilities.
     *
     * @param bool $forceRefresh
     * @return array{ok:bool,models:array,cached:bool,error:?array}
     */
    public function listModels($forceRefresh = false);

    /**
     * Submit a generation job.
     *
     * @param array $params Normalized generation parameters.
     * @return array{ok:bool,job:array,error:?array}
     */
    public function submit(array $params);

    /**
     * Fetch the current status of a job.
     *
     * @param string $jobId
     * @return array{ok:bool,job:array,error:?array}
     */
    public function getJob($jobId);

    /**
     * Build the upstream content URL for a job output.
     *
     * @param string $jobId
     * @param int    $index
     * @return string
     */
    public function contentUrl($jobId, $index = 0);

    /**
     * Outbound headers (including authorization) for content requests.
     *
     * @return array
     */
    public function authHeaders();
}
