<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\Link;
use JustSteveKing\SdkToolbox\Http\Links;

/**
 * RFC 8288 Web Linking.
 */
const CONTEXT = 'https://api.example.com/v2/members?page=2';

it('parses GitHub-style pagination links', function (): void {
    $header = '<https://api.example.com/v2/members?page=3>; rel="next", <https://api.example.com/v2/members?page=9>; rel="last"';

    expect(Links::find($header, CONTEXT, 'next')?->target)->toBe('https://api.example.com/v2/members?page=3')
        ->and(Links::find($header, CONTEXT, 'last')?->target)->toBe('https://api.example.com/v2/members?page=9')
        ->and(Links::find($header, CONTEXT, 'prev'))->toBeNull();
});

it('resolves relative targets against the request URI (3.1)', function (): void {
    expect(Links::find('<?page=3>; rel=next', CONTEXT, 'next')?->target)->toBe('https://api.example.com/v2/members?page=3')
        ->and(Links::find('</v2/members?page=3>; rel=next', CONTEXT, 'next')?->target)->toBe('https://api.example.com/v2/members?page=3');
});

it('reads several relations in one rel, case-insensitively (2.1.1, 3.3)', function (): void {
    $link = Links::find('<?page=3>; REL="Next Section"', CONTEXT, 'next');

    expect($link)->toBeInstanceOf(Link::class)
        ->and($link?->relations)->toBe(['next', 'section']);
});

it('uses only the first rel (3.3)', function (): void {
    expect(Links::find('<?page=3>; rel="prev"; rel="next"', CONTEXT, 'next'))->toBeNull();
});

it('drops a link anchored on some other resource (3.2)', function (): void {
    expect(Links::find('<?page=3>; rel=next; anchor="/v2/other"', CONTEXT, 'next'))->toBeNull()
        ->and(Links::find('<?page=3>; rel=next; anchor=""', CONTEXT, 'next'))->not->toBeNull();
});

it('does not split on commas or semicolons inside quotes or brackets', function (): void {
    $links = Links::parse('<https://x.example/a,b;c>; rel="next"; title="one, two; three", <https://x.example/d>; rel=last', CONTEXT);

    expect($links)->toHaveCount(2)
        ->and($links[0]->target)->toBe('https://x.example/a,b;c')
        ->and($links[0]->parameters['title'])->toBe('one, two; three');
});

it('reads every Link header line', function (): void {
    expect(Links::parse(['<?page=1>; rel=first', '<?page=3>; rel=next'], CONTEXT))->toHaveCount(2);
});

it('skips what will not parse', function (): void {
    expect(Links::parse('garbage, <?page=3>, <?page=4>; rel=next', CONTEXT))->toHaveCount(1);
});
