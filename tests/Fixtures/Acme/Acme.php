<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Auth\BearerToken;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\ProblemTypes;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use JustSteveKing\SdkToolbox\Time\SystemSleeper;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/**
 * A small SDK built the way the README tells people to build one.
 */
final class Acme extends Connector
{
    public function __construct(
        #[SensitiveParameter] string $token,
        ?ClientInterface $http = null,
        ?ClockInterface $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        parent::__construct(
            baseUri: 'https://api.acme.test/v2',
            auth: new BearerToken($token),
            http: $http,
            clock: $clock ?? new SystemClock,
            sleeper: $sleeper ?? new SystemSleeper,
            userAgent: 'acme-php/1.0',
            exceptions: new ProblemTypes([
                'https://api.acme.test/problems/out-of-credit' => OutOfCredit::class,
            ]),
        );
    }

    public function projects(): Projects
    {
        return new Projects($this);
    }
}
