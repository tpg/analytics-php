<?php

namespace TPG\Analytics;

use Closure;
use DateTimeInterface;
use Throwable;
use TPG\Analytics\Exceptions\AnalyticsException;
use TPG\Analytics\Exceptions\RequestFailedException;
use TPG\Analytics\Exceptions\TransportException;
use TPG\Analytics\Transport\CurlTransport;
use TPG\Analytics\Transport\Transport;

/**
 * Queues events in memory and sends them to the analytics API in batches.
 *
 * Tracking never throws. Failed batches go to the error handler, so analytics can't break your app.
 */
class Client
{
    public const string VERSION = '0.1.0';

    /**
     * The most events the API accepts in one request.
     */
    public const int MAX_BATCH_SIZE = 100;

    /**
     * Bodies larger than this many bytes are gzipped.
     */
    protected const int COMPRESS_FROM_BYTES = 1024;

    /**
     * The events waiting to be sent.
     *
     * @var list<Event>
     */
    protected array $queue = [];

    protected readonly string $endpoint;

    protected readonly Transport $transport;

    /**
     * @var (Closure(AnalyticsException, list<Event>): void)|null
     */
    protected ?Closure $onError;

    /**
     * Finds the visitor for events tracked without one.
     *
     * @var (Closure(): ?Visitor)|null
     */
    protected ?Closure $visitorResolver = null;

    /**
     * @param  string  $key  The property key, starting with "ak_".
     * @param  string  $endpoint  The API endpoint shown next to the key, such as "https://analytics.example.com/api/v1".
     * @param  int  $batchSize  How many events to queue before they are sent.
     * @param  int  $maxQueueSize  The most events to hold. The oldest are dropped beyond it.
     * @param  int  $retries  How many times a batch is resent after a network error, rate limit or server error.
     * @param  bool  $flushOnShutdown  Whether queued events are sent when the PHP process ends.
     * @param  (callable(AnalyticsException, list<Event>): void)|null  $onError  Called with each batch that couldn't be sent.
     * @param  bool  $enabled  Set to false to queue nothing and send nothing, such as in local development.
     */
    public function __construct(
        protected readonly string $key,
        string $endpoint,
        protected readonly int $batchSize = self::MAX_BATCH_SIZE,
        protected readonly int $maxQueueSize = 1000,
        protected readonly int $retries = 1,
        bool $flushOnShutdown = true,
        ?callable $onError = null,
        ?Transport $transport = null,
        protected readonly bool $enabled = true,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->transport = $transport ?? new CurlTransport;
        $this->onError = $onError === null ? null : Closure::fromCallable($onError);

        if ($flushOnShutdown) {
            register_shutdown_function(fn () => $this->flush());
        }
    }

    /**
     * Record a page being viewed.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function pageview(string $url, ?Visitor $visitor = null, ?string $referrer = null, array $properties = [], ?DateTimeInterface $occurredAt = null): void
    {
        $this->track(new Event(EventType::PageView, $this->visitor($visitor), url: $url, referrer: $referrer, properties: $properties, occurredAt: $occurredAt));
    }

    /**
     * Record a screen of an app being shown.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function screenview(string $screen, ?Visitor $visitor = null, array $properties = [], ?DateTimeInterface $occurredAt = null): void
    {
        $this->track(new Event(EventType::ScreenView, $this->visitor($visitor), screen: $screen, properties: $properties, occurredAt: $occurredAt));
    }

    /**
     * Record a custom event, such as a sign-up or purchase.
     *
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function event(string $name, array $properties = [], ?Visitor $visitor = null, ?string $url = null, ?DateTimeInterface $occurredAt = null): void
    {
        $this->track(new Event(EventType::Custom, $this->visitor($visitor), name: $name, url: $url, properties: $properties, occurredAt: $occurredAt));
    }

    /**
     * Record a request your API handled.
     *
     * @param  string|null  $route  The route pattern, such as "/users/{user}", so requests group together.
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function request(string $method, string $url, int $status, int $durationMs, ?Visitor $visitor = null, ?string $route = null, array $properties = [], ?DateTimeInterface $occurredAt = null): void
    {
        $this->track(new Event(
            EventType::Request,
            $this->visitor($visitor),
            url: $url,
            method: strtoupper($method),
            route: $route,
            status: $status,
            durationMs: max(0, $durationMs),
            properties: $properties,
            occurredAt: $occurredAt,
        ));
    }

    /**
     * Queue an event, sending the queue once it holds a full batch.
     */
    public function track(Event $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->queue[] = $event;

        if (count($this->queue) > $this->maxQueueSize) {
            $this->queue = array_slice($this->queue, -$this->maxQueueSize);
        }

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Send every queued event.
     *
     * Returns false if any batch couldn't be sent. Those events are passed to the error handler and dropped.
     */
    public function flush(): bool
    {
        $sent = true;

        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, min($this->batchSize, self::MAX_BATCH_SIZE));

            try {
                $this->sendBatch($batch);
            } catch (AnalyticsException $exception) {
                $sent = false;
                $this->report($exception, $batch);
            }
        }

