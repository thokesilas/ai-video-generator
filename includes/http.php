<?php
/**
 * HTTP client helpers (cURL preferred, pure-PHP stream fallback).
 *
 * All outbound OpenRouter traffic goes through these functions so the API key
 * never touches the browser.
 */

if (!defined('LVS_ROOT')) {
    http_response_code(500);
    exit('Direct access is not allowed.');
}

/**
 * Perform a request and buffer the response body.
 *
 * @param string      $method
 * @param string      $url
 * @param array       $headers
 * @param string|null $body
 * @param int         $timeout
 * @return array{ok:bool,status:int,body:string,error:?string}
 */
function lvs_http_request($method, $url, array $headers = array(), $body = null, $timeout = 60)
{
    $result = array('ok' => false, 'status' => 0, 'body' => '', 'error' => null);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LocalAIVideoStudio/1.0',
        );
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $result['error'] = curl_error($ch);
        } else {
            $result['body'] = (string) $resp;
            $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $result['ok'] = $result['status'] > 0;
        }
        curl_close($ch);
        return $result;
    }

    // Fallback: stream wrapper (requires allow_url_fopen).
    if (!ini_get('allow_url_fopen')) {
        $result['error'] = 'The PHP cURL extension is not enabled and allow_url_fopen is off.';
        return $result;
    }

    $ctxOptions = array(
        'http' => array(
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ),
    );
    if ($body !== null) {
        $ctxOptions['http']['content'] = $body;
    }
    $ctx = stream_context_create($ctxOptions);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $result['error'] = 'HTTP request failed (stream fallback).';
        return $result;
    }

    $result['body'] = $resp;
    $result['ok'] = true;

    $responseHeaders = null;
    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders = http_get_last_response_headers();
    } else {
        // Read the PHP-provided local variable indirectly so PHP 8.5's
        // deprecation for direct references is not triggered.
        $headerVarName = 'http_response_header';
        if (isset($$headerVarName) && is_array($$headerVarName)) {
            $responseHeaders = $$headerVarName;
        }
    }
    if (is_array($responseHeaders)) {
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $result['status'] = (int) $m[1];
            }
        }
    }
    return $result;
}

/**
 * Proxy a remote video stream to the browser, forwarding Range requests so
 * the player can seek. Used for both preview and download endpoints.
 *
 * @param string      $url
 * @param array       $headers        Outbound request headers (incl. Authorization).
 * @param string|null $range          Incoming "Range" header value, if any.
 * @param array       $extraOutHeaders Headers to add to the client response.
 * @return array{ok:bool,status:int,error:?string}
 */
function lvs_proxy_stream($url, array $headers, $range = null, array $extraOutHeaders = array())
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'cURL is required for streaming.');
    }

    $meta = array(
        'status'         => 0,
        'content_type'   => null,
        'content_length' => null,
        'content_range'  => null,
    );
    $sentHeaders = false;
    $errorBody = '';
    $isError = false;

    if ($range !== null && $range !== '') {
        $headers[] = 'Range: ' . $range;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER         => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'LocalAIVideoStudio/1.0',
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$meta) {
            $len = strlen($header);
            $line = trim($header);
            if ($line === '') {
                return $len;
            }
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $meta['status'] = (int) $m[1];
            } elseif (stripos($line, 'content-type:') === 0) {
                $meta['content_type'] = trim(substr($line, 13));
            } elseif (stripos($line, 'content-length:') === 0) {
                $meta['content_length'] = trim(substr($line, 15));
            } elseif (stripos($line, 'content-range:') === 0) {
                $meta['content_range'] = trim(substr($line, 14));
            }
            return $len;
        },
    ));

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$meta, &$sentHeaders, &$errorBody, &$isError, $extraOutHeaders) {
        if ($meta['status'] >= 400) {
            // Buffer error responses instead of streaming them as video.
            $isError = true;
            $errorBody .= $data;
            return strlen($data);
        }

        if (!$sentHeaders) {
            $sentHeaders = true;
            $code = $meta['status'] > 0 ? $meta['status'] : 200;
            http_response_code($code);
            header('Content-Type: ' . ($meta['content_type'] ?: 'video/mp4'));
            header('Accept-Ranges: bytes');
            if ($meta['content_length'] !== null) {
                header('Content-Length: ' . $meta['content_length']);
            }
            if ($meta['content_range'] !== null) {
                header('Content-Range: ' . $meta['content_range']);
            }
            foreach ($extraOutHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $data;
        return strlen($data);
    });

    $exec = curl_exec($ch);
    $curlError = $exec === false ? curl_error($ch) : null;
    curl_close($ch);

    if ($isError) {
        return array('ok' => false, 'status' => $meta['status'], 'error' => $errorBody);
    }
    if ($exec === false && !$sentHeaders) {
        return array('ok' => false, 'status' => 0, 'error' => $curlError ?: 'Stream failed.');
    }

    return array('ok' => true, 'status' => $meta['status'], 'error' => null);
}

/**
 * Buffer a remote file into memory (used for optional local saves).
 *
 * @param string $url
 * @param array  $headers
 * @param int    $maxBytes
 * @return array{ok:bool,body:string,error:?string}
 */
function lvs_http_download($url, array $headers, $maxBytes = 268435456)
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'body' => '', 'error' => 'cURL is required for downloads.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'LocalAIVideoStudio/1.0',
        CURLOPT_MAXFILESIZE    => $maxBytes,
    ));
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $body === false ? curl_error($ch) : null;
    curl_close($ch);

    if ($body === false) {
        return array('ok' => false, 'body' => '', 'error' => $err ?: 'Download failed.');
    }
    if ($status >= 400) {
        return array('ok' => false, 'body' => '', 'error' => lvs_map_api_error($status, $body)['message']);
    }
    return array('ok' => true, 'body' => $body, 'error' => null);
}
