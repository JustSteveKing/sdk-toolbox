<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasHeaders;
use JustSteveKing\SdkToolbox\Requests\HasQuery;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * A GET to /ping that returns the raw response, for tests that care about
 * the connector rather than a request.
 *
 * @param  array<string, mixed>  $query
 * @param  array<string, string>  $headers
 * @return Request<Response>
 */
function ping(array $query = [], array $headers = []): Request
{
    return new readonly class($query, $headers) implements HasHeaders, HasQuery, Request
    {
        /**
         * @param  array<string, mixed>  $query
         * @param  array<string, string>  $headers
         */
        public function __construct(private array $query, private array $headers) {}

        public function method(): Method
        {
            return Method::Get;
        }

        public function path(): string
        {
            return '/ping';
        }

        public function query(): array
        {
            return $this->query;
        }

        public function headers(): array
        {
            return $this->headers;
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };
}
