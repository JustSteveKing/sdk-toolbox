<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use DateTimeImmutable;

/**
 * What to show the user, and what to poll with (RFC 8628 section 3.2).
 */
final readonly class DeviceCode
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public ?string $verificationUriComplete,
        public DateTimeImmutable $expiresAt,
        public int $interval = 5,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['deviceCode' => '[redacted]', 'userCode' => $this->userCode, 'verificationUri' => $this->verificationUri];
    }
}
