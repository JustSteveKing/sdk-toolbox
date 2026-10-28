<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Signatures\Ed25519;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * Standard Webhooks (standardwebhooks.com, spec 1.0.0).
 *
 * The content signed is `webhook-id.webhook-timestamp.body`.
 *
 * - **`v1`** is HMAC-SHA256 with a `whsec_` secret.
 * - **`v1a`** is Ed25519 with a `whpk_` public key.
 * - **Key rotation:** `webhook-signature` can hold several signatures,
 *   space-separated, and any one valid signature is enough.
 * - **Replays:** the timestamp has to be within `tolerance` seconds of
 *   now, either way. Store `webhook-id`, which this returns, to drop
 *   repeats.
 */
final readonly class StandardWebhooks implements WebhookVerifier
{
    private string $scheme;

    /** @var non-empty-string */
    private string $key;

    /**
     * @param  string  $key  `whsec_...` for v1, `whpk_...` for v1a
     */
    public function __construct(
        #[SensitiveParameter]
        string $key,
        private int $tolerance = 300,
        private ClockInterface $clock = new SystemClock,
    ) {
        [$prefix, $encoded] = array_pad(explode('_', $key, 2), 2, '');
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || $decoded === '' || ! in_array($prefix, ['whsec', 'whpk'], true)) {
            throw new InvalidRequestException('A Standard Webhooks key is `whsec_` or `whpk_` followed by base64.');
        }

        $this->scheme = $prefix === 'whsec' ? 'v1' : 'v1a';
        $this->key = $prefix === 'whsec' ? $decoded : Ed25519::publicKey($decoded);
    }

    public function verify(RequestInterface $request): void
    {
        $this->check($request);
    }

    /**
     * Verifies, and returns the `webhook-id` to deduplicate on.
     *
     * @throws InvalidSignatureException
     */
    public function check(RequestInterface $request): string
    {
        $id = $request->getHeaderLine('webhook-id');
        $timestamp = $request->getHeaderLine('webhook-timestamp');
        $header = $request->getHeaderLine('webhook-signature');

        if ($id === '' || $timestamp === '' || $header === '') {
            throw new InvalidSignatureException('The webhook is missing webhook-id, webhook-timestamp or webhook-signature.');
        }

        if (preg_match('/^\d+$/', $timestamp) !== 1 || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
            throw new InvalidSignatureException('The webhook timestamp is outside the allowed tolerance.');
        }

        $content = "{$id}.{$timestamp}.".RawBody::of($request);

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $signature) {
            [$version, $encoded] = array_pad(explode(',', $signature, 2), 2, '');
            $bytes = base64_decode($encoded, true);

            if ($version !== $this->scheme || $bytes === false) {
                continue;
            }

            $valid = $this->scheme === 'v1'
                ? hash_equals(hash_hmac('sha256', $content, $this->key, true), $bytes)
                : strlen($bytes) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($bytes, $content, $this->key);

            if ($valid) {
                return $id;
            }
        }

        throw new InvalidSignatureException('No webhook signature matches.');
    }

    /**
     * Signs as a sender would, `v1,<base64>`: for testing your own handlers.
     */
    public static function sign(#[SensitiveParameter] string $secret, string $id, int $timestamp, string $body): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')), true);

        if (! str_starts_with($secret, 'whsec_') || $key === false) {
            throw new InvalidRequestException('Signing takes a `whsec_` secret.');
        }

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['scheme' => $this->scheme, 'key' => '[redacted]'];
    }
}
