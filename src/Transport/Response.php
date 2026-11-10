<?php

namespace TPG\Analytics\Transport;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body = '',
    ) {
        //
    }

    /**
     * Determine whether the request succeeded.
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Determine whether sending the same request again might succeed.
     */
    public function retryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    /**
     * Decode the JSON body.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
