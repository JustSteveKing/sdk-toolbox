<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;

/**
 * An RFC 7396 JSON Merge Patch, `application/merge-patch+json`.
 *
 * Two rules of the format catch people out, and both are enforced here
 * rather than left to the server: `null` means "remove this member", so a
 * merge patch can never set a value to null, and arrays are replaced whole,
 * never merged.
 */
final readonly class MergePatch implements Body
{
    /**
     * @param  array<string, mixed>  $patch
     */
    public function __construct(
        private array $patch,
    ) {}

    /**
     * The patch that turns one JSON object into another.
     *
     * Removed members become `null`, changed ones their new value, nested
     * objects are diffed member by member, and lists are sent whole.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changed
     *
     * @throws InvalidRequestException when a member changes to null, which a merge patch can't express
     */
    public static function diff(array $original, array $changed): self
    {
        return new self(self::between($original, $changed, ''));
    }

    public function contentType(): string
    {
        return 'application/merge-patch+json';
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        return $streams->createStream(EncodesJson::encode($this->patch === [] ? new stdClass : $this->patch));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->patch;
    }

    /**
     * @param  array<array-key, mixed>  $original
     * @param  array<array-key, mixed>  $changed
     * @return array<string, mixed>
     */
    private static function between(array $original, array $changed, string $path): array
    {
        $patch = [];

        foreach ($original as $key => $value) {
            if (! array_key_exists($key, $changed)) {
                $patch[(string) $key] = null;
            }
        }

        foreach ($changed as $key => $value) {
            $key = (string) $key;
            $at = "{$path}/{$key}";

            if ($value === null) {
                if (array_key_exists($key, $original) && $original[$key] !== null) {
                    throw new InvalidRequestException("A merge patch can't set [{$at}] to null: null removes the member (RFC 7396). Remove the key instead, or send a different body.");
                }

                continue;
            }

            $before = $original[$key] ?? null;

            if (self::isObject($value) && self::isObject($before)) {
                /** @var array<string, mixed> $value */
                /** @var array<string, mixed> $before */
                $nested = self::between($before, $value, $at);

                if ($nested !== []) {
                    $patch[$key] = $nested;
                }

                continue;
            }

            if ($value !== $before) {
                $patch[$key] = $value;
            }
        }

        return $patch;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }
}
