# Integrity and webhooks

## Content-Digest

`Digests` adds a `Content-Digest` (RFC 9530) to every request with a body, and checks the digest on every response that has one:

```php
$connector->withMiddleware(new Digests);                                 // sha-256
$connector->withMiddleware(new Digests(DigestAlgorithm::Sha512, verifyResponses: false));
```

- **A response whose body doesn't match** throws `DigestMismatchException`. It was changed or cut short on the way.
- **Only sha-256 and sha-512,** the algorithms RFC 9530 lists as Active. The deprecated ones (md5, sha, crc32c...) are ignored when they appear.
- **A response with a `Content-Encoding`** other than identity isn't checked. An HTTP client that decompressed it has handed over different bytes from the ones the digest was made over.

`ContentDigest::header($content)` and `ContentDigest::verify($message)` work on any PSR-7 message, outside the pipeline.

## HTTP Message Signatures

RFC 9421 signs chosen parts of a request (the method, path, authority, chosen headers) so the receiver can check who sent it and that those parts weren't changed.

### Signing requests

```php
$connector->withMiddleware(
    new Digests,
    new SignRequests(
        SigningKey::ed25519('client-42', $privateKeyPem),
        components: ['@method', '@authority', '@path', '@query'],
        tag: 'acme-v1',
        expiresIn: 300,
    ),
);
```

- **Keys:** `SigningKey::hmac($id, $secret)` (hmac-sha256) or `SigningKey::ed25519($id, $key)`. The Ed25519 key can be a 32-byte seed, a 64-byte secret or a PKCS #8 PEM.
- **Parameters:** it adds `created` and `keyid`, plus `expires` and `tag` when given.
- **The body:** when `Digests` runs first, `content-digest` is covered too, so the body is signed (pass `coverDigest: false` not to).
- **Correctness:** the toolbox's tests reproduce RFC 9421 Appendix B's own signatures byte for byte.

Authentication runs after middleware, so `Authorization` can't be covered.

### Verifying signatures

RFC 9421 leaves the important choices to the application: what a signature must cover, how old it may be, which keys count. `MessageVerifier` makes you state them:

```php
$verifier = new MessageVerifier(
    keys: fn (string $keyId): ?VerificationKey => $keyId === 'partner-1'
        ? VerificationKey::ed25519('partner-1', $publicKeyPem)
        : null,
    required: ['@method', '@path', 'content-digest'],
    maxAge: 300,
    tag: 'acme-v1',
);

$signature = $verifier->verify($request);   // or ->verify($request, $response) for a signed response
$signature->keyId;
```

It throws `InvalidSignatureException` for any of these:

- an unknown key;
- a missing required component;
- a `created` older than `maxAge`, or more than a minute in the future;
- an `expires` that has passed;
- the wrong tag;
- an `alg` that isn't the key's own algorithm (§3.2, step 6.5);
- a signature that simply doesn't match.

Supported components are header fields and the derived components `@method`, `@target-uri`, `@authority`, `@scheme`, `@request-target`, `@path`, `@query` and, on a response, `@status`. Component parameters (`;sf`, `;key`, `;bs`, `;req`, `@query-param;name=`) aren't supported yet. A signature that uses them is refused, never checked against the wrong base. RSA and ECDSA keys aren't supported yet either.

## Webhooks

Every verifier takes the PSR-7 request as it arrived (your framework's `ServerRequest` is fine), before anything parses or re-encodes the body, and throws `InvalidSignatureException`.

### Standard Webhooks

```php
$verifier = new StandardWebhooks('whsec_...');   // or 'whpk_...' for the Ed25519 scheme

$id = $verifier->check($request);   // the webhook-id, to drop repeats
```

- **What's signed:** `webhook-id.webhook-timestamp.body`, with HMAC-SHA256 (`v1`) or Ed25519 (`v1a`).
- **Freshness:** the timestamp has to be within `tolerance` seconds of now (300 by default), either way.
- **Key rotation:** `webhook-signature` can hold several signatures, and any one valid one is enough.
- **Replays:** keep the `webhook-id`s `check()` returns, and drop repeats.
- **Testing your own handlers:** `StandardWebhooks::sign($secret, $id, $timestamp, $body)` makes the header.

The tests use the official Standard Webhooks PHP library's own test vector.

### An HMAC of the body

The scheme most APIs used before Standard Webhooks. GitHub's, for example:

```php
new HmacWebhook($secret, header: 'X-Hub-Signature-256', prefix: 'sha256=')
```

`algorithm:` is `sha256` or `sha512`, and `encoding:` is `hex` or `base64`. There's no timestamp in this scheme, so it can't stop a replay on its own; prefer Standard Webhooks where the API offers it. The tests use GitHub's documented example.

### RFC 9421 signed webhooks

```php
new SignedWebhook($messageVerifier)
```

This requires the signature to cover `content-digest`, and checks the digest against the body (RFC 9530 §6.3 pairs the two for exactly this). Without both, the body would be unsigned.
