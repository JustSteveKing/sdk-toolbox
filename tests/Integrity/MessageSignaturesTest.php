<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use JustSteveKing\SdkToolbox\Integrity\Digests;
use JustSteveKing\SdkToolbox\Signatures\MessageVerifier;
use JustSteveKing\SdkToolbox\Signatures\SignatureBase;
use JustSteveKing\SdkToolbox\Signatures\SigningKey;
use JustSteveKing\SdkToolbox\Signatures\SignRequests;
use JustSteveKing\SdkToolbox\Signatures\VerificationKey;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\Http\Message\RequestInterface;

/**
 * RFC 9421 HTTP Message Signatures, against Appendix B's test cases.
 */
const B25_INPUT = 'sig-b25=("date" "@authority" "content-type");created=1618884473;keyid="test-shared-secret"';
const B25_SIGNATURE = 'sig-b25=:pxcQw6G3AjtMBQjwo8XzkZf/bws5LelbaMk5rGIGtE8=:';
const B26_INPUT = 'sig-b26=("date" "@method" "@path" "@authority" "content-type" "content-length");created=1618884473;keyid="test-key-ed25519"';
const B26_SIGNATURE = 'sig-b26=:wqcAqbmYJ2ji2glfAMaRy4gruYYnx2nEFN2HN6jrnDnQCK1u02Gb04v9EDgwUPiu4A0w6vuQv5lIp5WPpBKRCw==:';

function verifier(array $required = [], ?int $maxAge = null, ?FakeTime $time = null): MessageVerifier
{
    return new MessageVerifier(
        keys: fn (string $id): ?VerificationKey => match ($id) {
            'test-shared-secret' => VerificationKey::hmac($id, base64_decode(RFC9421_SHARED_SECRET)),
            'test-key-ed25519' => VerificationKey::ed25519($id, RFC9421_ED25519_PUBLIC),
            default => null,
        },
        required: $required,
        maxAge: $maxAge,
        clock: $time ?? new FakeTime('@1618884480'),
    );
}

/**
 * Signs through the middleware, at the RFC's created time, and returns
 * the request as it was sent.
 *
 * @param  list<string>  $components
 */
function signedBy(SigningKey $key, array $components, string $label): RequestInterface
{
    $http = new FakeClient(FakeResponse::empty(200));
    $request = rfc9421Request();

    (new JustSteveKing\SdkToolbox\Middleware\Pipeline($http, [new SignRequests($key, $components, $label, clock: new FakeTime('@1618884473'), coverDigest: false)]))->sendRequest($request);

    return $http->last() ?? throw new LogicException('Nothing sent');
}

it('builds B.2.5\'s signature base', function (): void {
    expect(SignatureBase::build(['date', '@authority', 'content-type'], ['created' => 1618884473, 'keyid' => 'test-shared-secret'], rfc9421Request()))->toBe(
        "\"date\": Tue, 20 Apr 2021 02:07:55 GMT\n\"@authority\": example.com\n\"content-type\": application/json\n\"@signature-params\": (\"date\" \"@authority\" \"content-type\");created=1618884473;keyid=\"test-shared-secret\""
    );
});

it('signs B.2.5 with hmac-sha256 exactly as the RFC does', function (): void {
    $sent = signedBy(SigningKey::hmac('test-shared-secret', base64_decode(RFC9421_SHARED_SECRET)), ['date', '@authority', 'content-type'], 'sig-b25');

    expect($sent->getHeaderLine('Signature-Input'))->toBe(B25_INPUT)
        ->and($sent->getHeaderLine('Signature'))->toBe(B25_SIGNATURE);
});

it('signs B.2.6 with ed25519 exactly as the RFC does', function (): void {
    $sent = signedBy(SigningKey::ed25519('test-key-ed25519', RFC9421_ED25519_PRIVATE), ['date', '@method', '@path', '@authority', 'content-type', 'content-length'], 'sig-b26');

    expect($sent->getHeaderLine('Signature-Input'))->toBe(B26_INPUT)
        ->and($sent->getHeaderLine('Signature'))->toBe(B26_SIGNATURE);
});

