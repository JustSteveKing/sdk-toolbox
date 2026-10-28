<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Observability;

/**
 * Which attempt of a send is in flight: the retry middleware counts, and
 * the instrumentation underneath reads it, for events and for
 * OpenTelemetry's `http.request.resend_count`.
 *
 * One per send. The connector builds its pipeline per send, so this never
 * outlives the request it counts.
 *
 * @internal
 */
final class Attempts
{
    public int $current = 1;
}
