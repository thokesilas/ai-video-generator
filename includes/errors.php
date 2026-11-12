<?php
/**
 * Error translation helpers.
 *
 * The goal is to turn raw OpenRouter/provider failures into safe, useful,
 * human readable messages without leaking sensitive request data.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

/**
 * Extract the most useful message string from a JSON/text error body.
 *
 * @param mixed $body
 * @return string
 */
function lvs_extract_error_message($body)
{
    if (!is_string($body) || $body === '') {
        return '';
    }

    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        if (isset($decoded['error']) && is_array($decoded['error']) && isset($decoded['error']['message'])) {
            return (string) $decoded['error']['message'];
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }
        // Some validation errors list supported values.
        if (isset($decoded['error']['param']) && isset($decoded['error']['code'])) {
            return (string) $decoded['error']['code'];
        }
    }

    // Trim raw HTML/plain text to something short and safe.
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    if (strlen($plain) > 500) {
        $plain = substr($plain, 0, 500) . '...';
    }
    return $plain;
}

/**
 * Map an HTTP status + body from OpenRouter to a structured error.
 *
 * @param int    $status
 * @param string $body
 * @param string $context
 * @return array{code:string,message:string,http:int}
 */
function lvs_map_api_error($status, $body, $context = 'request')
{
    $detail = lvs_extract_error_message($body);
    $suffix = $detail !== '' ? ' Detail: ' . $detail : '';

    if ($status === 0) {
        return array(
            'code'    => 'network_error',
            'http'    => 0,
            'message' => 'Could not reach OpenRouter. Check your internet connection and try again.' . $suffix,
        );
    }

    switch ($status) {
        case 400:
            return array(
                'code'    => 'bad_request',
                'http'    => 400,
                'message' => 'The provider rejected the request parameters.' . $suffix,
            );
        case 401:
            return array(
                'code'    => 'invalid_key',
                'http'    => 401,
                'message' => 'Invalid or missing OpenRouter API key. Check it in Settings.' . $suffix,
            );
        case 402:
            return array(
                'code'    => 'insufficient_credits',
                'http'    => 402,
                'message' => 'Insufficient OpenRouter credits for this request. Add credits and try again.' . $suffix,
            );
        case 403:
            return array(
                'code'    => 'forbidden',
                'http'    => 403,
                'message' => 'The request was forbidden (private model, moderation or region restriction).' . $suffix,
            );
        case 404:
            return array(
                'code'    => 'not_found',
                'http'    => 404,
                'message' => 'Model or job not found. The model id may be wrong or no longer available.' . $suffix,
            );
        case 408:
        case 504:
            return array(
                'code'    => 'timeout',
                'http'    => $status,
                'message' => 'The request timed out. The provider may be under heavy load.' . $suffix,
            );
        case 429:
            return array(
                'code'    => 'rate_limited',
                'http'    => 429,
                'message' => 'Rate limited by OpenRouter/provider. Wait a moment and retry.' . $suffix,
            );
        case 503:
            return array(
                'code'    => 'provider_unavailable',
                'http'    => 503,
                'message' => 'No provider is currently available for this model. Try another model.' . $suffix,
            );
    }

    if ($status >= 500) {
        return array(
            'code'    => 'provider_error',
            'http'    => $status,
            'message' => 'The upstream provider returned an error (HTTP ' . $status . ').' . $suffix,
        );
    }

    return array(
        'code'    => 'http_' . $status,
        'http'    => $status,
        'message' => 'Request failed with HTTP ' . $status . '.' . $suffix,
    );
}
