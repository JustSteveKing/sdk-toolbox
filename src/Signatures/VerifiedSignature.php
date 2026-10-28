<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Signatures;

/**
 * A signature that checked out: which one, by whom, over what.
 */
final readonly class VerifiedSignature
{
    /**
     * @param  list<string>  $components
     */
    public function __construct(
        public string $label,
        public string $keyId,
        public array $components,
        public ?int $created,
        public ?string $tag,
    ) {}
}
