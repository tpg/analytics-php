<?php

namespace TPG\Trace;

/**
 * The first-party cookie that recognises returning visitors on properties with persistent identification.
 *
 * Only set it when the property uses persistent identification. The ping API says which mode a property uses.
 */
final class VisitorCookie
{
    public const string DEFAULT_NAME = '_a_vid';

    /**
     * How long the cookie lasts, in seconds.
     */
    public const int LIFETIME = 60 * 60 * 24 * 365;

    /**
     * Read a valid visitor ID from the given cookies.
     *
     * @param  array<string, mixed>  $cookies
     */
    public static function read(array $cookies, string $name = self::DEFAULT_NAME): ?string
    {
        $value = $cookies[$name] ?? null;

        return is_string($value) && self::isValid($value) ? $value : null;
    }

    /**
     * Make a new random visitor ID.
     */
    public static function generate(): string
    {
        return Uuid::v4();
    }

    /**
     * Determine whether the value looks like a visitor ID this library made.
     */
    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }
}
