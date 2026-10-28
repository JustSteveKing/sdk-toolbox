<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use JustSteveKing\SdkToolbox\Events\CredentialsRefreshed;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Observability\Telemetry;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adds credentials as the last step before the HTTP client, and renews
 * them once if the API rejects them.
 *
 * On a 401 from a Refreshable authenticator the request is tried once
 * more with new credentials, which RFC 6750 section 3.1 allows for
 * `invalid_token`. Many APIs send a bare 401 with no `WWW-Authenticate` at
 * all, so that counts too. A Bearer challenge naming any other error
 * (`invalid_request`) doesn't, because new credentials won't fix it, and
 * nor does 403 `insufficient_scope`.
 *
 * @internal the connector puts it in place
 */
final readonly class Authenticate implements Middleware
{
    public function __construct(
        private Authenticator $auth,
        private Telemetry $telemetry = new Telemetry,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $sent = $this->auth->authenticate($request);
        $response = $next->handle($sent);

        if (! $this->auth instanceof Refreshable || ! $this->worthRefreshing($response)) {
            return $response;
        }

        $body = $request->getBody();

        if (! $body->isSeekable()) {
            return $response;
        }

        $this->auth->refresh($sent);
        $this->telemetry->emit(new CredentialsRefreshed($this->telemetry->snapshot($request)));
        $body->rewind();

        return $next->handle($this->auth->authenticate($request));
    }

    private function worthRefreshing(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 401) {
            return false;
        }

        $error = Challenges::bearer($response)?->parameter('error');

        return $error === null || $error === 'invalid_token';
    }
}
