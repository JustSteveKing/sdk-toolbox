<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\MergePatch;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * RFC 7396 JSON Merge Patch.
 */
it('diffs two objects into a merge patch', function (): void {
    $original = ['title' => 'Goodbye!', 'author' => ['givenName' => 'John', 'familyName' => 'Doe'], 'tags' => ['example', 'sample'], 'content' => 'This will be unchanged'];
    $changed = ['title' => 'Hello!', 'author' => ['givenName' => 'John'], 'tags' => ['example'], 'content' => 'This will be unchanged', 'phoneNumber' => '+01-123-456-7890'];

    // The example in RFC 7396 section 3, from the other direction.
    expect(MergePatch::diff($original, $changed)->toArray())->toBe([
        'title' => 'Hello!',
        'author' => ['familyName' => null],
        'tags' => ['example'],
        'phoneNumber' => '+01-123-456-7890',
    ]);
});

it('sends lists whole rather than merging them', function (): void {
    expect(MergePatch::diff(['ids' => [1, 2, 3]], ['ids' => [1, 2]])->toArray())->toBe(['ids' => [1, 2]]);
});

it('refuses to set a member to null, which a merge patch cannot express', function (): void {
    expect(fn () => MergePatch::diff(['a' => ['b' => 1]], ['a' => ['b' => null]]))
        ->toThrow(InvalidRequestException::class, "A merge patch can't set [/a/b] to null");
});

it('sends an empty patch as an empty object with its own media type', function (): void {
    $patch = MergePatch::diff(['a' => 1], ['a' => 1]);

    expect($patch->contentType())->toBe('application/merge-patch+json')
        ->and((string) $patch->stream(new Psr17Factory))->toBe('{}');
});
