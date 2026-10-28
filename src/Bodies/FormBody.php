<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Http\QueryString;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * `application/x-www-form-urlencoded`, encoded as QueryString encodes a
 * query, so booleans and enums go the same way in both places.
 */
final readonly class FormBody implements Body
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        private array $fields,
    ) {}

    public function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        return $streams->createStream(QueryString::build($this->fields));
    }
}
