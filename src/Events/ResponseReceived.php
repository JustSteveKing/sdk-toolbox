<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Events;

use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

/**
 * An attempt got a response, of any status.
 */
final readonly class ResponseReceived implements Event
{
    public function __construct(
        public RequestSnapshot $request,
        public ResponseInterface $response,
        public float $duration,
        public int $attempt,
    ) {}

    public function level(): string
    {
        return $this->response->getStatusCode() >= 500 ? LogLevel::WARNING : LogLevel::DEBUG;
    }

    public function message(): string
    {
        return sprintf('%s returned %d in %d ms', $this->request, $this->response->getStatusCode(), (int) round($this->duration * 1000));
    }

    public function context(): array
    {
        return [
            'method' => $this->request->method,
            'uri' => $this->request->uri,
            'status' => $this->response->getStatusCode(),
            'duration_ms' => (int) round($this->duration * 1000),
            'attempt' => $this->attempt,
        ];
    }
}
