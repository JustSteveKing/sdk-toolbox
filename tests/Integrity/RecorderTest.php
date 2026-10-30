<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FixtureMismatchException;
use JustSteveKing\SdkToolbox\Testing\Recorder;
use JustSteveKing\SdkToolbox\Testing\RecordMode;
use JustSteveKing\SdkToolbox\Testing\StrayRequestException;

function fixtures(): string
{
    $directory = sys_get_temp_dir().'/sdk-toolbox-fixtures-'.bin2hex(random_bytes(4));
    mkdir($directory);

    return $directory;
}

function recorded(Recorder $http): Connector
{
    return new Connector('https://api.example.com', auth: new BearerToken('real-token'), http: $http, retry: JustSteveKing\SdkToolbox\Retries\RetryPolicy::none());
}

it('records once and replays after, with secrets masked', function (): void {
    $directory = fixtures();
    $real = new FakeClient(FakeResponse::json(['id' => 'tok_1', 'access_token' => 'live-access-token', 'nested' => ['refresh_token' => 'live-refresh']]));

    $recorder = new Recorder($directory, 'auth/token', RecordMode::Record, $real);
    expect(recorded($recorder)->response(ping(['api_key' => 'query-secret']))->json()['access_token'])->toBe('live-access-token');

    $saved = (string) file_get_contents("{$directory}/auth/token.json");
    expect($saved)->not->toContain('real-token')->not->toContain('query-secret')->not->toContain('live-access-token')->not->toContain('live-refresh');

    $replayed = recorded(new Recorder($directory, 'auth/token'))->response(ping(['api_key' => 'query-secret']));
    expect($replayed->json())->toBe(['id' => 'tok_1', 'access_token' => '[redacted]', 'nested' => ['refresh_token' => '[redacted]']]);
});

it('refuses fixture names that could leave the directory', function (string $name): void {
    expect(fn () => new Recorder(fixtures(), $name, RecordMode::Record, new FakeClient))->toThrow(InvalidRequestException::class);
})->with(['../escape', 'a/../../b', '/etc/passwd', 'a\\b', 'with space', '']);

it('fails a test whose fixture is missing, in replay mode', function (): void {
    expect(fn () => new Recorder(fixtures(), 'never-recorded'))->toThrow(StrayRequestException::class, 'SDK_FIXTURES=record');
});

it('records what is missing and replays what exists, in missing mode', function (): void {
    $directory = fixtures();
    $real = new FakeClient(FakeResponse::json(['n' => 1]));

    expect((new Recorder($directory, 'thing', RecordMode::Missing, $real))->isRecording())->toBeTrue();
    recorded(new Recorder($directory, 'thing', RecordMode::Missing, $real))->response(ping());
    expect((new Recorder($directory, 'thing', RecordMode::Missing, $real))->isRecording())->toBeFalse();
});

it('throws when the SDK sends something other than what was recorded', function (): void {
    $directory = fixtures();
    recorded(new Recorder($directory, 'flow', RecordMode::Record, new FakeClient(FakeResponse::empty(200))))->response(ping());

    $replay = recorded(new Recorder($directory, 'flow'));

    expect(fn () => $replay->response(deleteOf('/ping')))->toThrow(FixtureMismatchException::class, 'was DELETE /ping, but the fixture recorded GET /ping')
        ->and(fn () => $replay->response(ping()))->toThrow(StrayRequestException::class);
});

it('reads its mode from the environment, replay when unset', function (): void {
    putenv('SDK_FIXTURES');
    expect(RecordMode::fromEnvironment())->toBe(RecordMode::Replay);

    putenv('SDK_FIXTURES=record');
    expect(RecordMode::fromEnvironment())->toBe(RecordMode::Record);
    putenv('SDK_FIXTURES');
});

/**
 * A fixture holding one search, recorded with a live key.
 */
function searchFixture(): string
{
    $directory = fixtures();
    recorded(new Recorder($directory, 'search', RecordMode::Record, new FakeClient(FakeResponse::json(['ok' => true]))))
        ->response(ping(['engine' => 'google', 'q' => 'coffee shops', 'api_key' => 'live-key']));

    return $directory;
}

it('replays a request whose query matches, in any order, when matching the query', function (): void {
    $directory = searchFixture();

    expect(recorded(new Recorder($directory, 'search', matchQuery: true))->response(ping(['engine' => 'google', 'q' => 'coffee shops', 'api_key' => 'live-key']))->json())->toBe(['ok' => true])
        ->and(recorded(new Recorder($directory, 'search', matchQuery: true))->response(ping(['q' => 'coffee shops', 'engine' => 'google', 'api_key' => 'live-key']))->json())->toBe(['ok' => true]);
});

it('refuses a request whose query differs, saying how, when matching the query', function (): void {
    $replay = recorded(new Recorder(searchFixture(), 'search', matchQuery: true));

    expect(fn () => $replay->response(ping(['engine' => 'youtube', 'q' => 'coffee shops', 'api_key' => 'live-key'])))
        ->toThrow(FixtureMismatchException::class, 'was GET /ping?engine=youtube&q=coffee shops, but the fixture recorded GET /ping?engine=google&q=coffee shops');
});

it('leaves masked parameters out of the comparison, so a test needs no real key', function (): void {
    $directory = searchFixture();

    expect(recorded(new Recorder($directory, 'search', matchQuery: true))->response(ping(['engine' => 'google', 'q' => 'coffee shops', 'api_key' => 'fake']))->json())->toBe(['ok' => true])
        ->and(recorded(new Recorder($directory, 'search', matchQuery: true))->response(ping(['engine' => 'google', 'q' => 'coffee shops']))->json())->toBe(['ok' => true]);
});

it('still matches method and path only by default', function (): void {
    expect(recorded(new Recorder(searchFixture(), 'search'))->response(ping(['engine' => 'youtube']))->json())->toBe(['ok' => true]);
});
