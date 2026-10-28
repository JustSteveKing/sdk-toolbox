<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A request body: its media type, and its content as a PSR-7 stream.
 *
 * Streams come back seekable, so a body can be sent again on a retry.
 */
interface Body
{
    public function contentType(): string;

    public function stream(StreamFactoryInterface $streams): StreamInterface;
}
