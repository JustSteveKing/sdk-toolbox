<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\FormBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\ListFormat;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\QueryString;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use Nyholm\Psr7\Factory\Psr17Factory;

it('encodes the way APIs expect', function (): void {
    expect(QueryString::build([
        'q' => 'coffee shops',
        'page' => 2,
        'active' => true,
        'archived' => false,
        'missing' => null,
        'method' => Method::Get,
        'ids' => [1, 2],
    ]))->toBe('q=coffee%20shops&page=2&active=true&archived=false&method=GET&ids%5B0%5D=1&ids%5B1%5D=2');
});

it('refuses values it cannot encode', function (): void {
    expect(fn () => QueryString::build(['when' => new DateTimeImmutable]))->toThrow(InvalidRequestException::class);
});

it('writes lists in the format the API reads', function (ListFormat $lists, string $expected): void {
    expect(QueryString::build(['expand' => ['customer', 'invoice'], 'q' => 'a b'], $lists))->toBe($expected);
})->with([
    'indexed' => [ListFormat::Indexed, 'expand%5B0%5D=customer&expand%5B1%5D=invoice&q=a%20b'],
    'brackets' => [ListFormat::Brackets, 'expand%5B%5D=customer&expand%5B%5D=invoice&q=a%20b'],
    'repeated' => [ListFormat::Repeated, 'expand=customer&expand=invoice&q=a%20b'],
]);

it('indexes lists by default, as before', function (): void {
    expect(QueryString::build(['ids' => [1, 2]]))->toBe(QueryString::build(['ids' => [1, 2]], ListFormat::Indexed))
        ->and(QueryString::build(['ids' => [1, 2]]))->toBe('ids%5B0%5D=1&ids%5B1%5D=2');
});

it('leaves keyed arrays, nested ones included, as they were', function (ListFormat $lists): void {
    $keyed = ['filter' => ['status' => 'open', 'owner' => ['id' => 7, 'name' => 'Ada Lovelace']], 'page' => ['size' => 10]];

    expect(QueryString::build($keyed, $lists))->toBe(QueryString::build($keyed))
        ->and(QueryString::build($keyed, $lists))->toBe('filter%5Bstatus%5D=open&filter%5Bowner%5D%5Bid%5D=7&filter%5Bowner%5D%5Bname%5D=Ada%20Lovelace&page%5Bsize%5D=10');
})->with([ListFormat::Brackets, ListFormat::Repeated]);

it('formats a list inside a keyed array, and keeps the index of a list item that is an array', function (): void {
    $values = ['filter' => ['status' => ['open', 'closed']], 'items' => [['id' => 1], ['id' => 2]]];

    expect(QueryString::build($values, ListFormat::Brackets))->toBe('filter%5Bstatus%5D%5B%5D=open&filter%5Bstatus%5D%5B%5D=closed&items%5B0%5D%5Bid%5D=1&items%5B1%5D%5Bid%5D=2')
        ->and(QueryString::build($values, ListFormat::Repeated))->toBe('filter%5Bstatus%5D=open&filter%5Bstatus%5D=closed&items%5B0%5D%5Bid%5D=1&items%5B1%5D%5Bid%5D=2');
});

it('encodes booleans, enums and nulls in lists the same way in every format', function (ListFormat $lists, string $expected): void {
    expect(QueryString::build(['flags' => [true, false, null, Method::Post], 'empty' => [], 'on' => true], $lists))->toBe($expected);
})->with([
    'brackets' => [ListFormat::Brackets, 'flags%5B%5D=true&flags%5B%5D=false&flags%5B%5D=POST&on=true'],
    'repeated' => [ListFormat::Repeated, 'flags=true&flags=false&flags=POST&on=true'],
]);

it('refuses values it cannot encode in every format', function (ListFormat $lists): void {
    expect(fn () => QueryString::build(['when' => [new DateTimeImmutable]], $lists))->toThrow(InvalidRequestException::class);
})->with(ListFormat::cases());

it('writes lists in a request\'s query as the connector is told, and in a form body as it is told', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient, queryLists: ListFormat::Repeated);

    expect($connector->toPsr(ping(['expand' => ['a', 'b']]))->getUri()->getQuery())->toBe('expand=a&expand=b')
        ->and((new Connector('https://api.example.com', http: new FakeClient))->toPsr(ping(['expand' => ['a', 'b']]))->getUri()->getQuery())->toBe('expand%5B0%5D=a&expand%5B1%5D=b')
        ->and((string) (new FormBody(['ids' => [1, 2]], ListFormat::Brackets))->stream(new Psr17Factory))->toBe('ids%5B%5D=1&ids%5B%5D=2')
        ->and((string) (new FormBody(['ids' => [1, 2]]))->stream(new Psr17Factory))->toBe('ids%5B0%5D=1&ids%5B1%5D=2');
});