it('verifies the RFC\'s own signatures', function (string $input, string $signature, string $keyId): void {
    $request = rfc9421Request()->withHeader('Signature-Input', $input)->withHeader('Signature', $signature);

    expect(verifier()->verify($request)->keyId)->toBe($keyId);
})->with([
    'B.2.5, hmac-sha256' => [B25_INPUT, B25_SIGNATURE, 'test-shared-secret'],
    'B.2.6, ed25519' => [B26_INPUT, B26_SIGNATURE, 'test-key-ed25519'],
]);

it('rejects a signed message that was changed', function (): void {
    $request = rfc9421Request()->withHeader('Signature-Input', B26_INPUT)->withHeader('Signature', B26_SIGNATURE)->withHeader('Content-Type', 'text/plain');

    expect(fn () => verifier()->verify($request))->toThrow(InvalidSignatureException::class, "doesn't match");
});

it('enforces the application\'s requirements (3.2.1)', function (): void {
    $signed = rfc9421Request()->withHeader('Signature-Input', B25_INPUT)->withHeader('Signature', B25_SIGNATURE);

    expect(fn () => verifier(required: ['@method'])->verify($signed))->toThrow(InvalidSignatureException::class, "doesn't cover @method")
        ->and(fn () => verifier(maxAge: 60, time: new FakeTime('@1618888000'))->verify($signed))->toThrow(InvalidSignatureException::class, 'age')
        ->and(fn () => verifier(maxAge: 60, time: new FakeTime('@1618884500'))->verify($signed))->not->toThrow(InvalidSignatureException::class)
        // created more than a minute in the future is refused too
        ->and(fn () => verifier(maxAge: 60, time: new FakeTime('@1618884400'))->verify($signed))->toThrow(InvalidSignatureException::class, 'age');
});

it('refuses an unknown key, and an alg that is not the key\'s (step 6.5)', function (): void {
    $unknown = rfc9421Request()->withHeader('Signature-Input', str_replace('test-shared-secret', 'someone', B25_INPUT))->withHeader('Signature', B25_SIGNATURE);
    $wrongAlg = rfc9421Request()->withHeader('Signature-Input', B25_INPUT.';alg="ed25519"')->withHeader('Signature', B25_SIGNATURE);

    expect(fn () => verifier()->verify($unknown))->toThrow(InvalidSignatureException::class, 'no key')
        ->and(fn () => verifier()->verify($wrongAlg))->toThrow(InvalidSignatureException::class, 'algorithm');
});

it('refuses expired signatures and components it cannot rebuild', function (): void {
    $expired = rfc9421Request()->withHeader('Signature-Input', 'sig=("@method");created=1618884473;expires=1618884474;keyid="test-shared-secret"')->withHeader('Signature', 'sig=:AAAA:');
    $params = rfc9421Request()->withHeader('Signature-Input', 'sig=("@query-param";name="Pet");created=1618884473;keyid="test-shared-secret"')->withHeader('Signature', 'sig=:AAAA:');

    expect(fn () => verifier(time: new FakeTime('@1618890000'))->verify($expired))->toThrow(InvalidSignatureException::class, 'expired')
        ->and(fn () => verifier()->verify($params))->toThrow(InvalidSignatureException::class, "can't rebuild");
});

it('signs the body through Content-Digest when the Digests middleware runs first', function (): void {
    $http = new FakeClient(FakeResponse::empty(201));
    $key = SigningKey::hmac('client-1', 'shared');

    (new Connector('https://api.example.com', http: $http))
        ->withMiddleware(new Digests, new SignRequests($key, ['@method', '@path'], tag: 'acme-v1'))
        ->send(post(new JsonBody(['amount' => 5])));

    $sent = $http->last() ?? throw new LogicException;
    $verified = (new MessageVerifier(fn (string $id) => VerificationKey::hmac($id, 'shared'), required: ['content-digest'], tag: 'acme-v1'))->verify($sent);

    expect($verified->components)->toBe(['@method', '@path', 'content-digest'])
        ->and($sent->getHeaderLine('Signature-Input'))->toContain(';tag="acme-v1"');
});
