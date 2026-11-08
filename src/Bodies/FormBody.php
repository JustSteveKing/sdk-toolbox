<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Http\ListFormat;
use JustSteveKing\SdkToolbox\Http\QueryString;
use NoDiscard;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * `application/x-www-form-urlencoded`, encoded as QueryString encodes a
 * query, so booleans and enums go the same way in both places. Lists are
 * indexed, `ids[0]=1`, unless `lists` says the API reads them another way.
 */
final readonly class FormBody implements Body
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        private array $fields,
        private ListFormat $lists = ListFormat::Indexed,
    ) {}

    /**
     * The same body with one more field, replacing any of that name.
     */
    #[NoDiscard]
    public function withField(string $name, mixed $value): self
    {
        return new self([...$this->fields, $name => $value], $this->lists);
    }

    public function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        return $streams->createStream(QueryString::build($this->fields, $this->lists));
    }
}
