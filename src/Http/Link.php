<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * One link from a `Link` header (RFC 8288), its target already resolved.
 */
final readonly class Link
{
    /**
     * @param  list<string>  $relations  lowercased; one link can carry several
     * @param  array<string, string>  $parameters  the first occurrence of each, names lowercased
     */
    public function __construct(
        public string $target,
        public array $relations,
        public array $parameters = [],
    ) {}

    public function is(string $relation): bool
    {
        return in_array(strtolower($relation), $this->relations, true);
    }
}
