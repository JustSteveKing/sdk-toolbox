<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

/**
 * A request sent with an Idempotency-Key, so it can be retried safely.
 *
 * Experimental: draft-ietf-httpapi-idempotency-key-header-08, whose retry
 * section is still open.
 *
 * The connector generates one key per send() and keeps it for every retry
 * of that send, which is what makes a retried POST safe: the server
 * recognises the key and doesn't apply the request twice. Return a key of
 * your own to tie it to something in your application, such as an order
 * ID, so a retry after a crash reuses it too. A key must never be reused
 * with a different payload (section 2.2).
 */
interface Idempotent
{
    public function idempotencyKey(): ?string;
}
