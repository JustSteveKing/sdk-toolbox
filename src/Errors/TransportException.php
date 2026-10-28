<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * No response came back: the PSR-18 client couldn't send the request or
 * couldn't read the answer.
 *
 * The client's own exception is kept as the previous one, because it's the
 * useful part when debugging. Its message is the client's, though, and some
 * clients quote the full URL in it, query string and all.
 */
final class TransportException extends RuntimeException implements SdkException
{
    public function __construct(
        string $message,
        public readonly RequestSnapshot $request,
        public readonly bool $network,
        ClientExceptionInterface $previous,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function from(ClientExceptionInterface $e, RequestInterface $request, Redactor $redactor): self
    {
        // The client's own request is the one that failed: authenticated, and
        // changed by any middleware since the connector built it.
        if ($e instanceof NetworkExceptionInterface || $e instanceof RequestExceptionInterface) {
            $request = $e->getRequest();
        }

        $snapshot = $redactor->request($request);
        $reason = $redactor->message($e->getMessage());

        return new self(
            "Could not complete {$request->getMethod()} {$request->getUri()->getPath()}: {$reason}",
            $snapshot,
            $e instanceof NetworkExceptionInterface,
            $e,
        );
    }
}
