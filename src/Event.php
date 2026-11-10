<?php

namespace TPG\Analytics;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One event, ready to be queued and sent.
 */
final readonly class Event
{
    public string $uuid;

    public DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, string|int|float|bool|null>  $properties
     */
    public function __construct(
        public EventType $type,
        public Visitor $visitor = new Visitor,
        public ?string $name = null,
        public ?string $url = null,
        public ?string $screen = null,
        public ?string $referrer = null,
        public ?string $method = null,
        public ?string $route = null,
        public ?int $status = null,
        public ?int $durationMs = null,
        public array $properties = [],
        ?DateTimeInterface $occurredAt = null,
        ?string $uuid = null,
    ) {
        $this->uuid = $uuid ?? Uuid::v4();
        $this->occurredAt = DateTimeImmutable::createFromInterface($occurredAt ?? new DateTimeImmutable)
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Get the event as the events API expects it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            ...array_filter([
                'name' => $this->name,
                'url' => $this->url,
                'screen' => $this->screen,
                'referrer' => $this->referrer === '' ? null : $this->referrer,
                'method' => $this->method,
                'route' => $this->route,
                'status' => $this->status,
                'duration_ms' => $this->durationMs,
                'properties' => $this->properties === [] ? null : $this->properties,
            ], fn (mixed $value) => $value !== null),
            ...$this->visitor->toArray(),
        ];
    }
}
