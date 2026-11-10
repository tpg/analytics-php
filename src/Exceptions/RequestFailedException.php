<?php

namespace TPG\Analytics\Exceptions;

use TPG\Analytics\Transport\Response;

/**
 * The API answered with an error.
 */
class RequestFailedException extends AnalyticsException
{
    public function __construct(public readonly Response $response, ?string $message = null)
    {
        $apiMessage = $response->json()['message'] ?? null;

        parent::__construct($message ?? sprintf(
            'The analytics API responded with %d%s',
            $response->status,
            is_string($apiMessage) ? ': '.$apiMessage : '.',
        ), $response->status);
    }
}
