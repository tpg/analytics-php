<?php

namespace TPG\Analytics\Laravel;

use Illuminate\Http\Request;
use TPG\Analytics\Visitor;
use TPG\Analytics\VisitorCookie;

/**
 * Reads the visitor from a Laravel request.
 */
final class RequestVisitor
{
    /**
     * The request attribute holding the visitor ID, including one the middleware just created.
     */
    public const string ATTRIBUTE = 'analytics-client.visitor-id';

    /**
     * Get the visitor making the request.
     */
    public static function from(Request $request, bool $identifyUsers = false): Visitor
    {
        $anonymousId = $request->attributes->get(self::ATTRIBUTE);

        $visitor = new Visitor(
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            anonymousId: is_string($anonymousId) ? $anonymousId : VisitorCookie::read($request->cookies->all()),
        );

        if ($identifyUsers && ($user = $request->user()) !== null) {
            $id = $user->getAuthIdentifier();

            return $visitor->withUserId(is_scalar($id) ? (string) $id : null);
        }

        return $visitor;
    }
}
