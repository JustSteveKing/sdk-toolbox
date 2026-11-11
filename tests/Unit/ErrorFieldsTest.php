<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\HttpException;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\StatusExceptions;
use JustSteveKing\SdkToolbox\Errors\TooManyRequestsException;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

function mappedBy(StatusExceptions $exceptions, Psr\Http\Message\ResponseInterface $response): HttpException
{
    $connector = new Connector('https://api.example.com', http: new FakeClient($response), retry: RetryPolicy::none(), exceptions: $exceptions);

    try {
        $connector->send(ping());
    } catch (HttpException $e) {
        return $e;
    }

    throw new RuntimeException('Nothing was raised.');
}

it('reads a flat error by field name, as Resend sends one', function (): void {
    $e = mappedBy(
        StatusExceptions::fromFields(title: 'name', detail: 'message'),
        FakeResponse::json(['statusCode' => 429, 'name' => 'daily_quota_exceeded', 'message' => 'You have reached your daily quota.'], 429),
    );

    expect($e)->toBeInstanceOf(TooManyRequestsException::class)
        ->and($e->problem?->title)->toBe('daily_quota_exceeded')
        ->and($e->problem?->detail)->toBe('You have reached your daily quota.')
        ->and($e->problem?->status)->toBe(429)
        ->and($e->problem?->type)->toBe(ProblemDetails::BLANK);
});

it('reads extensions by dotted path, as Todoist sends them', function (): void {
    $e = mappedBy(
        StatusExceptions::fromFields(title: 'error', extensions: [
            'code' => 'error_code',
            'tag' => 'error_tag',
            'event_id' => 'error_extra.event_id',
            'missing' => 'error_extra.nothing_here',
        ]),
        FakeResponse::json(['error' => 'Task not found', 'error_code' => 478, 'error_tag' => 'NOT_FOUND', 'error_extra' => ['event_id' => 'ev_1']], 404),
    );

    expect($e)->toBeInstanceOf(NotFoundException::class)
        ->and($e->problem?->title)->toBe('Task not found')
        ->and($e->problem?->extension('code'))->toBe(478)
        ->and($e->problem?->extension('tag'))->toBe('NOT_FOUND')
        ->and($e->problem?->extension('event_id'))->toBe('ev_1')
        ->and($e->problem?->extension('missing'))->toBeNull();
});

it('keeps a type when the API sends a string one', function (): void {
    $e = mappedBy(
        StatusExceptions::fromFields(title: 'error.message', type: 'error.type'),
        FakeResponse::json(['error' => ['message' => 'No such customer', 'type' => 'invalid_request_error']], 404),
    );

    expect($e->problem?->title)->toBe('No such customer')
        ->and($e->problem?->type)->toBe('invalid_request_error');
});

it('falls back to the RFC 9457 problem when the title field is missing', function (): void {
    $e = mappedBy(
        StatusExceptions::fromFields(title: 'name'),
        FakeResponse::problem(404, title: 'Not Found', detail: 'No project prj_9.'),
    );

    expect($e->problem?->title)->toBe('Not Found')
        ->and($e->problem?->detail)->toBe('No project prj_9.');
});

it('falls back when the title is not a string', function (): void {
    $e = mappedBy(StatusExceptions::fromFields(title: 'error'), FakeResponse::json(['error' => ['nested' => true]], 400));

    expect($e->problem)->toBeNull();
});

it('still raises by status for a body that is not JSON', function (): void {
    $e = mappedBy(StatusExceptions::fromFields(title: 'name'), FakeResponse::text('<html>Bad Gateway</html>', 502));

    expect($e)->toBeInstanceOf(ServerException::class)
        ->and($e->status)->toBe(502)
        ->and($e->problem)->toBeNull();
});
