<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JsonException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\QueryString;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Records real API responses once, then replays them in tests.
 *
 *     $http = new Recorder(__DIR__.'/fixtures', 'projects/list', RecordMode::fromEnvironment());
 *     $sdk = new Acme(getenv('ACME_TOKEN') ?: 'fake', http: $http);
 *
 * - **Safe to commit.** Fixtures are JSON, written with credentials
 *   already masked: sensitive headers and query parameters by the
 *   Redactor, and secret-looking keys (`access_token`, `client_secret`...)
 *   in JSON and form bodies, in both directions.
 * - **Safe to name.** A fixture name is letters, digits, `-`, `_` and `/`
 *   only, and resolves inside the directory, so a name can't write or
 *   read a file anywhere else (the path traversal Saloon fixed in v4).
 * - **Strict on replay.** Requests must come in the recorded order with
 *   the recorded method and path; anything else throws, rather than
 *   quietly answering the wrong request. With `matchQuery: true` the
 *   query has to match too, in any order, for an API that answers every
 *   call at one path (`GET /search.json?engine=...`), where the path alone
 *   can't tell one request from another. Parameters the Redactor masks,
 *   such as an API key, are left out of the comparison.
 */
final class Recorder implements ClientInterface
{
    public const array SECRET_KEYS = [
        'access_token', 'refresh_token', 'id_token', 'client_secret', 'password', 'secret', 'api_key', 'apikey', 'token', 'code_verifier',
    ];

    private readonly string $path;

    private readonly bool $recording;

    /** @var list<array{request: array<string, mixed>, response: array{status: int, reason: string, headers: array<string, list<string>>, body: string}}> */
    private array $interactions = [];

    private int $position = 0;

    /**
     * @param  list<string>  $secretKeys  JSON and form keys whose values are masked
     * @param  bool  $matchQuery  replay only when the query matches too, ignoring order and masked parameters
     */
    public function __construct(
        string $directory,
        string $fixture,
        RecordMode $mode = RecordMode::Replay,
        private readonly ?ClientInterface $client = null,
        private readonly Redactor $redactor = new Redactor,
        private readonly array $secretKeys = self::SECRET_KEYS,
        private readonly bool $matchQuery = false,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/', $fixture) !== 1) {
            throw new InvalidRequestException("A fixture name is letters, digits, '-', '_' and '/': [{$fixture}].");
        }

        $this->path = rtrim($directory, '/')."/{$fixture}.json";
        $exists = is_file($this->path);
        $this->recording = $mode === RecordMode::Record || ($mode === RecordMode::Missing && ! $exists);

        if (! $this->recording) {
            if (! $exists) {
                throw new StrayRequestException("No fixture at [{$this->path}]. Record it with SDK_FIXTURES=record.");
            }

            $this->interactions = $this->load();
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->recording ? $this->record($request) : $this->replay($request);
    }

    public function isRecording(): bool
    {
        return $this->recording;
    }

    private function replay(RequestInterface $request): ResponseInterface
    {
        $interaction = $this->interactions[$this->position] ?? null;
        $this->position++;

        if ($interaction === null) {
            throw new StrayRequestException("The fixture [{$this->path}] has no answer for request {$this->position}: {$request->getMethod()} {$request->getUri()->getPath()}.");
        }

        $expected = $interaction['request'];
        $actual = "{$request->getMethod()} {$request->getUri()->getPath()}";
        $recorded = sprintf('%s %s', is_string($expected['method'] ?? null) ? $expected['method'] : '', is_string($expected['path'] ?? null) ? $expected['path'] : '');

        if ($actual !== $recorded) {
            throw new FixtureMismatchException("Request {$this->position} was {$actual}, but the fixture recorded {$recorded}.");
        }

        if ($this->matchQuery) {
            $sent = $this->comparable((string) parse_url($this->redactor->uri((string) $request->getUri()), PHP_URL_QUERY));
            $kept = $this->comparable((string) parse_url(is_string($expected['uri'] ?? null) ? $expected['uri'] : '', PHP_URL_QUERY));

            if ($sent !== $kept) {
                throw new FixtureMismatchException(sprintf(
                    'Request %d was %s?%s, but the fixture recorded %s?%s.',
                    $this->position,
                    $actual,
                    implode('&', $sent),
                    $recorded,
                    implode('&', $kept),
                ));
            }
        }

        return $this->toResponse($interaction['response']);
    }

