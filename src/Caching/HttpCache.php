<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Caching;

use Http\Discovery\Psr17FactoryDiscovery;
use JustSteveKing\SdkToolbox\Http\HttpDate;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use NoDiscard;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A private HTTP cache (RFC 9111) over any PSR-16 store.
 *
 * - **What's stored:** 200 and 203 responses to GET, unless either side
 *   said `no-store` (section 3), and only when there's something to reuse
 *   them by: explicit freshness (`max-age`, `Expires`) or a validator
 *   (`ETag`, `Last-Modified`). Never 429 (RFC 6585 section 4), never a
 *   `Vary: *` response, never a body over `maxBodySize`.
 * - **Reuse:** a fresh response is returned without a request, with an
 *   `Age` header (section 5.1). A stale one, or one marked `no-cache`, is
 *   revalidated: the request goes out with `If-None-Match` and
 *   `If-Modified-Since` (section 4.3.1), and a 304 refreshes the stored
 *   headers and returns the stored body (section 4.3.4).
 * - **Vary:** a stored response is only used for a request that matches
 *   it on every header its `Vary` names (section 4.1).
 * - **Credentials:** entries are keyed on every credential the request
 *   carries, so one user never gets another's response, even from a
 *   shared Redis.
 * - **Invalidation:** a successful POST, PUT, PATCH or DELETE removes the
 *   stored response for its URI (section 4.4).
 *
 * `s-maxage` and `public` are for shared caches and are ignored; there is
 * no heuristic freshness, so a response without explicit freshness is
 * always revalidated before it's reused. HEAD goes straight through.
 *
 * @phpstan-type CacheEntry array{status: int, reason: string, headers: array<string, list<string>>, body: string, vary: array<string, string>, stored_at: float|int}
 */
