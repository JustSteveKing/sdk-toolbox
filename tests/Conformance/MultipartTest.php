<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\Multipart;
use JustSteveKing\SdkToolbox\Bodies\Part;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * RFC 7578 multipart/form-data.
 */
it('writes fields and files as parts, in order', function (): void {
    $factory = new Psr17Factory;
    $body = new Multipart(
        Part::field('title', 'Invoice'),
        Part::file('files', 'one', 'a.txt', 'text/plain'),
        Part::file('files', $factory->createStream('two'), 'b.txt', 'text/plain'),
    );

    $b = $body->boundary;
    expect($body->contentType())->toBe("multipart/form-data; boundary={$b}")
        ->and((string) $body->stream($factory))->toBe(
            "--{$b}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nInvoice\r\n"
            ."--{$b}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\none\r\n"
            ."--{$b}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"b.txt\"\r\nContent-Type: text/plain\r\n\r\ntwo\r\n"
            ."--{$b}--\r\n"
        );
});

it('keeps quotes and line breaks in names from breaking the framing', function (): void {
    expect(Part::file('f', 'x', "evil\"\r\nX-Injected: 1.txt")->headers())
        ->toContain('filename="evil%22%0D%0AX-Injected: 1.txt"')
        ->not->toContain("\r\nX-Injected");
});

it('streams resources without loading them whole, and stays seekable for a retry', function (): void {
    $file = fopen('php://memory', 'w+b');
    fwrite($file, str_repeat('a', 100_000));
    rewind($file);

    $stream = new Multipart(Part::file('upload', $file, 'big.bin'))->stream(new Psr17Factory);

    expect($stream->isSeekable())->toBeTrue()
        ->and(substr_count((string) $stream, 'a'))->toBeGreaterThanOrEqual(100_000);
});

it('requires a name on every part (section 4.2)', function (): void {
    expect(fn () => Part::field('', 'x'))->toThrow(InvalidRequestException::class);
});

it('uses a different boundary for every body', function (): void {
    expect(new Multipart()->boundary)->not->toBe(new Multipart()->boundary);
});
