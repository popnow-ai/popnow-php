<?php

declare(strict_types=1);

namespace PopNow;

/** ext-curl transport. Preferred: it streams request bodies without buffering. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly float $connectTimeoutSeconds = 10.0)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('ext-curl is not loaded; use StreamTransport instead');
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportError('could not initialise a cURL handle');
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($this->connectTimeoutSeconds * 1000)),
            // Never follow redirects: the API does not send 3xx, and a signed URL's redirect
            // target is outside what the signature covers, so following one would send the bytes,
            // headers and all, somewhere unverified.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Disable 100-continue. curl sends Expect: 100-continue automatically for large bodies,
        // and a good many object-storage endpoints never answer it, costing a wasted second on
        // every upload. An empty value after the colon is curl's convention for "remove this
        // header", not for sending an empty one.
        if (!array_key_exists('expect', array_change_key_case($headers, CASE_LOWER))) {
            $headerLines[] = 'Expect:';
        }
        $options[CURLOPT_HTTPHEADER] = $headerLines;

        if (is_resource($body)) {
            // Streaming upload: a 90 MiB video never enters memory.
            $stat = fstat($body);
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $body;
            $options[CURLOPT_INFILESIZE] = is_array($stat) ? (int) $stat['size'] : -1;
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // Since PHP 8.0 a curl handle is an object freed when it goes out of scope, curl_close()
        // is a no-op, and it is deprecated from 8.5. It is not called here; the handle is reclaimed
        // along with $handle.

        if ($raw === false || $errorNumber !== 0) {
            // 28 is CURLE_OPERATION_TIMEOUTED. It has to be thrown separately from other
            // transport failures because the two mean opposite things to a caller: a timeout means
            // the request may well have arrived and the outcome is unknown, whereas a refused
            // connection (7) or a failed TLS handshake (35) means it never went out at all - and
            // only the latter is safe to resend without an idempotency key. The literal is used
            // rather than the constant name: the number is stable in libcurl, while the constant
            // has two spellings across PHP versions.
            if ($errorNumber === 28) {
                throw new TimeoutError(sprintf(
                    'the request exceeded the local %.1fs timeout (curl error 28: %s)',
                    $timeoutSeconds,
                    $errorMessage === '' ? 'unknown' : $errorMessage,
                ));
            }
            // The curl error number goes into the message to tell the remaining failures apart.
            // The URL does not: a signed URL contains credentials.
            throw new TransportError(sprintf(
                'network request failed (curl error %d: %s)',
                $errorNumber,
                $errorMessage === '' ? 'unknown' : $errorMessage,
            ));
        }

        return new HttpResponse($status, $responseHeaders, is_string($raw) ? $raw : '');
    }
}
