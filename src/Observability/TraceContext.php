<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Observability;

use Closure;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * W3C Trace Context propagation, without OpenTelemetry.
 *
 * Give it the current `traceparent`, usually the one your application
 * received, and every outgoing request joins that trace: same trace-id and
 * flags, a new parent-id of its own, as the Recommendation requires of
 * each outgoing request (section 3.2.2.3). `tracestate` is passed on
 * unchanged. With no current trace, each request starts one.
 *
 * A request that already carries a `traceparent` is left alone. If you use
 * OpenTelemetry, use OpenTelemetrySpans instead, which propagates through
 * OTel's own propagator.
 *
 *     tracing: new TraceContext(fn () => [$request->header('traceparent'), $request->header('tracestate')]),
 */
final readonly class TraceContext implements Middleware
{
    private const string PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/';

    /**
     * @param  (Closure(): array{0: ?string, 1?: ?string})|null  $current  returns [traceparent, tracestate]
     */
    public function __construct(
        private ?Closure $current = null,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        if ($request->hasHeader('traceparent')) {
            return $next->handle($request);
        }

        [$parent, $state] = [...($this->current === null ? [] : ($this->current)()), null, null];
        $incoming = is_string($parent) ? self::parse($parent) : null;

        if ($incoming === null) {
            // Start a trace. Not sampled: nothing here records spans.
            return $next->handle($request->withHeader('traceparent', '00-'.self::id(16).'-'.self::id(8).'-00'));
        }

        [$traceId, $flags] = $incoming;
        $request = $request->withHeader('traceparent', "00-{$traceId}-".self::id(8)."-{$flags}");

        if (is_string($state) && $state !== '') {
            $request = $request->withHeader('tracestate', $state);
        }

        return $next->handle($request);
    }

    /**
     * The trace-id and flags of a valid traceparent, or null. Version ff is
     * invalid, as are all-zero ids (section 3.2.2); later versions are read
     * as version 00, as section 3.2.4 says.
     *
     * @return array{string, string}|null
     */
    public static function parse(string $traceparent): ?array
    {
        if (preg_match(self::PATTERN, strtolower(trim($traceparent)), $m) !== 1) {
            return null;
        }

        if ($m[1] === 'ff' || ($m[1] === '00' && ($m[5] ?? '') !== '') || $m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return [$m[2], $m[4]];
    }

    /**
     * @param  int<1, max>  $bytes
     */
    private static function id(int $bytes): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while (trim($id, '0') === '');

        return $id;
    }
}
