<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The API answered with an error status.
 *
 * Holds the response, the problem details if the body had them, and a
 * redacted snapshot of the request, never the request itself, which
 * carries its credentials. The constructor is final so that an SDK's own
 * subclasses, mapped from problem types, can always be built the same way;
 * they change their message by overriding describe(), and add accessors
 * with property hooks or methods over `$problem`.
 */
class HttpException extends RuntimeException implements SdkException
{
    public readonly int $status;

    public readonly RequestSnapshot $request;

    public readonly ResponseInterface $response;

    /**
     * Seconds the server asked to wait before trying again, from
     * `Retry-After`, on any status that sent one (429 and 503 usually).
     */
    public readonly ?float $retryAfter;

    final public function __construct(
        Response $response,
        public readonly ?ProblemDetails $problem,
        Redactor $redactor,
    ) {
        $this->status = $response->status();
        $this->request = $redactor->request($response->request());
        $this->response = $response->psr();
        $this->retryAfter = $response->retryAfter();

        parent::__construct($this->describe($response, $problem), $this->status);
    }

    /**
     * The exception's message. Override it to keep an SDK's own wording;
     * the constructor is final, so this is the place to do it.
     */
    protected function describe(Response $response, ?ProblemDetails $problem): string
    {
        $request = $response->request();
        $reason = $response->psr()->getReasonPhrase();
        $message = trim("{$request->getMethod()} {$request->getUri()->getPath()} returned {$response->status()} {$reason}");

        $explanation = match (true) {
            $problem?->detail !== null => $problem->detail,
            $problem?->title !== null => $problem->title,
            default => null,
        };

        return $explanation === null ? $message : "{$message}: {$explanation}";
    }
}
