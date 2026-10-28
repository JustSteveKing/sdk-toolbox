<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use JustSteveKing\SdkToolbox\Webhooks\TimestampedHmacWebhook;
use Nyholm\Psr7\ServerRequest;

/**
 * @param  array<string, string>  $headers
 */
function delivered(array $headers, string $body): ServerRequest
{
    return new ServerRequest('POST', 'https://app.example.com/webhooks', $headers, $body);
}

const SLACK_BODY = 'token=xyzz0WbapA4vBCDEFasx0q6G&team_id=T1DC2JH3J&team_domain=testteamnow&channel_id=G8PSS9T3V&channel_name=foobar&user_id=U2CERLKJA&user_name=roadrunner&command=%2Fwebhook-collect&text=&response_url=https%3A%2F%2Fhooks.slack.com%2Fcommands%2FT1DC2JH3J%2F397700885554%2F96rGlfmibIGlgcZRskXaIFfN&trigger_id=398738663015.47445629121.803a0bc887a14d10d2c447fce8b6703c';

it('verifies Slack\'s published example', function (): void {
    // api.slack.com/authentication/verifying-requests-from-slack
    $request = delivered([
        'X-Slack-Signature' => 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503',
        'X-Slack-Request-Timestamp' => '1531420618',
    ], SLACK_BODY);

    expect(TimestampedHmacWebhook::slack('8f742231b10e8888abcd99yyyzzz85a5', clock: new FakeTime('@1531420618'))->check($request))->toBe(1531420618);
});

it('verifies Stripe\'s scheme, trying every v1 and ignoring v0', function (): void {
    // docs.stripe.com/webhooks, "Verify manually": HMAC-SHA256 of "{t}.{body}", hex.
    // Stripe publishes the header's shape but not a secret to go with it,
    // so the signatures are made by its documented steps.
    $body = '{"id":"evt_1","type":"invoice.paid"}';
    $sign = fn (string $secret): string => hash_hmac('sha256', "1492774577.{$body}", $secret);
    $stripe = TimestampedHmacWebhook::stripe('whsec_new', clock: new FakeTime('@1492774577'));

    $rolling = delivered(['Stripe-Signature' => 't=1492774577,v1='.$sign('whsec_old').',v1='.$sign('whsec_new').',v0=6ffbb59b2300aae63f272406069a9788598b792a944a07aba816edb039989a39'], $body);
    $downgraded = delivered(['Stripe-Signature' => 't=1492774577,v0='.$sign('whsec_new')], $body);

    expect($stripe->check($rolling))->toBe(1492774577)
        ->and(fn () => $stripe->verify($downgraded))->toThrow(InvalidSignatureException::class, 'no Stripe-Signature signature');
});

it('verifies Ghost\'s scheme, with its millisecond timestamp', function (): void {
    // TryGhost/Ghost webhook-trigger.js: `sha256=${hmac(secret, payload + ts)}, t=${ts}`, ts = Date.now().
    $body = '{"post":{"current":{"id":"1"}}}';
    $request = delivered(['X-Ghost-Signature' => 'sha256='.hash_hmac('sha256', $body.'1761681600123', 'abc123dontstealme').', t=1761681600123'], $body);

    expect(TimestampedHmacWebhook::ghost('abc123dontstealme', clock: new FakeTime('@1761681600'))->check($request))->toBe(1761681600123);
});

it('refuses a delivery outside the tolerance, either way', function (string $now): void {
    $request = delivered(['X-Slack-Signature' => 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503', 'X-Slack-Request-Timestamp' => '1531420618'], SLACK_BODY);

    expect(fn () => TimestampedHmacWebhook::slack('8f742231b10e8888abcd99yyyzzz85a5', clock: new FakeTime($now))->verify($request))
        ->toThrow(InvalidSignatureException::class, 'tolerance');
})->with(['late' => '@1531420919', 'early' => '@1531420317']);

it('refuses a body changed after signing, or a timestamp moved', function (): void {
    $slack = TimestampedHmacWebhook::slack('8f742231b10e8888abcd99yyyzzz85a5', clock: new FakeTime('@1531420618'));
    $signature = 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503';

    expect(fn () => $slack->verify(delivered(['X-Slack-Signature' => $signature, 'X-Slack-Request-Timestamp' => '1531420618'], SLACK_BODY.'&admin=1')))
        ->toThrow(InvalidSignatureException::class, 'No webhook signature matches')
        ->and(fn () => $slack->verify(delivered(['X-Slack-Signature' => $signature, 'X-Slack-Request-Timestamp' => '1531420619'], SLACK_BODY)))
        ->toThrow(InvalidSignatureException::class, 'No webhook signature matches');
});

it('refuses a missing or malformed timestamp', function (array $headers): void {
    expect(fn () => TimestampedHmacWebhook::stripe('whsec_x')->verify(delivered($headers, '{}')))->toThrow(InvalidSignatureException::class);
})->with([
    'no header' => [[]],
    'no timestamp' => [['Stripe-Signature' => 'v1=abc']],
    'not a number' => [['Stripe-Signature' => 't=soon,v1=abc']],
]);

it('takes base64 and SHA-512 for senders that use them', function (): void {
    $body = '{"a":1}';
    $verifier = new TimestampedHmacWebhook('secret', 'X-Signature', algorithm: 'sha512', encoding: 'base64', clock: new FakeTime('@1700000000'));
    $signature = base64_encode(hash_hmac('sha512', "1700000000.{$body}", 'secret', true));

    expect($verifier->check(delivered(['X-Signature' => "t=1700000000,v1={$signature}"], $body)))->toBe(1700000000);
});

it('refuses configuration that could not protect anything', function (): void {
    expect(fn () => new TimestampedHmacWebhook('s', 'X-Signature', tolerance: 0))->toThrow(InvalidRequestException::class, 'at least one second')
        ->and(fn () => new TimestampedHmacWebhook('s', 'X-Signature', signedPayload: '{body}'))->toThrow(InvalidRequestException::class, 'both {timestamp} and {body}');
});

it('keeps the secret out of dumps', function (): void {
    expect(print_r(TimestampedHmacWebhook::stripe('whsec_topsecret'), true))->not->toContain('whsec_topsecret');
});