final readonly class HttpCache implements Middleware
{
    private ResponseFactoryInterface $responses;

    private StreamFactoryInterface $streams;

    public function __construct(
        private CacheInterface $store,
        private ClockInterface $clock = new SystemClock,
        private Redactor $redactor = new Redactor,
        private int $maxBodySize = 1_048_576,
        private string $prefix = 'sdk-toolbox.http.',
        ?ResponseFactoryInterface $responses = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $this->responses = $responses ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    #[NoDiscard]
    public function withRedactor(Redactor $redactor): self
    {
        return clone ($this, ['redactor' => $redactor]);
    }

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if ($method === 'HEAD') {
            return $next->handle($request);
        }

        if ($method !== 'GET') {
            $response = $next->handle($request);

            if (! in_array($method, ['OPTIONS', 'TRACE'], true) && $response->getStatusCode() < 400) {
                $this->store->delete($this->key($request));
            }

            return $response;
        }

        $requestDirectives = CacheControl::from($request);

        if ($requestDirectives->has('no-store')) {
            return $next->handle($request);
        }

        $key = $this->key($request);
        $entry = $this->entry($key, $request);

        if ($entry !== null && ! $requestDirectives->has('no-cache') && $this->fresh($entry)) {
            return $this->toResponse($entry);
        }

        if ($entry !== null) {
            $request = $this->conditional($request, $entry);
        }

        $response = $next->handle($request);

        if ($entry !== null && $response->getStatusCode() === 304) {
            $entry = $this->freshen($entry, $response);
            $this->store->set($key, $entry);

            return $this->toResponse($entry);
        }

        return $this->maybeStore($key, $request, $response);
    }

    /**
     * @param  CacheEntry  $entry
     */
    private function fresh(array $entry): bool
    {
        $directives = CacheControl::parse($this->line($entry['headers'], 'Cache-Control'));

        if ($directives->has('no-cache')) {
            return false;
        }

        return $this->lifetime($entry) > $this->age($entry);
    }

    /**
     * Freshness lifetime (section 4.2.1): max-age, then Expires minus Date.
     *
     * @param  CacheEntry  $entry
     */
    private function lifetime(array $entry): float
    {
        $maxAge = CacheControl::parse($this->line($entry['headers'], 'Cache-Control'))->seconds('max-age');

        if ($maxAge !== null) {
            return $maxAge;
        }

        $expires = $this->line($entry['headers'], 'Expires');

        if ($expires === '') {
            return 0;
        }

        $expiresAt = HttpDate::parse($expires);
        $date = HttpDate::parse($this->line($entry['headers'], 'Date'));

        // An invalid Expires, such as "0", means already expired (5.3).
        return $expiresAt === null ? 0 : $expiresAt->getTimestamp() - ($date?->getTimestamp() ?? (int) $entry['stored_at']);
    }

    /**
     * Current age (section 4.2.3), simplified for a client: the Age the
     * response arrived with, plus how long it has been stored.
     *
     * @param  CacheEntry  $entry
     */
    private function age(array $entry): float
    {
        $age = $this->line($entry['headers'], 'Age');

        return (preg_match('/^\d+$/', $age) === 1 ? (int) $age : 0) + $this->now() - $entry['stored_at'];
    }

    /**
     * @param  CacheEntry  $entry
     */
    private function conditional(RequestInterface $request, array $entry): RequestInterface
    {
        $etag = $this->line($entry['headers'], 'ETag');
        $modified = $this->line($entry['headers'], 'Last-Modified');

        if ($etag !== '' && ! $request->hasHeader('If-None-Match')) {
            $request = $request->withHeader('If-None-Match', $etag);
        }

        if ($modified !== '' && ! $request->hasHeader('If-Modified-Since')) {
            $request = $request->withHeader('If-Modified-Since', $modified);
        }

        return $request;
    }

    /**
     * Updates stored headers from a 304 (section 4.3.4), keeping the body.
     *
     * @param  CacheEntry  $entry
     * @return CacheEntry
     */
    private function freshen(array $entry, ResponseInterface $notModified): array
    {
        $headers = $entry['headers'];

        foreach ($notModified->getHeaders() as $name => $values) {
            if (in_array(strtolower((string) $name), ['content-length', 'content-encoding', 'transfer-encoding'], true)) {
                continue;
            }

            $headers = array_filter($headers, static fn (string $stored): bool => strcasecmp($stored, (string) $name) !== 0, ARRAY_FILTER_USE_KEY);
            $headers[(string) $name] = array_values($values);
        }

        unset($headers['Age']);

        return [...$entry, 'headers' => $headers, 'stored_at' => $this->now()];
    }

    private function maybeStore(string $key, RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $directives = CacheControl::from($response);
        $vary = array_map(trim(...), explode(',', $response->getHeaderLine('Vary')));

        $storable = in_array($response->getStatusCode(), [200, 203], true)
            && ! $directives->has('no-store')
            && ! in_array('*', $vary, true)
            && ($directives->has('max-age') || $response->hasHeader('Expires') || $response->hasHeader('ETag') || $response->hasHeader('Last-Modified'));

        if (! $storable) {
            return $response;
        }

        $body = $response->getBody();
        $size = $body->getSize();

        if ($size !== null && $size > $this->maxBodySize) {
            return $response;
        }

        $content = (string) $body;

        if (strlen($content) > $this->maxBodySize) {
            return $response->withBody($this->streams->createStream($content));
        }

        $varied = [];

        foreach (array_filter($vary) as $name) {
            $varied[strtolower($name)] = $request->getHeaderLine($name);
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        $this->store->set($key, [
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'headers' => $headers,
            'body' => $content,
            'vary' => $varied,
            'stored_at' => $this->now(),
        ]);

        return $response->withBody($this->streams->createStream($content));
    }

    /**
     * The stored entry for this request, if there is one it can be used for.
     *
     * Read back defensively: the store holds plain arrays, and anything
     * else under the key is ignored rather than trusted.
     *
     * @return CacheEntry|null
     */
    private function entry(string $key, RequestInterface $request): ?array
    {
        $entry = $this->store->get($key);

        if (! is_array($entry)
            || ! is_int($entry['status'] ?? null)
            || ! is_string($entry['reason'] ?? null)
            || ! is_array($entry['headers'] ?? null)
            || ! is_string($entry['body'] ?? null)
            || ! is_array($entry['vary'] ?? null)
            || ! (is_float($entry['stored_at'] ?? null) || is_int($entry['stored_at'] ?? null))
        ) {
            return null;
        }

        foreach ($entry['vary'] as $name => $value) {
            if ($request->getHeaderLine((string) $name) !== $value) {
                return null;
            }
        }

        /** @var CacheEntry $entry */
        return $entry;
    }

    /**
     * @param  CacheEntry  $entry
     */
    private function toResponse(array $entry): ResponseInterface
    {
        $response = $this->responses->createResponse($entry['status'], $entry['reason'])
            ->withBody($this->streams->createStream($entry['body']));

        foreach ($entry['headers'] as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withHeader('Age', (string) max(0, (int) floor($this->age($entry))));
    }

    /**
     * Keyed on the URI and every credential the request carries (only GET
     * is stored, so the method needn't be part of it), hashed with SHA-256 so keys fit PSR-16 and a credential is
     * never stored in a key.
     */
    private function key(RequestInterface $request): string
    {
        $credentials = [];

        foreach ($request->getHeaders() as $name => $values) {
            if ($this->redactor->isSensitiveHeader((string) $name)) {
                $credentials[strtolower((string) $name)] = $values;
            }
        }

        ksort($credentials);

        return $this->prefix.hash('sha256', json_encode([(string) $request->getUri(), $credentials], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function line(array $headers, string $name): string
    {
        foreach ($headers as $stored => $values) {
            if (strcasecmp($stored, $name) === 0) {
                return implode(', ', $values);
            }
        }

        return '';
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
