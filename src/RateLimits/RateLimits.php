<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\RateLimits;

use JustSteveKing\SdkToolbox\Http\StructuredFields;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads the rate-limit headers a response carries.
 *
 * The IETF draft's `RateLimit` field is preferred. It is a Structured
 * Field List with one item per policy (`"default";r=99;t=50`), joined to
 * `RateLimit-Policy` by name for the quota and window. Malformed fields
 * are ignored, as the draft requires (section 7).
 *
 * Without it, the older conventions are read: `X-RateLimit-Limit`,
 * `-Remaining` and `-Reset`, or the same without the `X-`. Their reset is
 * seconds from now in some APIs and a Unix timestamp in others (GitHub),
 * and nothing in the header says which. A value large enough to be a
 * timestamp is read as one.
 */
final class RateLimits
{
    private const int TIMESTAMP = 1_000_000_000;

    /**
     * @return list<RateLimit>
     */
    public static function read(ResponseInterface $response, ClockInterface $clock): array
    {
        if ($response->hasHeader('RateLimit')) {
            return self::ietf($response);
        }

        foreach (['X-RateLimit-', 'RateLimit-'] as $prefix) {
            if ($response->hasHeader("{$prefix}Remaining")) {
                $legacy = self::legacy($response, $prefix, $clock);

                return $legacy === null ? [] : [$legacy];
            }
        }

        return [];
    }

    /**
     * The policy closest to running out, by remaining quota and then by the
     * longest wait.
     */
    public static function mostRestrictive(ResponseInterface $response, ClockInterface $clock): ?RateLimit
    {
        $limits = self::read($response, $clock);

        usort($limits, static fn (RateLimit $a, RateLimit $b): int => [$a->remaining, -($a->resetIn ?? 0)] <=> [$b->remaining, -($b->resetIn ?? 0)]);

        return $limits[0] ?? null;
    }

    /**
     * @return list<RateLimit>
     */
    private static function ietf(ResponseInterface $response): array
    {
        $items = StructuredFields::parseList($response->getHeaderLine('RateLimit')) ?? [];
        $policies = [];

        foreach (StructuredFields::parseList($response->getHeaderLine('RateLimit-Policy')) ?? [] as $policy) {
            if (is_string($policy['value'])) {
                $policies[$policy['value']] = $policy['params'];
            }
        }

        $limits = [];

        foreach ($items as $item) {
            $name = $item['value'];
            $remaining = $item['params']['r'] ?? null;
            $reset = $item['params']['t'] ?? null;

            if (! is_string($name) || ! is_int($remaining) || $remaining < 0) {
                continue;
            }

            $policy = $policies[$name] ?? [];
            $quota = $policy['q'] ?? null;
            $window = $policy['w'] ?? null;

            $limits[] = new RateLimit(
                policy: $name,
                remaining: $remaining,
                limit: is_int($quota) && $quota >= 0 ? $quota : null,
                resetIn: is_int($reset) && $reset >= 0 ? (float) $reset : null,
                window: is_int($window) && $window > 0 ? $window : null,
                source: RateLimitSource::Ietf,
            );
        }

        return $limits;
    }

    private static function legacy(ResponseInterface $response, string $prefix, ClockInterface $clock): ?RateLimit
    {
        $remaining = self::integer($response->getHeaderLine("{$prefix}Remaining"));

        if ($remaining === null) {
            return null;
        }

        $reset = self::integer($response->getHeaderLine("{$prefix}Reset"));
        $resource = trim($response->getHeaderLine("{$prefix}Resource"));

        return new RateLimit(
            policy: $resource === '' ? 'default' : $resource,
            remaining: $remaining,
            limit: self::integer($response->getHeaderLine("{$prefix}Limit")),
            resetIn: $reset === null ? null : self::resetIn($reset, $clock),
            source: RateLimitSource::Legacy,
        );
    }

    private static function resetIn(int $reset, ClockInterface $clock): float
    {
        if ($reset < self::TIMESTAMP) {
            return (float) $reset;
        }

        // Milliseconds since the epoch, in the APIs that use them.
        $timestamp = $reset > self::TIMESTAMP * 1000 ? $reset / 1000 : $reset;

        return max(0.0, $timestamp - (float) $clock->now()->format('U.u'));
    }

    private static function integer(string $value): ?int
    {
        $value = trim($value);

        return preg_match('/^\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }
}
