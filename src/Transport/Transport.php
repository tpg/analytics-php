<?php

namespace ThePublicGood\Analytics\Transport;

use ThePublicGood\Analytics\Exceptions\TransportException;

/**
 * Sends HTTP requests to the analytics API.
 */
interface Transport
{
    /**
     * Send a request and return its response.
     *
     * @param  array<string, string>  $headers
     *
     * @throws TransportException when the request could not be sent or no response arrived.
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): Response;
}
