<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\NotFoundException;
use JustSteveKing\SdkToolbox\Errors\SdkException;
use JustSteveKing\SdkToolbox\Errors\ServerException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Errors\UnprocessableContentException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use JustSteveKing\SdkToolbox\Testing\StrayRequestException;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Acme;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\OutOfCredit;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Project;
use Psr\Http\Message\RequestInterface;

it('sends a typed request and returns its typed result', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']));

    $project = (new Acme('secret-token', $http))->projects()->get('prj_1');

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->name)->toBe('Website');

    $request = $http->last();
    expect($request?->getMethod())->toBe('GET')
        ->and((string) $request?->getUri())->toBe('https://api.acme.test/v2/projects/prj_1')
        ->and($request?->getHeaderLine('Authorization'))->toBe('Bearer secret-token')
        ->and($request?->getHeaderLine('Accept'))->toBe('application/json, application/problem+json')
        ->and($request?->getHeaderLine('User-Agent'))->toStartWith('acme-php/1.0 sdk-toolbox/'.Connector::VERSION.' php/');
});

it('sends query values and leaves out nulls', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']));
    $acme = new Acme('t', $http);

    $acme->send(new JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\GetProject('prj_1', withMembers: true));

    expect($http->last()?->getUri()->getQuery())->toBe('include_members=true');
});

it('sends a JSON body with its content type', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_2', 'name' => 'Docs'], 201));

    (new Acme('t', $http))->projects()->create('Docs');

    $request = $http->last();
    expect($request?->getMethod())->toBe('POST')
        ->and($request?->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and((string) $request?->getBody())->toBe('{"name":"Docs"}');
});

it('encodes path segments so an id cannot change the path', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'x', 'name' => 'x']));

    (new Acme('t', $http))->projects()->get('../../admin');

    expect($http->last()?->getUri()->getPath())->toBe('/v2/projects/..%2F..%2Fadmin');
});

it('throws the status exception for an error', function (): void {
    $http = new FakeClient(FakeResponse::problem(404, title: 'Not Found', detail: 'No project prj_9.'));

    expect(fn () => (new Acme('t', $http))->projects()->get('prj_9'))
        ->toThrow(NotFoundException::class, 'GET /v2/projects/prj_9 returned 404 Not Found: No project prj_9.');
});

it('throws the SDK\'s own exception for a problem type it knows', function (): void {
    $http = new FakeClient(FakeResponse::problem(
        403,
        type: 'https://api.acme.test/problems/out-of-credit',
        title: 'You do not have enough credit.',
        extensions: ['balance' => 30],
    ));

    try {
        (new Acme('t', $http))->projects()->create('Docs');
        $this->fail('Expected OutOfCredit');
    } catch (OutOfCredit $e) {
        expect($e->balance())->toBe(30)
            ->and($e->status)->toBe(403)
            ->and($e->getMessage())->toBe('POST /v2/projects returned 403 Forbidden: You do not have enough credit.');
    }
});

it('resolves a relative problem type before matching it', function (): void {
    $http = new FakeClient(FakeResponse::problem(403, type: '/problems/out-of-credit'));

    expect(fn () => (new Acme('t', $http))->projects()->create('Docs'))->toThrow(OutOfCredit::class);
});

it('maps 422 to an unprocessable content exception carrying the problem', function (): void {
    $http = new FakeClient(FakeResponse::problem(422, title: 'Invalid', extensions: ['errors' => ['name' => ['Required.']]]));

    try {
        (new Acme('t', $http))->projects()->create('');
    } catch (UnprocessableContentException $e) {
        expect($e->problem?->extension('errors'))->toBe(['name' => ['Required.']]);
    }
});

it('never keeps the token in an exception', function (): void {
    $http = new FakeClient(FakeResponse::problem(400, title: 'Boom'));

    try {
        (new Acme('very-secret-token', $http))->projects()->get('prj_1');
    } catch (SdkException $e) {
        expect(print_r($e, true))->not->toContain('very-secret-token')
            ->and(serialize($e->request))->not->toContain('very-secret-token');
    }
});

it('turns a network failure that outlasts the retries into a transport exception', function (): void {
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::networkError('Could not resolve host')));
    $time = new FakeTime;

    try {
        (new Acme('t', $http, $time, $time))->projects()->get('prj_1');
        $this->fail('Expected a TransportException');
    } catch (TransportException $e) {
        expect($e->network)->toBeTrue()
            ->and($e->getMessage())->toBe('Could not complete GET /v2/projects/prj_1: Could not resolve host');
    }

    $http->assertSentCount(3);
    expect($time->sleeps())->toHaveCount(2);
});

it('retries a failed read and returns the result when it recovers', function (): void {
    $http = new FakeClient(
        FakeResponse::problem(503, title: 'Down for a moment', headers: ['Retry-After' => '2']),
        FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']),
    );
    $time = new FakeTime;

    $project = (new Acme('t', $http, $time, $time))->projects()->get('prj_1');

    expect($project->name)->toBe('Website')
        ->and($time->sleeps())->toBe([2.0]);
});

it('does not retry a create that may have landed', function (): void {
    $http = new FakeClient(FakeResponse::problem(502, title: 'Bad gateway'));

    expect(fn () => (new Acme('t', $http))->projects()->create('Docs'))->toThrow(ServerException::class);
    $http->assertSentCount(1);
});

it('lets a stray request fail the test instead of becoming a transport error', function (): void {
    expect(fn () => (new Acme('t', new FakeClient))->projects()->get('prj_1'))
        ->toThrow(StrayRequestException::class);
});

it('can assert on what was sent', function (): void {
    $http = new FakeClient(
        FakeResponse::json(['id' => 'a', 'name' => 'A']),
        FakeResponse::json(['id' => 'b', 'name' => 'B']),
    );
    $acme = new Acme('t', $http);

    $acme->projects()->get('a');
    $acme->projects()->get('b');

    $http->assertSentCount(2);
    $http->assertSent(fn (RequestInterface $r): bool => $r->getUri()->getPath() === '/v2/projects/b');
    $http->assertNotSent(fn (RequestInterface $r): bool => $r->getMethod() === 'POST');
    $http->assertQueueEmpty();
});

it('keeps the token out of a dump of the SDK', function (): void {
    expect(print_r(new Acme('very-secret-token', new FakeClient), true))->not->toContain('very-secret-token');
});

it('lets an SDK exception word its own message', function (): void {
    $exception = new class(new JustSteveKing\SdkToolbox\Http\Response(FakeResponse::problem(402, title: 'Out of credit.'), new Nyholm\Psr7\Request('GET', 'https://api.acme.test/v2/x')), JustSteveKing\SdkToolbox\Errors\ProblemDetails::fromArray(['title' => 'Out of credit.']), new JustSteveKing\SdkToolbox\Redaction\Redactor) extends JustSteveKing\SdkToolbox\Errors\ClientException
    {
        protected function describe(JustSteveKing\SdkToolbox\Http\Response $response, ?JustSteveKing\SdkToolbox\Errors\ProblemDetails $problem): string
        {
            return "Acme: {$problem?->title}";
        }
    };

    expect($exception->getMessage())->toBe('Acme: Out of credit.')
        ->and($exception->status)->toBe(402);
});
