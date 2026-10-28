<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
}
