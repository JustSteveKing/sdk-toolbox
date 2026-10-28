<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use JustSteveKing\SdkToolbox\Time\SystemSleeper;

/**
 * The device authorisation grant (RFC 8628), for CLIs and anything else
 * without a browser: show the user a code and a URL, then poll.
 *
 *     $code = $flow->start(['projects:read']);
 *     echo "Go to {$code->verificationUri} and enter {$code->userCode}\n";
 *     $token = $flow->poll($code);
 *
 * Polling follows section 3.5 to the letter: wait `interval` seconds (5
 * when the server gives none), add 5 more on every `slow_down`, keep going
 * on `authorization_pending`, double the wait after a connection failure,
 * and stop on anything else or when the code expires.
 */
final readonly class DeviceAuthorization
{
    public function __construct(
        private OAuthClient $client,
        private Sleeper $sleeper = new SystemSleeper,
    ) {
        if ($client->deviceAuthorizationEndpoint === null) {
            throw new InvalidRequestException('The device grant needs the client\'s deviceAuthorizationEndpoint.');
        }
    }

    /**
     * @param  list<string>  $scopes
     */
    public function start(array $scopes = []): DeviceCode
    {
        $data = $this->client->post((string) $this->client->deviceAuthorizationEndpoint, [
            'scope' => $this->client->scope($scopes),
        ]);

        $device = $data['device_code'] ?? null;
        $user = $data['user_code'] ?? null;
        $uri = $data['verification_uri'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        $interval = $data['interval'] ?? 5;
        $complete = $data['verification_uri_complete'] ?? null;

        if (! is_string($device) || ! is_string($user) || ! is_string($uri) || ! is_int($expiresIn)) {
            throw new OAuthException('invalid_response', 'The device authorisation response is missing a required field (RFC 8628 section 3.2).');
        }

        return new DeviceCode(
            deviceCode: $device,
            userCode: $user,
            verificationUri: $uri,
            verificationUriComplete: is_string($complete) ? $complete : null,
            expiresAt: $this->client->clock->now()->modify("+{$expiresIn} seconds"),
            interval: is_int($interval) && $interval > 0 ? $interval : 5,
        );
    }

    /**
     * @param  list<string>  $scopes  as asked for in start()
     *
     * @throws OAuthException when the user denies access, the code expires, or the server errors
     */
    public function poll(DeviceCode $code, array $scopes = []): AccessToken
    {
        $interval = $code->interval;

        while (true) {
            $this->sleeper->sleep($interval);

            if ($this->client->clock->now() >= $code->expiresAt) {
                throw new OAuthException('expired_token', 'The device code expired before the user finished authorising.');
            }

            try {
                return $this->client->requestToken([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'device_code' => $code->deviceCode,
                ], $scopes);
            } catch (OAuthException $e) {
                match ($e->error) {
                    'authorization_pending' => null,
                    'slow_down' => $interval += 5,
                    default => throw $e,
                };
            } catch (TransportException) {
                // A connection failure MUST slow polling down (section 3.5);
                // exponential backoff is what the RFC recommends.
                $interval *= 2;
            }
        }
    }
}
