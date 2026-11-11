<?php

namespace TPG\Trace\Transport;

use TPG\Trace\Exceptions\TransportException;

/**
 * Records requests instead of sending them, for your application's tests.
 */
final class FakeTransport implements Transport
{
    /**
     * The requests sent so far.
     *
     * @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}>
     */
    public array $requests = [];

    /**
     * The responses to return, in order. Once they run out, every request is accepted.
     *
     * @var list<Response|TransportException>
     */
    private array $responses;

    public function __construct(Response|TransportException ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $response = array_shift($this->responses) ?? new Response(202, '{"queued":0}');

        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    }

    /**
     * Get every event sent so far, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->requests as $request) {
            if ($request['body'] === null) {
                continue;
            }

            $body = ($request['headers']['Content-Encoding'] ?? null) === 'gzip'
                ? (string) gzdecode($request['body'])
                : $request['body'];

            $decoded = json_decode($body, true);

            foreach (is_array($decoded) ? $decoded['events'] ?? [] : [] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
