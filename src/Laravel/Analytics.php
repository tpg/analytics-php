<?php

namespace ThePublicGood\Analytics\Laravel;

use Illuminate\Support\Facades\Facade;
use ThePublicGood\Analytics\Client;
use ThePublicGood\Analytics\Exceptions\TransportException;
use ThePublicGood\Analytics\Transport\FakeTransport;
use ThePublicGood\Analytics\Transport\Response;

/**
 * @method static void pageview(string $url, ?\ThePublicGood\Analytics\Visitor $visitor = null, ?string $referrer = null, array<string, string|int|float|bool|null> $properties = [], ?\DateTimeInterface $occurredAt = null)
 * @method static void screenview(string $screen, ?\ThePublicGood\Analytics\Visitor $visitor = null, array<string, string|int|float|bool|null> $properties = [], ?\DateTimeInterface $occurredAt = null)
 * @method static void event(string $name, array<string, string|int|float|bool|null> $properties = [], ?\ThePublicGood\Analytics\Visitor $visitor = null, ?string $url = null, ?\DateTimeInterface $occurredAt = null)
 * @method static void request(string $method, string $url, int $status, int $durationMs, ?\ThePublicGood\Analytics\Visitor $visitor = null, ?string $route = null, array<string, string|int|float|bool|null> $properties = [], ?\DateTimeInterface $occurredAt = null)
 * @method static void track(\ThePublicGood\Analytics\Event $event)
 * @method static bool flush()
 * @method static list<\ThePublicGood\Analytics\Event> queued()
 * @method static \ThePublicGood\Analytics\Visitor visitor(?\ThePublicGood\Analytics\Visitor $visitor = null)
 * @method static \ThePublicGood\Analytics\PropertySettings ping()
 *
 * @see Client
 */
class Analytics extends Facade
{
    /**
     * Record events instead of sending them, and get the fake transport to inspect them.
     *
     * Pass responses to control what the API answers, in order. Every other request is accepted.
     */
    public static function fake(Response|TransportException ...$responses): FakeTransport
    {
        $transport = new FakeTransport(...$responses);
        $app = static::getFacadeApplication();
        $existing = $app?->make(Client::class);

        $client = new Client(
            key: 'ak_fake',
            endpoint: 'https://analytics.invalid/api/v1',
            flushOnShutdown: false,
            transport: $transport,
        );

        if ($existing !== null) {
            $client->resolveVisitorUsing(fn () => $existing->visitor());
        }

        static::swap($client);

        return $transport;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
