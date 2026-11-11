<?php

declare(strict_types=1);

use Http\Discovery\ClassDiscovery;
use Http\Discovery\Exception\NotFoundException;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\NoHttpClientException;
use JustSteveKing\SdkToolbox\Errors\SdkException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;

/*
 * A project with no PSR-18 client installed, as php-http/discovery sees
 * it: no strategy finds anything.
 */
beforeEach(function (): void {
    $this->strategies = iterator_to_array(ClassDiscovery::getStrategies());
    ClassDiscovery::setStrategies([]);
    ClassDiscovery::clearCache();
});

afterEach(function (): void {
    ClassDiscovery::setStrategies($this->strategies);
    ClassDiscovery::clearCache();
});

it('says what to install when no HTTP client can be found', function (): void {
    try {
        new Connector('https://api.acme.test');
        $this->fail('Nothing was raised.');
    } catch (NoHttpClientException $e) {
        expect($e)->toBeInstanceOf(SdkException::class)
            ->and($e->getMessage())->toContain('No PSR-18 HTTP client is installed')
            ->and($e->getMessage())->toContain('composer require guzzlehttp/guzzle')
            ->and($e->getMessage())->toContain('composer require symfony/http-client nyholm/psr7')
            ->and($e->getPrevious())->toBeInstanceOf(NotFoundException::class);
    }
});

it('says which factory is missing when only the client was given', function (): void {
    expect(fn () => new Connector('https://api.acme.test', http: new FakeClient))
        ->toThrow(NoHttpClientException::class, 'No PSR-17 request factory is installed');
});

it('never looks when everything is passed in', function (): void {
    $factory = new Nyholm\Psr7\Factory\Psr17Factory;

    expect(new Connector('https://api.acme.test', http: new FakeClient, requests: $factory, streams: $factory))
        ->toBeInstanceOf(Connector::class);
});
