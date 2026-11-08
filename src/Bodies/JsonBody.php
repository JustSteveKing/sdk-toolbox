<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JsonSerializable;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use NoDiscard;
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

    /**
     * The same body with one more top-level member, replacing any of that
     * name. Only an object can take one, so a list is refused.
     *
     * @throws InvalidRequestException when the body isn't a JSON object
     */
    #[NoDiscard]
    public function withField(string $name, mixed $value): self
    {
        $data = $this->data instanceof JsonSerializable ? $this->data->jsonSerialize() : $this->data;
        $data = $data instanceof stdClass ? get_object_vars($data) : $data;

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidRequestException("[{$name}] can only be added to a JSON object, and this body isn't one.");
        }

        return new self([...$data, $name => $value]);
    }

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
