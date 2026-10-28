<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

use JustSteveKing\SdkToolbox\Http\Precondition;

/**
 * A request sent with a precondition, such as If-Match.
 */
interface Conditional
{
    public function precondition(): ?Precondition;
}
