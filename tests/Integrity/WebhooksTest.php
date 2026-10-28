<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Integrity\ContentDigest;
use JustSteveKing\SdkToolbox\Signatures\MessageVerifier;
use JustSteveKing\SdkToolbox\Signatures\SigningKey;
use JustSteveKing\SdkToolbox\Signatures\SignRequests;
use JustSteveKing\SdkToolbox\Signatures\VerificationKey;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use JustSteveKing\SdkToolbox\Webhooks\HmacWebhook;
use JustSteveKing\SdkToolbox\Webhooks\SignedWebhook;
use JustSteveKing\SdkToolbox\Webhooks\StandardWebhooks;
use Nyholm\Psr7\ServerRequest;

const WHSEC = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

function webhook(array $headers, string $body = '{"test": 2432232314}'): ServerRequest
{
    return new ServerRequest('POST', 'https://app.example.com/webhooks', $headers, $body);
}

it('verifies the Standard Webhooks PHP library\'s own test vector', function (): void {
    $request = webhook([
        'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
        'webhook-timestamp' => '1614265330',
        'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
    ]);

    expect((new StandardWebhooks(WHSEC, clock: new FakeTime('@1614265330')))->check($request))->toBe('msg_p5jXN8AQM9LWM0D4loKWxJek')
        ->and(StandardWebhooks::sign(WHSEC, 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}'))->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

it('verifies Svix\'s own example under its svix- headers', function (): void {
    // docs.svix.com/receiving/verifying-payloads/how-manual, "Example signatures".
    $request = new ServerRequest('POST', 'https://app.test/webhooks', [
        'svix-id' => 'msg_loFOjxBNrRLzqYUf',
        'svix-timestamp' => '1731705121',
        'svix-signature' => 'v1,rAvfW3dJ/X/qxhsaXPOyyCGmRKsaKWcsNccKXlIktD0=',
    ], '{"event_type":"ping","data":{"success":true}}');

    $svix = new StandardWebhooks('whsec_plJ3nmyCDGBKInavdOK15jsl', clock: new FakeTime('@1731705121'), headerPrefix: 'svix-');

    expect($svix->check($request))->toBe('msg_loFOjxBNrRLzqYUf')
        ->and(fn () => (new StandardWebhooks('whsec_plJ3nmyCDGBKInavdOK15jsl', clock: new FakeTime('@1731705121')))->verify($request))
        ->toThrow(InvalidSignatureException::class, 'missing webhook-id');
});

it('refuses a header prefix that would not make header names', function (string $prefix): void {
    expect(fn () => new StandardWebhooks(WHSEC, headerPrefix: $prefix))->toThrow(InvalidRequestException::class);
})->with(['no hyphen' => 'svix', 'a space' => 'my svix-', 'empty' => '']);

it('accepts any one valid signature, for key rotation', function (): void {
    $request = webhook([
        'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
        'webhook-timestamp' => '1614265330',
        'webhook-signature' => 'v1,Ceo5qEr07ixe2NLpvHk3FH9bwy/WavXrAFQ/9tdO6mc= v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
    ]);

    (new StandardWebhooks(WHSEC, clock: new FakeTime('@1614265330')))->verify($request);
})->throwsNoExceptions();

it('rejects a webhook outside the tolerance, or tampered with', function (): void {
    $headers = ['webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek', 'webhook-timestamp' => '1614265330', 'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE='];

    expect(fn () => (new StandardWebhooks(WHSEC, clock: new FakeTime('@1614265931')))->verify(webhook($headers)))->toThrow(InvalidSignatureException::class, 'tolerance')
        ->and(fn () => (new StandardWebhooks(WHSEC, clock: new FakeTime('@1614265330')))->verify(webhook($headers, '{"test": 1}')))->toThrow(InvalidSignatureException::class, 'No webhook signature')
        ->and(fn () => (new StandardWebhooks(WHSEC))->verify(webhook([])))->toThrow(InvalidSignatureException::class, 'missing');
});

it('verifies v1a, the Ed25519 scheme', function (): void {
    $keys = sodium_crypto_sign_keypair();
    $content = 'msg_1.1614265330.{"a":1}';
    $signature = base64_encode(sodium_crypto_sign_detached($content, sodium_crypto_sign_secretkey($keys)));
    $verifier = new StandardWebhooks('whpk_'.base64_encode(sodium_crypto_sign_publickey($keys)), clock: new FakeTime('@1614265330'));

    expect($verifier->check(webhook(['webhook-id' => 'msg_1', 'webhook-timestamp' => '1614265330', 'webhook-signature' => "v1a,{$signature}"], '{"a":1}')))->toBe('msg_1')
        ->and(fn () => $verifier->verify(webhook(['webhook-id' => 'msg_1', 'webhook-timestamp' => '1614265330', 'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE='], '{"a":1}')))->toThrow(InvalidSignatureException::class);
});

it('verifies GitHub\'s documented example', function (): void {
    $verifier = new HmacWebhook("It's a Secret to Everybody", header: 'X-Hub-Signature-256', prefix: 'sha256=');

    $verifier->verify(webhook(['X-Hub-Signature-256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17'], 'Hello, World!'));

    expect(fn () => $verifier->verify(webhook(['X-Hub-Signature-256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17'], 'Hello, World?')))
        ->toThrow(InvalidSignatureException::class);
});

it('verifies an RFC 9421 signed webhook only when the body is covered and intact', function (): void {
    $body = '{"event":"order.paid"}';
    $time = new FakeTime;
    $http = new FakeClient(FakeResponse::empty(200));
    $request = webhook(['Content-Type' => 'application/json', 'Content-Digest' => ContentDigest::header($body)], $body);
    (new JustSteveKing\SdkToolbox\Middleware\Pipeline($http, [new SignRequests(SigningKey::hmac('sender', 's'), ['@method', '@path'], clock: $time)]))->sendRequest($request);
    $signed = $http->last() ?? throw new LogicException;

    $verifier = new SignedWebhook(new MessageVerifier(fn (string $id) => VerificationKey::hmac($id, 's'), clock: $time));

    $verifier->verify($signed);
    expect(fn () => $verifier->verify($signed->withBody(Nyholm\Psr7\Stream::create('{"event":"order.refunded"}'))))->toThrow(InvalidSignatureException::class, 'Content-Digest');
});