        return $sent;
    }

    /**
     * Set how the visitor is found for events tracked without one, such as from the current request.
     *
     * @param  (callable(): ?Visitor)|null  $resolver
     */
    public function resolveVisitorUsing(?callable $resolver): static
    {
        $this->visitorResolver = $resolver === null ? null : Closure::fromCallable($resolver);

        return $this;
    }

    /**
     * Get the given visitor, or the resolved one when none was given.
     */
    public function visitor(?Visitor $visitor = null): Visitor
    {
        return $visitor ?? ($this->visitorResolver ? ($this->visitorResolver)() : null) ?? new Visitor;
    }

    /**
     * Get the events waiting to be sent.
     *
     * @return list<Event>
     */
    public function queued(): array
    {
        return $this->queue;
    }

    /**
     * Check the key and get the settings of its property.
     *
     * @throws AnalyticsException when the key is wrong or the API can't be reached.
     */
    public function ping(): PropertySettings
    {
        $response = $this->transport->send('GET', $this->endpoint.'/ping', $this->headers());

        if (! $response->successful()) {
            throw new RequestFailedException($response);
        }

        return PropertySettings::fromArray($response->json());
    }

    /**
     * Send one batch, retrying errors that might go away.
     *
     * @param  list<Event>  $batch
     */
    protected function sendBatch(array $batch): void
    {
        $body = (string) json_encode(['events' => array_map(fn (Event $event) => $event->toArray(), $batch)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->headers() + ['Content-Type' => 'application/json'];

        if (strlen($body) > self::COMPRESS_FROM_BYTES && function_exists('gzencode')) {
            $body = (string) gzencode($body);
            $headers['Content-Encoding'] = 'gzip';
        }

        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send('POST', $this->endpoint.'/events', $headers, $body);

                if ($response->successful()) {
                    return;
                }

                $failure = new RequestFailedException($response);
                $retryable = $response->retryable();
            } catch (TransportException $exception) {
                $failure = $exception;
                $retryable = true;
            }

            if (! $retryable || $attempt >= $this->retries) {
                throw $failure;
            }

            $this->pause($attempt++);
        }
    }

    /**
     * Wait before retrying, a little longer after each attempt.
     */
    protected function pause(int $attempt): void
    {
        usleep(min(2_000_000, 200_000 * (2 ** $attempt)));
    }

    /**
     * Get the headers every request sends.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->key,
            'Accept' => 'application/json',
            'User-Agent' => 'thepublicgood-analytics-php/'.self::VERSION,
        ];
    }

    /**
     * Pass a failed batch to the error handler, or PHP's error log without one.
     *
     * @param  list<Event>  $batch
     */
    protected function report(AnalyticsException $exception, array $batch): void
    {
        try {
            if ($this->onError !== null) {
                ($this->onError)($exception, $batch);

                return;
            }

            error_log(sprintf('[analytics] Dropped %d event(s): %s', count($batch), $exception->getMessage()));
        } catch (Throwable) {
            // An error handler that throws must not break the app.
        }
    }
}
