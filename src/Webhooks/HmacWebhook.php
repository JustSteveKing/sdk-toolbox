<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use JustSteveKing\SdkToolbox\Errors\InvalidSignatureException;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The scheme most APIs used before Standard Webhooks: an HMAC of the raw
 * body in a header of their choosing. GitHub's, for example:
 *
 *     new HmacWebhook($secret, header: 'X-Hub-Signature-256', prefix: 'sha256=')
 *
 * It has no timestamp, so it can't stop a replay on its own. Prefer
 * Standard Webhooks where the API offers it.
 */
final readonly class HmacWebhook implements WebhookVerifier
{
    /**
     * @param  'sha256'|'sha512'  $algorithm
     * @param  'hex'|'base64'  $encoding
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
        private string $header,
        private string $prefix = '',
        private string $algorithm = 'sha256',
        private string $encoding = 'hex',
    ) {}

    public function verify(RequestInterface $request): void
    {
        $value = trim($request->getHeaderLine($this->header));

        if ($value === '' || ! str_starts_with($value, $this->prefix)) {
            throw new InvalidSignatureException("The webhook has no {$this->header} signature.");
        }

        $digest = hash_hmac($this->algorithm, RawBody::of($request), $this->secret, true);
        $expected = $this->encoding === 'hex' ? bin2hex($digest) : base64_encode($digest);

        if (! hash_equals($expected, substr($value, strlen($this->prefix)))) {
            throw new InvalidSignatureException('The webhook signature doesn\'t match.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['header' => $this->header, 'secret' => '[redacted]'];
    }
}
