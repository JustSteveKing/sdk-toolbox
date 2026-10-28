<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use NoDiscard;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * An RFC 6902 JSON Patch, `application/json-patch+json`.
 *
 * Built one operation at a time, each one with exactly one `op` and one
 * valid `path` (section 4). The server applies the whole patch or none of
 * it (section 5), which makes `test()` the way to guard a patch: put one
 * first, ideally alongside an If-Match header.
 *
 *     $patch = JsonPatch::new()
 *         ->test('/version', 3)
 *         ->replace('/name', 'Production')
 *         ->remove('/labels/0');
 */
final readonly class JsonPatch implements Body
{
    /**
     * @param  list<array<string, mixed>>  $operations
     */
    private function __construct(
        private array $operations = [],
    ) {}

    public static function new(): self
    {
        return new self;
    }

    #[NoDiscard]
    public function add(string $path, mixed $value): self
    {
        return $this->with(['op' => 'add', 'path' => JsonPointer::assertValid($path), 'value' => $value]);
    }

    #[NoDiscard]
    public function remove(string $path): self
    {
        return $this->with(['op' => 'remove', 'path' => JsonPointer::assertValid($path)]);
    }

    #[NoDiscard]
    public function replace(string $path, mixed $value): self
    {
        return $this->with(['op' => 'replace', 'path' => JsonPointer::assertValid($path), 'value' => $value]);
    }

    #[NoDiscard]
    public function move(string $from, string $path): self
    {
        return $this->with(['op' => 'move', 'from' => JsonPointer::assertValid($from), 'path' => JsonPointer::assertValid($path)]);
    }

    #[NoDiscard]
    public function copy(string $from, string $path): self
    {
        return $this->with(['op' => 'copy', 'from' => JsonPointer::assertValid($from), 'path' => JsonPointer::assertValid($path)]);
    }

    #[NoDiscard]
    public function test(string $path, mixed $value): self
    {
        return $this->with(['op' => 'test', 'path' => JsonPointer::assertValid($path), 'value' => $value]);
    }

    public function contentType(): string
    {
        return 'application/json-patch+json';
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        if ($this->operations === []) {
            throw new InvalidRequestException('A JSON Patch needs at least one operation.');
        }

        return $streams->createStream(EncodesJson::encode($this->operations));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->operations;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function with(array $operation): self
    {
        return new self([...$this->operations, $operation]);
    }
}
