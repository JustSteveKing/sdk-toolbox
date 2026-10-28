<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JsonException;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Http\Uri;

/**
 * An RFC 9457 problem details object, read the way the RFC tells a client
 * to read one.
 *
 * - `type` is the problem's identifier (section 3.1.1). It defaults to
 *   `about:blank`, a relative reference is resolved against the request
 *   URI, and it is never fetched.
 * - A member with the wrong JSON type is ignored, as if it weren't there
 *   (section 3.1).
 * - Anything else is an extension member, kept as-is, for an SDK to read if
 *   it knows the type (section 3.2).
 * - `status` is advisory (section 3.1.2); the response's own status wins.
 */
final readonly class ProblemDetails
{
    public const string BLANK = 'about:blank';

    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $type = self::BLANK,
        public ?string $title = null,
        public ?int $status = null,
        public ?string $detail = null,
        public ?string $instance = null,
        public array $extensions = [],
    ) {}

    /**
     * The problem details in a response, or null if the body isn't
     * `application/problem+json` or isn't a JSON object.
     */
    public static function fromResponse(Response $response): ?self
    {
        if (! $response->isProblem()) {
            return null;
        }

        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, (string) $response->request()->getUri());
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $base  the URI a relative `type` resolves against
     */
    public static function fromArray(array $data, ?string $base = null): self
    {
        $type = is_string($data['type'] ?? null) && $data['type'] !== '' ? $data['type'] : self::BLANK;
        $status = $data['status'] ?? null;

        return new self(
            type: self::resolve($type, $base),
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            status: is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
            detail: is_string($data['detail'] ?? null) ? $data['detail'] : null,
            instance: is_string($data['instance'] ?? null) ? self::resolve($data['instance'], $base) : null,
            extensions: array_diff_key($data, array_flip(['type', 'title', 'status', 'detail', 'instance'])),
        );
    }

    public function isBlank(): bool
    {
        return $this->type === self::BLANK;
    }

    public function extension(string $name): mixed
    {
        return $this->extensions[$name] ?? null;
    }

    private static function resolve(string $reference, ?string $base): string
    {
        if ($base === null || $reference === self::BLANK) {
            return $reference;
        }

        // The request URI may carry a key in its query; a resolved type never should.
        $baseUri = Uri::parse($base)?->withQuery(null)->withFragment(null)->withUserInfo(null);
        $resolved = $baseUri === null ? null : Uri::parse($reference, $baseUri);

        return $resolved?->toString() ?? $reference;
    }
}
