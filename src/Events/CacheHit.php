<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Log\LogLevel;

/**
 * The HTTP cache answered: fresh, with no request, or revalidated, with a
 * 304 that kept the stored body.
 */
final readonly class CacheHit implements Event
{
    public function __construct(
        public RequestSnapshot $request,
        public bool $revalidated,
    ) {}

    public function level(): string
    {
        return LogLevel::DEBUG;
    }

    public function message(): string
    {
        return $this->revalidated ? "Revalidated {$this->request} from the cache (304)" : "Served {$this->request} from the cache";
    }

    public function context(): array
    {
        return ['method' => $this->request->method, 'uri' => $this->request->uri, 'revalidated' => $this->revalidated];
    }
}
