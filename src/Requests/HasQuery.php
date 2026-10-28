<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

interface HasQuery
{
    /**
     * Null values are left out; see QueryString for how the rest encode.
     *
     * @return array<string, mixed>
     */
    public function query(): array;
}
