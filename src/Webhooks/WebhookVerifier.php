<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use Psr\Http\Message\RequestInterface;

/**
 * Checks that an incoming webhook came from who it says, unchanged.
 *
 * Give it the request as it arrived, a PSR-7 ServerRequest from your
 * framework included, before anything parses or re-encodes the body: every
 * scheme here signs the raw bytes.
 */
interface WebhookVerifier
{
    /**
     * @throws InvalidSignatureException
     */
    public function verify(RequestInterface $request): void;
}
