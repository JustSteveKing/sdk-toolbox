<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

interface HasHeaders
{
    /**
     * Headers for this request, replacing the connector's defaults of the
     * same name.
     *
     * @return array<string, string|list<string>>
     */
    public function headers(): array;
}
