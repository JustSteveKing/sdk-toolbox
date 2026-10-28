<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Deprecation;

use DateTimeImmutable;
use JustSteveKing\SdkToolbox\Http\HttpDate;
use JustSteveKing\SdkToolbox\Http\Links;
use JustSteveKing\SdkToolbox\Http\StructuredFields;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What a response said about the end of the endpoint it came from.
 *
 * - `Deprecation` (RFC 9745) is a Structured Field Date, `@1688169599`:
 *   when the resource was or will be deprecated, past or future.
 * - `Sunset` (RFC 8594) is an HTTP-date: when it's expected to stop
 *   responding.
 * - `Link` relations `deprecation` and `sunset` point at the documentation
 *   and the policy.
 *
 * All of it is a hint (RFC 9745 section 7, RFC 8594 section 3). Nothing
 * here changes how a request is sent.
 */
final readonly class DeprecationNotice
{
    public function __construct(
        public string $method,
        public string $uri,
        public ?DateTimeImmutable $deprecatedAt,
        public ?DateTimeImmutable $sunsetAt,
        public ?string $deprecationLink = null,
        public ?string $sunsetLink = null,
    ) {}

    /**
     * The notice in a response, or null when it says nothing about either.
     */
    public static function from(RequestInterface $request, ResponseInterface $response, Redactor $redactor = new Redactor): ?self
    {
        $deprecated = $response->hasHeader('Deprecation');
        $deprecatedAt = $deprecated ? self::deprecationDate($response->getHeaderLine('Deprecation')) : null;
        $sunsetAt = $response->hasHeader('Sunset') ? HttpDate::parse($response->getHeaderLine('Sunset')) : null;

        if ($deprecatedAt === null && $sunsetAt === null && ! ($deprecated && self::flagged($response->getHeaderLine('Deprecation')))) {
            return null;
        }

        $context = (string) $request->getUri();
        $links = $response->getHeader('Link');

        return new self(
            method: $request->getMethod(),
            uri: $redactor->uri($context),
            deprecatedAt: $deprecatedAt,
            sunsetAt: $sunsetAt,
            deprecationLink: Links::find($links, $context, 'deprecation')?->target,
            sunsetLink: Links::find($links, $context, 'sunset')?->target,
        );
    }

    public function isSunset(DateTimeImmutable $now): bool
    {
        return $this->sunsetAt !== null && $this->sunsetAt <= $now;
    }

    public function describe(): string
    {
        $parts = ["{$this->method} {$this->uri} is deprecated"];

        if ($this->deprecatedAt !== null) {
            $parts[0] .= ' as of '.$this->deprecatedAt->format('Y-m-d');
        }

        if ($this->sunsetAt !== null) {
            $parts[] = 'and stops responding after '.$this->sunsetAt->format('Y-m-d');
        }

        if ($this->deprecationLink !== null) {
            $parts[] = "(see {$this->deprecationLink})";
        }

        return implode(' ', $parts).'.';
    }

    private static function deprecationDate(string $value): ?DateTimeImmutable
    {
        $items = StructuredFields::parseList($value);
        $date = $items !== null && count($items) === 1 ? $items[0]['value'] : null;

        return is_int($date) && str_starts_with(trim($value), '@') ? new DateTimeImmutable("@{$date}") : null;
    }

    /**
     * Drafts before RFC 9745 sent `Deprecation: true` or `?1` with no date;
     * still deprecated, just undated.
     */
    private static function flagged(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '?1'], true);
    }
}
