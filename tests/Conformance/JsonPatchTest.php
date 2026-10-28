<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\JsonPatch;
use JustSteveKing\SdkToolbox\Bodies\JsonPointer;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * RFC 6902 JSON Patch and RFC 6901 JSON Pointer.
 */
it('builds every operation with exactly one op and path', function (): void {
    $patch = JsonPatch::new()
        ->test('/a/b/c', 'foo')
        ->remove('/a/b/c')
        ->add('/a/b/c', ['foo', 'bar'])
        ->replace('/a/b/c', 42)
        ->move('/a/b/c', '/a/b/d')
        ->copy('/a/b/d', '/a/b/e');

    // RFC 6902 section 3's example, operation for operation.
    expect((string) $patch->stream(new Psr17Factory))->toBe(
        '[{"op":"test","path":"/a/b/c","value":"foo"},{"op":"remove","path":"/a/b/c"},{"op":"add","path":"/a/b/c","value":["foo","bar"]},{"op":"replace","path":"/a/b/c","value":42},{"op":"move","from":"/a/b/c","path":"/a/b/d"},{"op":"copy","from":"/a/b/d","path":"/a/b/e"}]'
    )->and($patch->contentType())->toBe('application/json-patch+json');
});

it('is immutable', function (): void {
    $empty = JsonPatch::new();
    $one = $empty->remove('/a');

    expect($empty->toArray())->toBe([])->and($one->toArray())->toHaveCount(1);
});

it('escapes pointer segments (RFC 6901 section 3)', function (): void {
    expect(JsonPointer::from('a/b', 'm~n', 0))->toBe('/a~1b/m~0n/0');
});

it('refuses invalid pointers', function (string $pointer): void {
    expect(fn () => JsonPatch::new()->remove($pointer))->toThrow(InvalidRequestException::class);
})->with(['no leading slash' => ['a/b'], 'bad escape' => ['/a~2b'], 'bare tilde' => ['/a~']]);

it('refuses to send an empty patch', function (): void {
    expect(fn () => JsonPatch::new()->stream(new Psr17Factory))->toThrow(InvalidRequestException::class);
});
