<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Integrity\ContentDigest;
use JustSteveKing\SdkToolbox\Signatures\MessageVerifier;
use Psr\Http\Message\RequestInterface;

/**
 * A webhook signed with RFC 9421 HTTP Message Signatures.
 *
 * The signature only covers the body through `content-digest`, so this
 * requires that component and checks the digest against the body too
 * (RFC 9530 section 6.3 pairs the two for exactly this).
 */
final readonly class SignedWebhook implements WebhookVerifier
{
    public function __construct(
        private MessageVerifier $verifier,
    ) {}

    public function verify(RequestInterface $request): void
    {
        $signature = $this->verifier->verify($request);

        if (! in_array('content-digest', $signature->components, true)) {
            throw new InvalidSignatureException('The webhook signature doesn\'t cover content-digest, so the body is unsigned.');
        }

        if (ContentDigest::verify($request) !== true) {
            throw new InvalidSignatureException('The webhook body doesn\'t match its Content-Digest.');
        }
    }
}
