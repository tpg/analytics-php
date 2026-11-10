<?php

namespace TPG\Analytics;

/**
 * How the property a key belongs to wants client libraries to behave, from the ping API.
 */
final readonly class PropertySettings
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $persistentVisitors,
        public string $visitorCookie = VisitorCookie::DEFAULT_NAME,
        public int $maxBatchSize = Client::MAX_BATCH_SIZE,
    ) {
        //
    }

    /**
     * Create the settings from a ping response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $property = is_array($data['property'] ?? null) ? $data['property'] : [];

        return new self(
            name: (string) ($property['name'] ?? ''),
            type: (string) ($property['type'] ?? ''),
            persistentVisitors: ($data['visitor_identification'] ?? null) === 'persistent',
            visitorCookie: is_string($data['visitor_cookie'] ?? null) ? $data['visitor_cookie'] : VisitorCookie::DEFAULT_NAME,
            maxBatchSize: is_int($data['max_batch_size'] ?? null) ? $data['max_batch_size'] : Client::MAX_BATCH_SIZE,
        );
    }

    /**
     * Get the settings in the shape the ping API uses, so they can be cached.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property' => ['name' => $this->name, 'type' => $this->type],
            'visitor_identification' => $this->persistentVisitors ? 'persistent' : 'cookieless',
            'visitor_cookie' => $this->visitorCookie,
            'max_batch_size' => $this->maxBatchSize,
        ];
    }
}
