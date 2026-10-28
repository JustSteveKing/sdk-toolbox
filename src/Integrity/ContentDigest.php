<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Integrity;

use JustSteveKing\SdkToolbox\Http\ByteSequence;
use JustSteveKing\SdkToolbox\Http\StructuredFields;
use Psr\Http\Message\MessageInterface;

/**
 * `Content-Digest` (RFC 9530 section 2): a Structured Field Dictionary of
 * algorithm to digest, `sha-256=:base64:`, over the message content.
 */
final class ContentDigest
{
    public static function header(string $content, DigestAlgorithm $algorithm = DigestAlgorithm::Sha256): string
    {
        return $algorithm->value.'=:'.base64_encode($algorithm->hash($content)).':';
    }

    /**
     * Whether a message's Content-Digest matches its content.
     *
     * True when every Active algorithm it lists matches, false when any
     * doesn't, and null when there's nothing to check: no header, a
     * malformed one, or only algorithms that aren't Active.
     */
    public static function verify(MessageInterface $message): ?bool
    {
        if (! $message->hasHeader('Content-Digest')) {
            return null;
        }

        $digests = StructuredFields::parseDictionary($message->getHeaderLine('Content-Digest'));

        if ($digests === null) {
            return null;
        }

        $body = $message->getBody();
        $content = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $checked = false;

        foreach ($digests as $name => $member) {
            $algorithm = DigestAlgorithm::tryFrom($name);

            if ($algorithm === null || ! $member['value'] instanceof ByteSequence) {
                continue;
            }

            if (! hash_equals($algorithm->hash($content), $member['value']->bytes)) {
                return false;
            }

            $checked = true;
        }

        return $checked ? true : null;
    }
}