    /**
     * A query as sorted, decoded `name=value` pairs, without the ones the
     * Redactor masked, so order and credentials don't count.
     *
     * @return list<string>
     */
    private function comparable(string $query): array
    {
        $pairs = [];

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (rawurldecode($value) === Redactor::MASK) {
                continue;
            }

            $pairs[] = rawurldecode($name).'='.rawurldecode($value);
        }

        sort($pairs);

        return $pairs;
    }

    private function record(RequestInterface $request): ResponseInterface
    {
        $client = $this->client ?? Psr18ClientDiscovery::find();
        $response = $client->sendRequest($request);
        $body = (string) $response->getBody();

        $this->interactions[] = [
            'request' => [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'uri' => $this->redactor->uri((string) $request->getUri()),
                'headers' => $this->redactor->headers($request),
                'body' => $this->mask((string) $request->getBody(), $request->getHeaderLine('Content-Type')),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                'headers' => $this->redactor->headers($response),
                'body' => $this->mask($body, $response->getHeaderLine('Content-Type')),
            ],
        ];

        $this->save();

        return $response->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($body));
    }

    /**
     * Masks secret-looking keys in JSON and form bodies; leaves anything
     * else alone.
     */
    private function mask(string $body, string $contentType): string
    {
        if ($body === '') {
            return $body;
        }

        if (str_contains($contentType, 'json')) {
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $body;
            }

            return json_encode($this->maskArray($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }

        if (str_contains($contentType, 'x-www-form-urlencoded')) {
            parse_str($body, $form);

            $masked = $this->maskArray($form);

            return QueryString::build(is_array($masked) ? $masked : []);
        }

        return $body;
    }

    private function maskArray(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            $data[$key] = is_string($key) && in_array(strtolower($key), $this->secretKeys, true) && $value !== null
                ? Redactor::MASK
                : $this->maskArray($value);
        }

        return $data;
    }

    private function save(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new InvalidRequestException("Could not create [{$directory}].");
        }

        file_put_contents($this->path, json_encode(
            ['interactions' => $this->interactions],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n");
    }

    /**
     * @return list<array{request: array<string, mixed>, response: array{status: int, reason: string, headers: array<string, list<string>>, body: string}}>
     */
    private function load(): array
    {
        $data = json_decode((string) file_get_contents($this->path), true);
        $interactions = is_array($data) && is_array($data['interactions'] ?? null) ? $data['interactions'] : null;

        if ($interactions === null) {
            throw new FixtureMismatchException("The fixture [{$this->path}] isn't valid.");
        }

        $valid = [];

        foreach ($interactions as $interaction) {
            $response = is_array($interaction) ? ($interaction['response'] ?? null) : null;

            if (! is_array($interaction) || ! is_array($interaction['request'] ?? null) || ! is_array($response)
                || ! is_int($response['status'] ?? null) || ! is_string($response['body'] ?? null) || ! is_array($response['headers'] ?? null)) {
                throw new FixtureMismatchException("The fixture [{$this->path}] has an interaction it can't read.");
            }

            $headers = [];

            foreach ($response['headers'] as $name => $values) {
                $headers[(string) $name] = array_values(array_filter(is_array($values) ? $values : [$values], is_string(...)));
            }

            /** @var array<string, mixed> $request */
            $request = $interaction['request'];

            $valid[] = [
                'request' => $request,
                'response' => [
                    'status' => $response['status'],
                    'reason' => is_string($response['reason'] ?? null) ? $response['reason'] : '',
                    'headers' => $headers,
                    'body' => $response['body'],
                ],
            ];
        }

        return $valid;
    }

    /**
     * @param  array{status: int, reason: string, headers: array<string, list<string>>, body: string}  $recorded
     */
    private function toResponse(array $recorded): ResponseInterface
    {
        $response = Psr17FactoryDiscovery::findResponseFactory()
            ->createResponse($recorded['status'], $recorded['reason'])
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($recorded['body']));

        foreach ($recorded['headers'] as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }
}
