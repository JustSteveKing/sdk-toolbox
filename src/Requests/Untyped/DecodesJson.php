<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests\Untyped;

use JustSteveKing\SdkToolbox\Http\Response;

/**
 * The whole decoded body, every field the API sent, known or not; an
 * empty array for an empty body (a 204, or a DELETE that says nothing).
 *
 * @internal
 */
trait DecodesJson
{
    /**
     * @return array<array-key, mixed>
     */
    public function result(Response $response): array
    {
        return trim($response->body()) === '' ? [] : $response->json();
    }
}
