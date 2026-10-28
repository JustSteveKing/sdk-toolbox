<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use JustSteveKing\SdkToolbox\Bodies\EncodesJson;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Responses to queue on a FakeClient.
 */
final class FakeResponse
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string|list<string>>  $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::make($status, ['Content-Type' => 'application/json', ...$headers], EncodesJson::encode($data));
    }

    /**
     * An RFC 9457 problem details response.
     *
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string|list<string>>  $headers
     */
    public static function problem(
        int $status,
        string $type = 'about:blank',
        ?string $title = null,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): ResponseInterface {
        $body = array_filter(
            ['type' => $type, 'title' => $title, 'status' => $status, 'detail' => $detail],
            static fn (mixed $value): bool => $value !== null,
        );

        return self::make($status, ['Content-Type' => 'application/problem+json', ...$headers], EncodesJson::encode([...$body, ...$extensions]));
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return self::make($status, ['Content-Type' => 'text/plain', ...$headers], $body);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        return self::make($status, $headers, '');
    }

    /**
     * A network failure, thrown when the fake reaches it in its queue.
     *
     * @return Closure(RequestInterface): never
     */
    public static function networkError(string $message = 'Connection refused'): Closure
    {
        return static fn (RequestInterface $request): never => throw new FakeNetworkException($request, $message);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private static function make(int $status, array $headers, string $body): ResponseInterface
    {
        $response = Psr17FactoryDiscovery::findResponseFactory()
            ->createResponse($status)
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
