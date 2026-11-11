<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Psr\SimpleCache\CacheInterface;

/**
 * Remembers webhook delivery IDs, so a delivery sent twice is handled once.
 *
 * A valid signature doesn't make a delivery unique. A sender retries a
 * delivery until one succeeds, with the same ID each time, and Standard
 * Webhooks says to "use the `webhook-id` header as an idempotency key to
 * prevent accidentally processing the same webhook more than once"
 * ("Verifying signatures"), keeping IDs for five minutes in its example.
 * The verifiers reject a replay outside their timestamp tolerance, but
 * inside it the same delivery verifies every time. So: verify first, then
 * ask this whether the ID is new.
 *
 *     $id = $webhooks->check($request);
 *
 *     if (! $seen->firstTime($id)) {
 *         return new Response(200);   // already handled: acknowledge, do nothing
 *     }
 *
 * IDs are kept for `ttl` seconds, which must outlast the verifier's
 * tolerance on both sides of now, or a delivery could be forgotten while
 * its signature still verifies. The default, 600, covers the usual 300.
 *
 * PSR-16 has no "set if absent", so two copies arriving at the same moment
 * on two workers can both be told they're first. That's fine for most
 * handlers; where a double run would do harm (charging a card), make the
 * work itself idempotent too, such as a unique index on the event ID.
 * Needs psr/simple-cache.
 */
final readonly class SeenDeliveries
{
    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 600,
        private string $prefix = 'sdk-toolbox.webhook.',
    ) {
        if ($ttl < 1) {
            throw new InvalidRequestException('Delivery IDs need to be kept for at least a second.');
        }
    }

    /**
     * True the first time an ID is seen, and remembered from then on;
     * false every time after, until it expires.
     */
    public function firstTime(string $id): bool
    {
        $key = $this->key($id);

        if ($this->cache->has($key)) {
            return false;
        }

        $this->cache->set($key, true, $this->ttl);

        return true;
    }

    /**
     * Forget an ID, so its next delivery is handled: for when handling
     * failed after firstTime() said yes, and the sender should be let to
     * retry.
     */
    public function forget(string $id): void
    {
        $this->cache->delete($this->key($id));
    }

    /**
     * PSR-16 keys can't contain `{}()/\@:`, so the ID goes in hashed.
     */
    private function key(string $id): string
    {
        return $this->prefix.hash('xxh128', $id);
    }
}
