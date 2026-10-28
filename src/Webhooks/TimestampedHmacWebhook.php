<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * An HMAC over the body and a timestamp, the scheme Stripe, Slack and Ghost
 * use, each in its own dialect. Because the timestamp is signed, a stale
 * delivery can be refused, which a plain body HMAC (HmacWebhook) can't do.
 *
 * There's no standard for it. Each sender documents three things, and this
 * takes them as configuration:
 *
 * - **Where the parts are:** a header of `key=value` pairs separated by
 *   commas (`t=1492774577,v1=5257a8...`), with the timestamp either among
 *   them or in a header of its own.
 * - **What's signed:** a template over `{timestamp}` and `{body}`, such as
 *   Stripe's `{timestamp}.{body}`.
 * - **How it's written:** the HMAC's algorithm and encoding, and whether
 *   the timestamp is in seconds or milliseconds.
 *
 * Every signature under `signatureKey` is tried, so a sender rolling its
 * secret can send two; any other key is ignored, which is how Stripe says
 * to avoid a downgrade to its test-only `v0`. Comparison is constant-time,
 * and the timestamp has to be within `tolerance` seconds of now, either way.
 * A signature doesn't make a delivery unique: deduplicate on the event's
 * own ID as well.
 */
final readonly class TimestampedHmacWebhook implements WebhookVerifier
{
    /**
     * @param  string  $header  the header holding the signature
     * @param  string  $signedPayload  what's signed, over `{timestamp}` and `{body}`
     * @param  string  $signatureKey  the key the signatures sit under, such as `v1`
     * @param  string|null  $timestampHeader  a header of its own for the timestamp; null when it's in `$header`
     * @param  string  $timestampKey  the timestamp's key in `$header`
     * @param  'sha256'|'sha512'  $algorithm
     * @param  'hex'|'base64'  $encoding
     * @param  bool  $milliseconds  whether the timestamp counts milliseconds rather than seconds
     * @param  int  $tolerance  seconds either side of now a timestamp may be; at least 1
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
        private string $header,
        private string $signedPayload = '{timestamp}.{body}',
        private string $signatureKey = 'v1',
        private ?string $timestampHeader = null,
        private string $timestampKey = 't',
        private string $algorithm = 'sha256',
        private string $encoding = 'hex',
        private bool $milliseconds = false,
        private int $tolerance = 300,
        private ClockInterface $clock = new SystemClock,
    ) {
        // Stripe warns that a tolerance of 0 turns its check off entirely;
        // here it would refuse everything, which is no better.
        if ($tolerance < 1) {
            throw new InvalidRequestException('A webhook tolerance is at least one second.');
        }

        if (! str_contains($signedPayload, '{body}') || ! str_contains($signedPayload, '{timestamp}')) {
            throw new InvalidRequestException('The signed payload has to include both {timestamp} and {body}.');
        }
    }

    /**
     * Stripe: `Stripe-Signature: t=1492774577,v1=5257a8...`, an HMAC-SHA256
     * of `{timestamp}.{body}` in hex, keyed with the `whsec_` secret as is.
     */
    public static function stripe(#[SensitiveParameter] string $secret, int $tolerance = 300, ClockInterface $clock = new SystemClock): self
    {
        return new self($secret, 'Stripe-Signature', tolerance: $tolerance, clock: $clock);
    }

    /**
     * Slack: `X-Slack-Signature: v0=a2114d...` and the timestamp in
     * `X-Slack-Request-Timestamp`, an HMAC-SHA256 of `v0:{timestamp}:{body}`
     * in hex. Slack recommends five minutes' tolerance.
     */
    public static function slack(#[SensitiveParameter] string $secret, int $tolerance = 300, ClockInterface $clock = new SystemClock): self
    {
        return new self($secret, 'X-Slack-Signature', 'v0:{timestamp}:{body}', 'v0', 'X-Slack-Request-Timestamp', tolerance: $tolerance, clock: $clock);
    }

    /**
     * Ghost: `X-Ghost-Signature: sha256=<hex>, t=<milliseconds>`, an
     * HMAC-SHA256 of the body followed directly by the timestamp.
     */
    public static function ghost(#[SensitiveParameter] string $secret, int $tolerance = 300, ClockInterface $clock = new SystemClock): self
    {
        return new self($secret, 'X-Ghost-Signature', '{body}{timestamp}', 'sha256', milliseconds: true, tolerance: $tolerance, clock: $clock);
    }

    public function verify(RequestInterface $request): void
    {
        $this->check($request);
    }

    /**
     * Verifies, and returns the signed timestamp, as the sender wrote it.
     *
     * @throws InvalidSignatureException
     */
    public function check(RequestInterface $request): int
    {
        $parts = self::parts($request->getHeaderLine($this->header));
        $signatures = $parts[$this->signatureKey] ?? [];
        $timestamp = $this->timestampHeader === null
            ? ($parts[$this->timestampKey][0] ?? '')
            : trim($request->getHeaderLine($this->timestampHeader));

        if ($signatures === [] || $timestamp === '') {
            throw new InvalidSignatureException("The webhook has no {$this->header} signature or timestamp.");
        }

        if (preg_match('/^\d{1,16}$/', $timestamp) !== 1) {
            throw new InvalidSignatureException('The webhook timestamp isn\'t a number.');
        }

        $seconds = $this->milliseconds ? intdiv((int) $timestamp, 1000) : (int) $timestamp;

        if (abs($this->clock->now()->getTimestamp() - $seconds) > $this->tolerance) {
            throw new InvalidSignatureException('The webhook timestamp is outside the allowed tolerance.');
        }

        $digest = hash_hmac($this->algorithm, strtr($this->signedPayload, ['{timestamp}' => $timestamp, '{body}' => RawBody::of($request)]), $this->secret, true);
        $expected = $this->encoding === 'hex' ? bin2hex($digest) : base64_encode($digest);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return (int) $timestamp;
            }
        }

        throw new InvalidSignatureException('No webhook signature matches.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['header' => $this->header, 'signedPayload' => $this->signedPayload, 'secret' => '[redacted]'];
    }

    /**
     * `t=1,v1=a,v1=b` as ['t' => ['1'], 'v1' => ['a', 'b']]. Values split
     * on the first `=` only, since base64 ends in them.
     *
     * @return array<string, list<string>>
     */
    private static function parts(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $element) {
            [$key, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($key !== '' && $value !== '') {
                $parts[$key][] = $value;
            }
        }

        return $parts;
    }
}
