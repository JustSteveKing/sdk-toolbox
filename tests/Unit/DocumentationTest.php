<?php

declare(strict_types=1);

/**
 * The README, docs and agent skill name classes and link to each other.
 * When code moves, they're the first thing to go stale, so they're checked
 * like code: every toolbox class they name exists, and every relative link
 * and anchor resolves.
 */
const NAMESPACES = 'Auth|Bodies|Caching|Deprecation|Errors|Events|Http|Hydration|Integrity|Middleware|Observability|Pagination|RateLimits|Redaction|Requests|Retries|Signatures|Testing|Time|Webhooks';

/**
 * @return list<string>
 */
function documents(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.'/README.md', $root.'/AGENTS.md', ...glob($root.'/docs/*.md') ?: []];
    $skills = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/boost', FilesystemIterator::SKIP_DOTS));

    foreach ($skills as $file) {
        $files[] = (string) $file;
    }

    return $files;
}

/**
 * GitHub's heading anchors: lowercased, punctuation dropped, spaces to hyphens.
 *
 * @return list<string>
 */
function anchors(string $markdown): array
{
    preg_match_all('/^#{1,6} (.+)$/m', (string) preg_replace('/```.*?```/s', '', $markdown), $headings);

    return array_map(
        static fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 \-]/', '', strtolower(str_replace('`', '', $heading)))),
        $headings[1],
    );
}

it('names only classes that exist', function (string $file): void {
    $text = (string) file_get_contents($file);
    preg_match_all('/JustSteveKing\\\\SdkToolbox\\\\([A-Za-z0-9\\\\]+)/', $text, $full);
    preg_match_all('/(?<![\\\\\w])((?:'.NAMESPACES.')\\\\[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*)/', $text, $relative);
    $src = dirname(__DIR__, 2).'/src/';

    expect($text)->not->toBe('');

    foreach ([...$full[1], ...$relative[1]] as $name) {
        $name = rtrim($name, '\\');
        $class = 'JustSteveKing\\SdkToolbox\\'.$name;
        $exists = class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class) || is_dir($src.str_replace('\\', '/', $name));

        expect($exists)->toBeTrue("{$file} names {$class}, which doesn't exist");
    }
})->with(documents());

it('links only to files and anchors that exist', function (string $file): void {
    $text = (string) preg_replace('/```.*?```/s', '', (string) file_get_contents($file));
    preg_match_all('/\]\((?!https?:|mailto:)([^)\s]+)\)/', $text, $links);

    foreach ($links[1] as $link) {
        [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $target = $path === '' ? $file : dirname($file).'/'.$path;

        expect(file_exists($target))->toBeTrue("{$file} links to {$link}, which doesn't exist");

        if ($anchor !== null && str_ends_with($target, '.md')) {
            expect(in_array($anchor, anchors((string) file_get_contents($target)), true))->toBeTrue("{$file} links to {$link}, but there's no such heading");
        }
    }

    expect($text)->not->toBe('');
})->with(documents());
