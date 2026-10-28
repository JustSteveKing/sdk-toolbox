<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use DateTimeImmutable;

final readonly class Member
{
    public function __construct(
        public string $id,
        public string $displayName,
        public Role $role,
        public DateTimeImmutable $joinedAt,
        public ?string $avatarUrl = null,
    ) {}
}
