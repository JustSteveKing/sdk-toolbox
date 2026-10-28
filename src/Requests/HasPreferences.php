<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

use JustSteveKing\SdkToolbox\Http\Preferences;

interface HasPreferences
{
    public function preferences(): Preferences;
}
