# Getting started

This builds a small SDK for an imaginary API, Acme: one connector, two requests, a resource class to group them, and a test. Most SDKs are this shape with more requests.

## Install

```bash
composer require juststeveking/sdk-toolbox
```

The toolbox needs a PSR-18 HTTP client and PSR-17 factories, and doesn't bring its own. Most applications already have them; if yours doesn't, install a pair:

```bash
composer require symfony/http-client nyholm/psr7
```

`php-http/discovery` finds whatever is installed, so nobody has to wire it up. PHP 8.3 or newer.

## The connector

A connector is the API: where it lives, how to authenticate, and how requests get there. Extend `Connector` and pass the API's details to the parent constructor.

```php
<?php

declare(strict_types=1);

namespace Acme;

use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

final class Acme extends Connector
{
    public function __construct(
        #[SensitiveParameter] string $token,
        ?ClientInterface $http = null,
    ) {
        parent::__construct(
            baseUri: 'https://api.acme.test/v2',
            auth: new BearerToken($token),
            http: $http,
            userAgent: 'acme-php/1.0',
        );
    }

    public function projects(): Projects
    {
        return new Projects($this);
    }
}
```

Taking `$http` lets the application pass its own client, and lets your tests pass a fake. `#[SensitiveParameter]` keeps the token out of stack traces.

Every request path is appended to the base URI, so `/projects` becomes `https://api.acme.test/v2/projects`, and nothing can make a request leave it. See [Requests and bodies](requests-and-bodies.md#the-base-uri).

## A request

A request is one API operation. It says what to send and what a successful response becomes.

```php
<?php

declare(strict_types=1);

namespace Acme\Requests;

use Acme\Data\Project;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Project>
 */
final readonly class GetProject implements Request
{
    public function __construct(
        private string $id,
    ) {}

    public function method(): Method
    {
        return Method::Get;
    }

    public function path(): string
    {
        return '/projects/'.rawurlencode($this->id);
    }

    public function result(Response $response): Project
    {
        return Project::fromArray($response->json());
    }
}
```

The `@implements Request<Project>` line is what makes `send()` return a `Project` as far as PHPStan is concerned, as well as at runtime. Encode anything that comes from a caller with `rawurlencode()`: an ID of `../../admin` then stays one path segment.

`result()` only ever sees a 2xx or 3xx response. Errors are thrown before it's called, so it never has to check a status.

## A request with a body

A request that sends something implements `HasBody`. `HasQuery` and `HasHeaders` work the same way, so a request only declares what it uses.

```php
<?php

declare(strict_types=1);

namespace Acme\Requests;

use Acme\Data\Project;
use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Request;

/**
 * @implements Request<Project>
 */
final readonly class CreateProject implements HasBody, Request
{
    public function __construct(
        private string $name,
    ) {}

    public function method(): Method
    {
        return Method::Post;
    }

    public function path(): string
    {
        return '/projects';
    }

    public function body(): Body
    {
        return new JsonBody(['name' => $this->name]);
    }

    public function result(Response $response): Project
    {
        return Project::fromArray($response->json());
    }
}
```

## A resource

Callers shouldn't have to know your request classes. Group them into resources, so `$acme->projects()->get('prj_1')` reads naturally:

```php
<?php

declare(strict_types=1);

namespace Acme;

use Acme\Data\Project;
use Acme\Requests\CreateProject;
use Acme\Requests\GetProject;

final readonly class Projects
{
    public function __construct(
        private Acme $acme,
    ) {}

    public function get(string $id): Project
    {
        return $this->acme->send(new GetProject($id));
    }

    public function create(string $name): Project
    {
        return $this->acme->send(new CreateProject($name));
    }
}
```

Resources are a convention, not something the toolbox needs. A small SDK can send requests from the connector directly.

## The result type

`Project` is your own class. Writing `fromArray()` by hand is fine, and is all this example needs. For a larger API, the toolbox can map responses for you with Valinor: see [Results and pagination](results-and-pagination.md).

```php
<?php

declare(strict_types=1);

namespace Acme\Data;

final readonly class Project
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
        );
    }
}
```

## Using it

```php
$acme = new Acme(getenv('ACME_TOKEN'));

$project = $acme->projects()->create('Website');
$again = $acme->projects()->get($project->id);
```

Out of the box, that connector already:

- **retries** the GET if Acme answers 503, waiting as long as `Retry-After` says;
- **never retries** the POST after a 502, because it may have landed (see [Reliability](reliability.md));
- **throws** a `NotFoundException` for a 404, carrying the RFC 9457 problem if Acme sent one;
- **keeps the token** out of every exception.

## A test

`FakeClient` is a PSR-18 client that answers from a queue and remembers what it was sent. Pass it where the real client would go:

```php
use Acme\Acme;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

it('gets a project', function (): void {
    $http = new FakeClient(FakeResponse::json(['id' => 'prj_1', 'name' => 'Website']));

    $project = (new Acme('test-token', $http))->projects()->get('prj_1');

    expect($project->name)->toBe('Website');
    $http->assertSentTo('GET', '/v2/projects/prj_1');
});
```

If the code sends a request the queue has no answer for, the test fails with `StrayRequestException`, so nothing reaches the network by accident. [Testing](testing.md) covers fakes for time, errors and network failures, and fixtures recorded from the real API.

## Where next

- More kinds of body, queries and headers: [Requests and bodies](requests-and-bodies.md)
- Turning an API's error format into your own exceptions: [Errors](errors.md)
- OAuth: [Authentication](authentication.md)
- Lists: [Results and pagination](results-and-pagination.md)
