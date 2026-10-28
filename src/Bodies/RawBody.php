<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Anything else: a string or a stream you've built, with its media type.
 */
final readonly class RawBody implements Body
{
    public function __construct(
        private string|StreamInterface $content,
        private string $contentType = 'application/octet-stream',
    ) {}

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        if (is_string($this->content)) {
            return $streams->createStream($this->content);
        }

        if ($this->content->isSeekable()) {
            $this->content->rewind();
        }

        return $this->content;
    }
}
