<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Integrity;

use JustSteveKing\SdkToolbox\Errors\DigestMismatchException;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Content-Digest on the way out, and checked on the way back (RFC 9530).
 *
 * Every request with a body gets a Content-Digest, so the API can tell it
 * arrived whole. A response that carries one is checked, and a mismatch
 * throws.
 *
 * A digest covers the content as sent, before any decoding. A response
 * with a Content-Encoding other than identity isn't checked, because an
 * HTTP client that decompressed it has handed over different bytes from
 * the ones the digest was made over.
 */
final readonly class Digests implements Middleware
{
    public function __construct(
        private DigestAlgorithm $algorithm = DigestAlgorithm::Sha256,
        private bool $verifyResponses = true,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $body = $request->getBody();
        $content = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if ($content !== '' && ! $request->hasHeader('Content-Digest')) {
            $request = $request->withHeader('Content-Digest', ContentDigest::header($content, $this->algorithm));
        }

        $response = $next->handle($request);
        $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));

        if ($this->verifyResponses && ($encoding === '' || $encoding === 'identity') && ContentDigest::verify($response) === false) {
            throw new DigestMismatchException("The response to {$request->getMethod()} {$request->getUri()->getPath()} doesn't match its Content-Digest.");
        }

        return $response;
    }
}
