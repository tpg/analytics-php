<?php

namespace TPG\Analytics\Transport;

use TPG\Analytics\Exceptions\TransportException;

final class CurlTransport implements Transport
{
    public function __construct(
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
        private readonly bool $verifySsl = true,
    ) {
        //
    }

    /**
     * Send a request with cURL.
     *
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): Response
    {
        if ($method === '') {
            throw new TransportException('The request method is missing.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new TransportException('Unable to start a cURL request.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER => array_map(fn (string $name, string $value) => "{$name}: {$value}", array_keys($headers), $headers),
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            throw new TransportException('The analytics request failed: '.curl_error($handle));
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $responseBody);
    }
}
