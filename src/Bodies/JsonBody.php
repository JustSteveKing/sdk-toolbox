<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JsonSerializable;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;

/**
 * `application/json`. An empty array is sent as `{}`, because a request
 * body with nothing in it is almost always an empty object, not a list.
 */
final readonly class JsonBody implements Body
{
    /**
     * @param  array<array-key, mixed>|JsonSerializable  $data
     */
    public function __construct(
        private array|JsonSerializable $data,
    ) {}

    public function contentType(): string
    {
        return 'application/json';
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        $data = $this->data === [] ? new stdClass : $this->data;

        return $streams->createStream(EncodesJson::encode($data));
    }
}
