<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use Closure;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Http\ByteSequence;
use JustSteveKing\SdkToolbox\Http\StructuredFields;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Verifies RFC 9421 HTTP Message Signatures (section 3.2), against an
 * application's requirements (section 3.2.1).
 *
 * RFC 9421 leaves it to the application to say what a signature must
 * cover, how old it may be and which keys count, so those are required
 * here rather than left open:
 *
 *     $verifier = new MessageVerifier(
 *         keys: fn (string $keyId) => $keyId === 'partner-1' ? VerificationKey::ed25519('partner-1', $publicKey) : null,
 *         required: ['@method', '@path', 'content-digest'],
 *         maxAge: 300,
 *     );
 *
 * A signature's `alg`, if it states one, must be the key's algorithm
 * (section 3.2, step 6.5), so a key can't be used with an algorithm it
 * wasn't meant for. `expires` is enforced, and so is `created` against
 * `maxAge`, with a minute's allowance for clocks that disagree.
 */
final readonly class MessageVerifier
{
    private const int SKEW = 60;

    /**
     * @param  Closure(string): ?VerificationKey  $keys  the key for a `keyid`, or null if it isn't one you trust
     * @param  list<string>  $required  components every signature must cover
     * @param  int|null  $maxAge  seconds since `created`; null accepts any age (and a missing `created`)
     */
    public function __construct(
        private Closure $keys,
        private array $required = [],
        private ?int $maxAge = 300,
        private ?string $tag = null,
        private ClockInterface $clock = new SystemClock,
    ) {}

    /**
     * @param  string|null  $label  which signature to check; null tries each until one passes
     *
     * @throws InvalidSignatureException
     */
    public function verify(RequestInterface $request, ?ResponseInterface $response = null, ?string $label = null): VerifiedSignature
    {
        $message = $response ?? $request;
        $inputs = StructuredFields::parseDictionary($message->getHeaderLine('Signature-Input'));
        $signatures = StructuredFields::parseDictionary($message->getHeaderLine('Signature'));

        if ($inputs === null || $signatures === null || $inputs === [] || $signatures === []) {
            throw new InvalidSignatureException('The message has no valid Signature-Input and Signature fields.');
        }

        $labels = $label === null ? array_keys($inputs) : [$label];
        $failure = null;

        foreach ($labels as $candidate) {
            try {
                return $this->check((string) $candidate, $inputs, $signatures, $request, $response);
            } catch (InvalidSignatureException $e) {
                $failure = $e;
            }
        }

        throw $failure;
    }

    /**
     * @param  array<string, array{value: mixed, params: array<string, mixed>}>  $inputs
     * @param  array<string, array{value: mixed, params: array<string, mixed>}>  $signatures
     */
    private function check(string $label, array $inputs, array $signatures, RequestInterface $request, ?ResponseInterface $response): VerifiedSignature
    {
        $input = $inputs[$label] ?? null;
        $signature = $signatures[$label]['value'] ?? null;

        if ($input === null || ! is_array($input['value']) || ! $signature instanceof ByteSequence) {
            throw new InvalidSignatureException("There's no complete signature labelled [{$label}].");
        }

        $components = [];

        foreach ($input['value'] as $item) {
            if (! is_array($item) || ! is_string($item['value'] ?? null) || ($item['params'] ?? []) !== []) {
                throw new InvalidSignatureException("Signature [{$label}] covers a component this verifier can't rebuild.");
            }

            $components[] = $item['value'];
        }

        $parameters = $input['params'];
        $missing = array_diff($this->required, $components);

        if ($missing !== []) {
            throw new InvalidSignatureException("Signature [{$label}] doesn't cover ".implode(', ', $missing).'.');
        }

        $this->checkTime($label, $parameters);

        $tag = is_string($parameters['tag'] ?? null) ? $parameters['tag'] : null;

        if ($this->tag !== null && $tag !== $this->tag) {
            throw new InvalidSignatureException("Signature [{$label}] isn't tagged [{$this->tag}].");
        }

        $keyId = $parameters['keyid'] ?? null;
        $key = is_string($keyId) ? ($this->keys)($keyId) : null;

        if (! is_string($keyId) || $key === null) {
            throw new InvalidSignatureException("Signature [{$label}] names no key this verifier trusts.");
        }

        if (isset($parameters['alg']) && $parameters['alg'] !== $key->algorithm->value) {
            throw new InvalidSignatureException("Signature [{$label}] claims an algorithm its key isn't for (RFC 9421 section 3.2, step 6.5).");
        }

        if (! $key->verify(SignatureBase::build($components, $parameters, $request, $response), $signature->bytes)) {
            throw new InvalidSignatureException("Signature [{$label}] doesn't match.");
        }

        $created = $parameters['created'] ?? null;

        return new VerifiedSignature($label, $keyId, $components, is_int($created) ? $created : null, $tag);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function checkTime(string $label, array $parameters): void
    {
        $now = $this->clock->now()->getTimestamp();
        $created = $parameters['created'] ?? null;
        $expires = $parameters['expires'] ?? null;

        if (is_int($expires) && $now > $expires + self::SKEW) {
            throw new InvalidSignatureException("Signature [{$label}] has expired.");
        }

        if ($this->maxAge === null) {
            return;
        }

        if (! is_int($created)) {
            throw new InvalidSignatureException("Signature [{$label}] has no created time, so its age can't be checked.");
        }

        if ($created > $now + self::SKEW || $now - $created > $this->maxAge) {
            throw new InvalidSignatureException("Signature [{$label}] is outside the allowed age.");
        }
    }
}
