<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use JustSteveKing\SdkToolbox\Http\StructuredFields;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Signs every request with RFC 9421 HTTP Message Signatures.
 *
 * Adds `Signature-Input` and `Signature` under one label, covering the
 * components given, with `created` and `keyid` as the RFC's examples do,
 * and a `tag` naming the profile when you give one. A request that has a
 * Content-Digest also has it covered (unless `coverDigest` is false), so
 * put the Digests middleware before this one and the body is signed too.
 *
 * Authentication runs after middleware, so its headers can't be covered.
 */
final readonly class SignRequests implements Middleware
{
    /**
     * @param  list<string>  $components
     * @param  int|null  $expiresIn  seconds after creation the signature stops being valid
     */
    public function __construct(
        private SigningKey $key,
        private array $components = ['@method', '@authority', '@path', '@query'],
        private string $label = 'sig1',
        private ?string $tag = null,
        private ?int $expiresIn = null,
        private ClockInterface $clock = new SystemClock,
        private bool $coverDigest = true,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $components = $this->components;

        if ($this->coverDigest && $request->hasHeader('Content-Digest') && ! in_array('content-digest', $components, true)) {
            $components[] = 'content-digest';
        }

        $created = $this->clock->now()->getTimestamp();
        $parameters = ['created' => $created];

        if ($this->expiresIn !== null) {
            $parameters['expires'] = $created + $this->expiresIn;
        }

        $parameters['keyid'] = $this->key->id;

        if ($this->tag !== null) {
            $parameters['tag'] = $this->tag;
        }

        $base = SignatureBase::build($components, $parameters, $request);

        return $next->handle($request
            ->withHeader('Signature-Input', "{$this->label}=".StructuredFields::serializeInnerList($components, $parameters))
            ->withHeader('Signature', "{$this->label}=:".base64_encode($this->key->sign($base)).':'));
    }
}
