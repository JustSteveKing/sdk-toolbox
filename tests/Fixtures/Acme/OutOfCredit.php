<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

use JustSteveKing\SdkToolbox\Errors\ClientException;

final class OutOfCredit extends ClientException
{
    public function balance(): int
    {
        $balance = $this->problem?->extension('balance');

        return is_int($balance) ? $balance : 0;
    }
}
