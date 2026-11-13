<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Sending\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * HTTP-API driver base (WP-14). Implements the honest default classification
 * every vendor driver starts from; drivers refine it with vendor-specific
 * error codes in their own docblocks.
 *
 * Default transient-vs-permanent table:
 *  | condition                          | classification |
 *  |------------------------------------|----------------|
 *  | connection error / timeout         | transient      |
 *  | HTTP 408 / 429 (throttled)         | transient      |
 *  | HTTP 5xx                           | transient      |
 *  | any other non-2xx (400/401/403/…)  | permanent      |
 */
abstract class AbstractApiDriver extends AbstractDriver
{
    protected function failureFromResponse(Response $response): SendResult
    {
        $error = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500);

        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            return SendResult::transientFailure($error);
        }

        return SendResult::permanentFailure($error);
    }

    protected function failureFromConnection(ConnectionException $e): SendResult
    {
        return SendResult::transientFailure($e->getMessage());
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'api',
            'attachments' => true,
            'custom_headers' => true,
            'dkim' => false, // vendor signs at their edge
        ];
    }
}
