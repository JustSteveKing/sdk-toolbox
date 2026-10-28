<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

use JustSteveKing\SdkToolbox\Bodies\Body;

interface HasBody
{
    public function body(): Body;
}
