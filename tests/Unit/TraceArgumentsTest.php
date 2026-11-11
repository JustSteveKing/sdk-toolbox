<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\BasicAuth;
use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Auth\HeaderKey;
use JustSteveKing\SdkToolbox\Auth\OAuth\ClientAuthentication;
use JustSteveKing\SdkToolbox\Auth\OAuth\OAuthClient;
use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Retries\RetryPolicy;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\StrayRequestException;

/*
 * With zend.exception_ignore_args off, as php.ini-development and PHP's
 * built-in defaults have it, every frame of an exception's trace keeps
 * its arguments, and print_r(), var_dump() and error pages that capture
 * frames show them. A frame handed the authenticated request, or an OAuth
 * grant's parameters, would put the credential there. Production ini
 * files switch this on, which is how it went unseen: these tests switch
 * it off themselves, so they hold wherever the suite runs.
 *
 * Secrets are built at runtime so no test frame holds them as a literal
 * argument, and each is checked in plain and base64 form (Basic auth).
 */

beforeEach(function (): void {
    $this->ini = [
        'zend.exception_ignore_args' => ini_set('zend.exception_ignore_args', '0'),
        'zend.exception_string_param_max_len' => ini_set('zend.exception_string_param_max_len', '15'),
    ];
});

afterEach(function (): void {
    foreach ($this->ini as $name => $value) {
        if ($value !== false) {
            ini_set($name, $value);
        }
    }
});

function secret(string $name): string
{
    return implode('', [$name, '-', 'sh0uld-n0t-l3ak']);
}

/**
 * Every place in the exception, its previous ones, and every frame
 * argument of each, where one of the secrets shows.
 *
 * @return list<string>
 */
function leaks(Throwable $e, string ...$secrets): array
{
    $needles = [];

    foreach ($secrets as $secret) {
        $needles[] = $secret;
        $needles[] = base64_encode("client-1:{$secret}");
        $needles[] = base64_encode("user:{$secret}");
    }

    $found = [];

    for ($link = $e; $link !== null; $link = $link->getPrevious()) {
        $where = (new ReflectionClass($link))->getShortName();

        foreach ($link->getTrace() as $i => $frame) {
            foreach ($frame['args'] ?? [] as $n => $argument) {
                $dump = print_r($argument, true);

                foreach ($needles as $needle) {
                    if (str_contains($dump, $needle)) {
                        $found[] = "{$where} #{$i} ".($frame['class'] ?? '').($frame['type'] ?? '')."{$frame['function']}() argument {$n}";
                    }
                }
            }
        }

        foreach ($needles as $needle) {
            if (str_contains($link->getMessage(), $needle) || str_contains((string) $link, $needle)) {
                $found[] = "{$where}'s message or string form";
            }
        }
    }

    return array_values(array_unique($found));
}

function failing(Connector $connector): Throwable
{
    try {
        $connector->send(ping());
    } catch (Throwable $e) {
        return $e;
    }

    throw new LogicException('Nothing was raised.');
}

it('keeps the credential out of a transport failure\'s trace', function (Closure $auth): void {
    $key = secret('api-key');
    $connector = new Connector('https://api.example.com', auth: $auth($key), http: new FakeClient(FakeResponse::networkError('Could not resolve host')), retry: RetryPolicy::none());

    $e = failing($connector);

    expect($e)->toBeInstanceOf(TransportException::class)
        ->and(leaks($e, $key))->toBe([]);
})->with([
    'a query key' => [fn (string $key) => new QueryKey('api_key', $key)],
    'a header key' => [fn (string $key) => new HeaderKey('X-Api-Key', $key)],
    'a bearer token' => [fn (string $key) => new BearerToken($key)],
    'basic auth' => [fn (string $key) => new BasicAuth('user', $key)],
]);

it('keeps it out after retries too', function (): void {
    $key = secret('api-key');
    $time = new JustSteveKing\SdkToolbox\Testing\FakeTime;
    $http = new FakeClient(FakeResponse::networkError(), FakeResponse::networkError(), FakeResponse::networkError());
    $connector = new Connector('https://api.example.com', auth: new BearerToken($key), http: $http, retry: new RetryPolicy(jitter: 0.0), clock: $time, sleeper: $time);

    expect(leaks(failing($connector), $key))->toBe([]);
});

it('keeps it out of an exception thrown inside the transport, below authentication', function (): void {
    $key = secret('api-key');
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', $key), http: new FakeClient, retry: RetryPolicy::none());

    $e = failing($connector);

    expect($e)->toBeInstanceOf(StrayRequestException::class)
        ->and(leaks($e, $key))->toBe([]);
});

it('keeps it out of an HTTP error', function (): void {
    $key = secret('api-key');
    $connector = new Connector('https://api.example.com', auth: new QueryKey('api_key', $key), http: new FakeClient(FakeResponse::problem(404, title: 'Not Found')), retry: RetryPolicy::none());

    $e = failing($connector);

    expect($e)->toBeInstanceOf(NotFoundException::class)
        ->and(leaks($e, $key))->toBe([]);
});

it('keeps a pre-signed URL\'s signature out of a failed unauthenticated send', function (): void {
    $signature = secret('signature');
    $connector = new Connector('https://api.example.com', http: new FakeClient(FakeResponse::networkError()), retry: RetryPolicy::none());

    try {
        $connector->sendUnauthenticated("https://uploads.example.net/file?X-Amz-Signature={$signature}", ping());
        $this->fail('Nothing was raised.');
    } catch (TransportException $e) {
        expect(leaks($e, $signature))->toBe([]);
    }
});

it('keeps a refresh token and the client secret out of a failed token request', function (ClientAuthentication $authentication): void {
    $refresh = secret('refresh-token');
    $clientSecret = secret('client-secret');
    $oauth = new OAuthClient(
        'https://auth.example.com/token',
        'client-1',
        $clientSecret,
        authentication: $authentication,
        http: new FakeClient(FakeResponse::networkError()),
    );

    try {
        $oauth->requestToken(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        $this->fail('Nothing was raised.');
    } catch (TransportException $e) {
        expect(leaks($e, $refresh, $clientSecret))->toBe([]);
    }
})->with([
    'client secret in Basic auth' => [ClientAuthentication::Basic],
    'client secret in the body' => [ClientAuthentication::Post],
]);
