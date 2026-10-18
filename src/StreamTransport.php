<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Stream-wrapper transport for builds without ext-curl.
 *
 * Two ways it differs from {@see CurlTransport}, both of which can take a
 * process or a deployment down quietly:
 *
 *  - the `http://` wrapper has no streaming request body, so a resource body is
 *    read into memory in full. A 90 MiB upload then needs a `memory_limit`
 *    above 90 MiB. Use cURL for uploads whenever ext-curl exists.
 *  - it ignores the `http_proxy` / `https_proxy` environment variables that
 *    libcurl honours. Behind an egress proxy, cURL works and this does not; set
 *    the `proxy` context option below if that is where you are.
 */
final class StreamTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
            if ($body === false) {
                throw new TransportError('could not read the request body stream');
            }
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if (is_string($body)) {
            $headerLines[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => is_string($body) ? $body : '',
                'timeout' => $timeoutSeconds,
                // Without this, a 4xx or 5xx makes file_get_contents return false and emit a
                // warning, and the error body - code and request_id included - is lost entirely.
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseHeaderLines = [];
        $startedAt = microtime(true);
        // Warnings are suppressed with @ and the return value is used instead: a warning would
        // write the upstream address into the log.
        //
        // fopen plus stream_get_meta_data is used rather than file_get_contents in order to obtain
        // the response headers: the magic local $http_response_header is deprecated from PHP 8.5,
        // and its deprecation notice is emitted at compile time - `php -l` alone produces it, so no
        // runtime branch such as function_exists can hold it back. Switching to
        // http_get_last_response_headers() would cut support down to 8.5 and above.
        $stream = @fopen($url, 'rb', false, $context);
        $raw = false;
        if ($stream !== false) {
            $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            if (is_array($wrapperData)) {
                $responseHeaderLines = $wrapperData;
            }
            $raw = stream_get_contents($stream);
            fclose($stream);
        }
        if ($raw === false && $responseHeaderLines === []) {
            // The stream wrapper reports no error number, so the only usable signal is "no
            // response at all, yet the entire timeout budget was consumed". This is best effort
            // rather than a precise determination, and the cost of being wrong is asymmetric, so
            // borderline cases are counted as timeouts: mistaking a timeout for a connection
            // failure would let a caller believe the request never went out and resend it without
            // a key, whereas the opposite merely costs one automatic retry.
            if (microtime(true) - $startedAt >= $timeoutSeconds) {
                throw new TimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout (stream wrapper)',
                    $timeoutSeconds,
                ));
            }
            throw new TransportError('network request failed (stream wrapper)');
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($responseHeaderLines as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $line, $matches) === 1) {
                // Take the last status line: through a proxy there may be a 1xx or CONNECT line
                // ahead of it.
                $status = (int) $matches[1];
                $responseHeaders = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return new HttpResponse($status, $responseHeaders, is_string($raw) ? $raw : '');
    }
}
