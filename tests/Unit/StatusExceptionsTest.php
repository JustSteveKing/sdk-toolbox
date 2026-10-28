<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\BadRequestException;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\StatusExceptions;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

/**
 * An API that answers `{"error": "...", "code": 1234}`, as most do.
 */
function ownErrors(Psr\Http\Message\ResponseInterface ...$responses): Connector
{
    return new Connector(
        'https://api.example.com',
        http: new FakeClient(...$responses),
        retry: RetryPolicy::none(),
        exceptions: new StatusExceptions(problems: function (Response $response): ?ProblemDetails {
            $body = json_decode($response->body(), true);

            return is_array($body) && is_string($body['error'] ?? null)
                ? new ProblemDetails(title: $body['error'], status: $response->status(), extensions: ['code' => $body['code'] ?? null])
                : null;
        }),
    );
}

it('carries an API\'s own error shape on the status exception, for every status', function (int $status, string $class): void {
    try {
        ownErrors(FakeResponse::json(['error' => 'Project not found', 'code' => 4004], $status))->send(ping());
        $this->fail('Expected an exception');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->problem?->title)->toBe('Project not found')
            ->and($e->problem?->extension('code'))->toBe(4004)
            ->and($e->getMessage())->toContain('Project not found');
    }
})->with([
    '400' => [400, BadRequestException::class],
    '404' => [404, NotFoundException::class],
    '503' => [503, ServerException::class],
]);

it('falls back to an RFC 9457 problem when the reader finds nothing', function (): void {
    try {
        ownErrors(FakeResponse::problem(404, title: 'Not Found', detail: 'No project prj_9.'))->send(ping());
        $this->fail('Expected a NotFoundException');
    } catch (NotFoundException $e) {
        expect($e->problem?->detail)->toBe('No project prj_9.');
    }
});

it('maps by status alone with no reader and no problem', function (): void {
    try {
        ownErrors(FakeResponse::text('<html>Bad gateway</html>', 502))->send(ping());
        $this->fail('Expected a ServerException');
    } catch (ServerException $e) {
        expect($e->problem)->toBeNull();
    }
});
