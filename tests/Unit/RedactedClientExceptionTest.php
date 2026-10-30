<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\RedactedClientException;
use JustSteveKing\SdkToolbox\Errors\SdkException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A client that fails the way Guzzle's does: its message quotes the whole
 * URL, key included, and the cause underneath does too.
 */
function failingClient(string $key): FakeClient
{
    return new FakeClient(static function (RequestInterface $request) use ($key): never {
        $cause = new RuntimeException("curl: (6) Could not resolve host for https://api.example.com/ping?api_key={$key}", 6);

        throw new class($request, "cURL error 6: Could not resolve host (see https://api.example.com/ping?api_key={$key})", $cause) extends RuntimeException implements NetworkExceptionInterface
        {
            public function __construct(private readonly RequestInterface $request, string $message, Throwable $previous)
            {
                parent::__construct($message, previous: $previous);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    });
}

function transportFailure(string $key): TransportException
{
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', $key), http: failingClient($key), retry: RetryPolicy::none());

    try {
        $connector->response(ping());
    } catch (TransportException $e) {
        return $e;
    }

    throw new LogicException('Expected a TransportException.');
}

it('keeps the client exception as the previous one, with the key masked', function (): void {
    $previous = transportFailure('s3cr3t-key')->getPrevious();

    expect($previous)->toBeInstanceOf(RedactedClientException::class)
        ->and($previous)->toBeInstanceOf(ClientExceptionInterface::class)
        ->and($previous)->toBeInstanceOf(SdkException::class)
        ->and($previous?->getMessage())->not->toContain('s3cr3t-key')
        ->and($previous?->getMessage())->toContain('api_key=%5Bredacted%5D')
        ->and($previous?->getMessage())->toContain(': cURL error 6');
});

it('keeps what helps when debugging: the class, the code, the file and the line', function (): void {
    $previous = transportFailure('s3cr3t-key')->getPrevious();
    assert($previous instanceof RedactedClientException);

    expect($previous->class)->toStartWith('RuntimeException@anonymous')
        ->and($previous->getFile())->toEndWith('RedactedClientExceptionTest.php')
        ->and($previous->getLine())->toBeGreaterThan(0);
});

it("masks the client exception's own previous exceptions too", function (): void {
    $cause = transportFailure('s3cr3t-key')->getPrevious()?->getPrevious();

    expect($cause)->toBeInstanceOf(RedactedClientException::class)
        ->and($cause?->getMessage())->toStartWith('RuntimeException: curl: (6)')
        ->and($cause?->getMessage())->not->toContain('s3cr3t-key')
        ->and($cause?->getCode())->toBe(6);
});

it('never has the key anywhere in the chain, its dump or its string form', function (): void {
    $e = transportFailure('s3cr3t-key');
    $chain = [];

    for ($link = $e; $link !== null; $link = $link->getPrevious()) {
        $chain[] = $link->getMessage();
    }

    expect(implode("\n", $chain))->not->toContain('s3cr3t-key')
        ->and((string) $e)->not->toContain('s3cr3t-key')
        ->and(print_r($e, true))->not->toContain('s3cr3t-key');
});

it('stops following a chain after eight links', function (): void {
    $deep = new RuntimeException('bottom');

    foreach (range(1, 20) as $i) {
        $deep = new RuntimeException("link {$i}", previous: $deep);
    }

    $links = 0;

    for ($link = RedactedClientException::from($deep, new Redactor); $link !== null; $link = $link->getPrevious()) {
        $links++;
    }

    expect($links)->toBe(9);
});
