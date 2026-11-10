<?php

namespace TPG\Analytics;

/**
 * The person an event belongs to, as your server sees them.
 *
 * The IP address and user agent are only used to work out a location, a device and an anonymous hash.
 * Analytics never stores them.
 */
final readonly class Visitor
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $anonymousId = null,
        public ?string $userId = null,
        public ?string $appVersion = null,
    ) {
        //
    }

    /**
     * Read the visitor from PHP's request globals, including their visitor cookie if they have one.
     *
     * @param  array<string, mixed>|null  $server
     * @param  array<string, mixed>|null  $cookies
     */
    public static function fromGlobals(string $cookieName = VisitorCookie::DEFAULT_NAME, ?array $server = null, ?array $cookies = null): self
    {
        $server ??= $_SERVER;
        $cookies ??= $_COOKIE;

        return new self(
            ip: is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null,
            userAgent: is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : null,
            anonymousId: VisitorCookie::read($cookies, $cookieName),
        );
    }

    /**
     * Get a copy of the visitor signed in as the given user.
     *
     * Analytics hashes the ID before storing it, so a database ID is fine.
     */
    public function withUserId(string|int|null $userId): self
    {
        return new self($this->ip, $this->userAgent, $this->anonymousId, $userId === null ? null : (string) $userId, $this->appVersion);
    }

    /**
     * Get a copy of the visitor with the given anonymous ID.
     */
    public function withAnonymousId(?string $anonymousId): self
    {
        return new self($this->ip, $this->userAgent, $anonymousId, $this->userId, $this->appVersion);
    }

    /**
     * Get the fields the visitor adds to an event.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'anonymous_id' => $this->anonymousId,
            'user_id' => $this->userId,
            'app_version' => $this->appVersion,
        ], fn (?string $value) => $value !== null && $value !== '');
    }
}
