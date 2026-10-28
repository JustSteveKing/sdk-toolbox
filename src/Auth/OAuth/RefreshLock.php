<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Closure;

/**
 * Makes sure only one process refreshes a token at a time.
 *
 * Without it, ten workers that find the same expired token all refresh it,
 * and with refresh-token rotation (RFC 9700 section 4.14.2) all but the
 * first are using a refresh token the server has already retired. Adapt
 * whatever lock your application has:
 *
 *     final readonly class SymfonyRefreshLock implements RefreshLock
 *     {
 *         public function __construct(private LockFactory $locks) {}
 *
 *         public function synchronized(string $key, Closure $callback): mixed
 *         {
 *             $lock = $this->locks->createLock("token-refresh.{$key}", 30);
 *             $lock->acquire(blocking: true);
 *
 *             try {
 *                 return $callback();
 *             } finally {
 *                 $lock->release();
 *             }
 *         }
 *     }
 */
interface RefreshLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function synchronized(string $key, Closure $callback): mixed;
}
