<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\ByteSequence;
use JustSteveKing\SdkToolbox\Http\StructuredFields;

/**
 * RFC 9651 lists, as the RateLimit fields use them.
 */
it('parses items with parameters', function (): void {
    expect(StructuredFields::parseList('"default";r=50;t=30, "daily";r=1000;t=86400'))->toBe([
        ['value' => 'default', 'params' => ['r' => 50, 't' => 30]],
        ['value' => 'daily', 'params' => ['r' => 1000, 't' => 86400]],
    ]);
});

it('parses every bare item type', function (): void {
    expect(StructuredFields::parseList('42, -1.5, "a \\"q\\"", tok/en, :aGk=:, ?1, ?0, @1659578233, %"f%c3%bc"'))->toEqual([
        ['value' => 42, 'params' => []],
        ['value' => -1.5, 'params' => []],
        ['value' => 'a "q"', 'params' => []],
        ['value' => 'tok/en', 'params' => []],
        ['value' => new ByteSequence('hi'), 'params' => []],
        ['value' => true, 'params' => []],
        ['value' => false, 'params' => []],
        ['value' => 1659578233, 'params' => []],
        ['value' => 'fü', 'params' => []],
    ]);
});

it('parses inner lists and bare parameters', function (): void {
    expect(StructuredFields::parseList('("a" "b");x, c;flag'))->toBe([
        ['value' => [['value' => 'a', 'params' => []], ['value' => 'b', 'params' => []]], 'params' => ['x' => true]],
        ['value' => 'c', 'params' => ['flag' => true]],
    ]);
});

it('fails the whole field on any error (section 4.2)', function (string $field): void {
    expect(StructuredFields::parseList($field))->toBeNull();
})->with([
    'trailing comma' => ['"a", '],
    'unterminated string' => ['"a'],
    'bad parameter key' => ['"a";R=1'],
    'garbage after an item' => ['12abc'],
    'missing comma' => ['"a" "b"'],
]);

it('parses dictionaries, as Content-Digest and Signature use them', function (): void {
    expect(StructuredFields::parseDictionary('sha-256=:aGk=:, sig1=("@method" "@path");created=1618884473;keyid="k", flag'))->toEqual([
        'sha-256' => ['value' => new ByteSequence('hi'), 'params' => []],
        'sig1' => ['value' => [['value' => '@method', 'params' => []], ['value' => '@path', 'params' => []]], 'params' => ['created' => 1618884473, 'keyid' => 'k']],
        'flag' => ['value' => true, 'params' => []],
    ]);
});

it('serialises an inner list the way RFC 9421 signs it', function (): void {
    expect(StructuredFields::serializeInnerList(['date', '@authority', 'content-type'], ['created' => 1618884473, 'keyid' => 'test-shared-secret']))
        ->toBe('("date" "@authority" "content-type");created=1618884473;keyid="test-shared-secret"');
});
