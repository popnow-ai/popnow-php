<?php

declare(strict_types=1);

namespace PopNow;

/**
 * The seam between this client and the network.
 *
 * Swapping it is how the client is tested without a network, and how a host
 * application routes calls through its own egress proxy or instrumentation.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @param string|resource|null $body Resource bodies are streamed where the
     *                                   implementation can; see the note in
     *                                   {@see StreamTransport}.
     *
     * @throws TimeoutError   when the local deadline elapsed. Separate from a
     *                        transport failure because the request may already
     *                        have reached the server.
     * @throws TransportError when no HTTP response was produced at all and the
     *                        deadline was not the reason.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        $body,
        float $timeoutSeconds,
    ): HttpResponse;
}
