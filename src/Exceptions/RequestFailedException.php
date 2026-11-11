<?php

namespace TPG\Trace\Exceptions;

use TPG\Trace\Transport\Response;

/**
 * The API answered with an error.
 */
class RequestFailedException extends TraceException
{
    public function __construct(public readonly Response $response, ?string $message = null)
    {
        $apiMessage = $response->json()['message'] ?? null;

        parent::__construct($message ?? sprintf(
            'The Trace API responded with %d%s',
            $response->status,
            is_string($apiMessage) ? ': '.$apiMessage : '.',
        ), $response->status);
    }
}
