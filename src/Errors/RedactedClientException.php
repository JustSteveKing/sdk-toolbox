<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * The HTTP client's own exception, with its credentials masked: what a
 * TransportException keeps as its previous exception.
 *
 * A client's message often quotes the full URL, query string and all, so an
 * API key sent as a query parameter would reach any log that writes out the
 * exception chain, as Laravel's and Monolog's do. This keeps what helps when
 * debugging, the client's class, code, file and line and its message with
 * credentials masked, and does the same for the client's own previous
 * exceptions. The original isn't kept, so it can't leak through a dump either.
 */
final class RedactedClientException extends RuntimeException implements ClientExceptionInterface, SdkException
{
    /** How far down a chain of previous exceptions to follow. */
    private const int DEPTH = 8;

    /**
     * @param  class-string<Throwable>  $class  the client exception this stands in for
     */
    private function __construct(
        public readonly string $class,
        string $message,
        int $code,
        string $file,
        int $line,
        ?self $previous,
    ) {
        parent::__construct($message, $code, $previous);

        $this->file = $file;
        $this->line = $line;
    }

    public static function from(Throwable $e, Redactor $redactor, int $depth = 0): self
    {
        $previous = $e->getPrevious();
        $class = $e::class;

        return new self(
            $class,
            "{$class}: {$redactor->message($e->getMessage())}",
            (int) $e->getCode(),
            $e->getFile(),
            $e->getLine(),
            $previous !== null && $depth < self::DEPTH ? self::from($previous, $redactor, $depth + 1) : null,
        );
    }
}
