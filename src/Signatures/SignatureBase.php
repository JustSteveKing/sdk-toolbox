<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Http\StructuredFields;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the signature base (RFC 9421 section 2.5): one line per covered
 * component, `"name": value`, then `"@signature-params"` with no newline
 * after it.
 *
 * Supported components: HTTP fields by their lowercased name, and the
 * derived components `@method`, `@target-uri`, `@authority`, `@scheme`,
 * `@request-target`, `@path`, `@query` and, for a response, `@status`.
 * Component parameters (`;sf`, `;key`, `;bs`, `;req`, `;name`) aren't
 * supported, and a signature that uses them is refused rather than
 * checked against the wrong base.
 */
final class SignatureBase
{
    /**
     * @param  list<string>  $components
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidSignatureException when a component is missing, repeated or unsupported
     */
    public static function build(array $components, array $parameters, RequestInterface $request, ?ResponseInterface $response = null): string
    {
        if (count($components) !== count(array_unique($components))) {
            throw new InvalidSignatureException('A component is covered twice (RFC 9421 section 2.5).');
        }

        $lines = [];

        foreach ($components as $component) {
            $lines[] = StructuredFields::serializeString($component).': '.self::value($component, $request, $response);
        }

        $lines[] = '"@signature-params": '.StructuredFields::serializeInnerList($components, $parameters);

        return implode("\n", $lines);
    }

    private static function value(string $component, RequestInterface $request, ?ResponseInterface $response): string
    {
        $uri = $request->getUri();

        if (str_starts_with($component, '@')) {
            return match ($component) {
                '@method' => $request->getMethod(),
                '@target-uri' => (string) $uri,
                '@authority' => self::authority($request),
                '@scheme' => strtolower($uri->getScheme()),
                '@request-target' => ($uri->getPath() === '' ? '/' : $uri->getPath()).($uri->getQuery() === '' ? '' : '?'.$uri->getQuery()),
                '@path' => $uri->getPath() === '' ? '/' : $uri->getPath(),
                '@query' => '?'.$uri->getQuery(),
                '@status' => $response === null
                    ? throw new InvalidSignatureException('@status only exists on a response (RFC 9421 section 2.2.9).')
                    : (string) $response->getStatusCode(),
                default => throw new InvalidSignatureException("The derived component [{$component}] isn't supported."),
            };
        }

        if ($component !== strtolower($component)) {
            throw new InvalidSignatureException("Field names in a signature are lowercase (section 2.1): [{$component}].");
        }

        $message = $response ?? $request;

        if (! $message->hasHeader($component)) {
            throw new InvalidSignatureException("The covered field [{$component}] isn't in the message.");
        }

        // Each value trimmed, then joined with ", " (section 2.1).
        return implode(', ', array_map(trim(...), $message->getHeader($component)));
    }

    /**
     * Lowercase host, default port left out (section 2.2.3).
     */
    private static function authority(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = strtolower($uri->getHost() !== '' ? $uri->getHost() : $request->getHeaderLine('Host'));
        $port = $uri->getPort();
        $default = ['http' => 80, 'https' => 443][strtolower($uri->getScheme())] ?? null;

        return $port === null || $port === $default ? $host : "{$host}:{$port}";
    }
}
