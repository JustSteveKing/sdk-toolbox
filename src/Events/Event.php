<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

/**
 * Something that happened while sending a request, dispatched through
 * PSR-14 and logged through PSR-3 when the connector has either.
 *
 * Events carry redacted request snapshots, never the PSR-7 request, so a
 * listener or a log line can't leak credentials.
 */
interface Event
{
    /**
     * A PSR-3 level, from Psr\Log\LogLevel.
     */
    public function level(): string;

    public function message(): string;

    /**
     * @return array<string, mixed>
     */
    public function context(): array;
}
