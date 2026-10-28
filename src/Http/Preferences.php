<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use NoDiscard;

/**
 * The `Prefer` header (RFC 7240): what the client would like, which the
 * server is free to ignore. `Preference-Applied` on the response says
 * which it honoured.
 *
 *     Preferences::none()->returnMinimal()->wait(10)   // Prefer: return=minimal, wait=10
 */
final readonly class Preferences
{
    /**
     * @param  array<string, string|null>  $preferences
     */
    private function __construct(
        private array $preferences = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * `return=minimal`: no body needed, typically a 204 (section 4.2).
     */
    #[NoDiscard]
    public function returnMinimal(): self
    {
        return $this->with('return', 'minimal');
    }

    /**
     * `return=representation`: send the resource back (section 4.2).
     */
    #[NoDiscard]
    public function returnRepresentation(): self
    {
        return $this->with('return', 'representation');
    }

    /**
     * `respond-async`: a 202 is welcome for slow work (section 4.1).
     */
    #[NoDiscard]
    public function respondAsync(): self
    {
        return $this->with('respond-async', null);
    }

    /**
     * `wait=N`: the longest the client wants to wait, in seconds (4.3).
     */
    #[NoDiscard]
    public function wait(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidRequestException('Prefer: wait takes a number of seconds, zero or more.');
        }

        return $this->with('wait', (string) $seconds);
    }

    /**
     * `handling=strict` or `handling=lenient` (section 4.4).
     */
    #[NoDiscard]
    public function handling(bool $strict): self
    {
        return $this->with('handling', $strict ? 'strict' : 'lenient');
    }

    /**
     * Any other preference an API defines.
     */
    #[NoDiscard]
    public function with(string $name, ?string $value): self
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidRequestException("Not a valid preference name: [{$name}].");
        }

        return new self([...$this->preferences, strtolower($name) => $value]);
    }

    public function isEmpty(): bool
    {
        return $this->preferences === [];
    }

    public function header(): string
    {
        $parts = [];

        foreach ($this->preferences as $name => $value) {
            $parts[] = match (true) {
                $value === null => $name,
                preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $value) === 1 => "{$name}={$value}",
                default => $name.'="'.addcslashes($value, '"\\').'"',
            };
        }

        return implode(', ', $parts);
    }

    /**
     * Reads `Preference-Applied` (section 3): name => value, null for a
     * preference without one.
     *
     * @return array<string, string|null>
     */
    public static function applied(string $header): array
    {
        $applied = [];

        foreach (array_filter(array_map(trim(...), explode(',', $header))) as $item) {
            $pair = explode('=', explode(';', $item, 2)[0], 2);
            $applied[strtolower(trim($pair[0]))] = isset($pair[1]) ? trim($pair[1], " \t\"") : null;
        }

        return $applied;
    }
}
