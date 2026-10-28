<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A multipart/form-data body (RFC 7578).
 *
 * Parts are written into a `php://temp` stream, which moves to disk past
 * 2MB, so a large upload never sits whole in memory, and the result is
 * seekable, so it can be sent again on a retry. Several files under one
 * name are several parts, in the order given (section 4.3).
 */
final readonly class Multipart implements Body
{
    private const int CHUNK = 8192;

    /**
     * Fixed per body: 32 random hex characters, which makes a collision with
     * the content vanishingly unlikely (section 4.1).
     */
    public string $boundary;

    /** @var list<Part> */
    private array $parts;

    public function __construct(
        Part ...$parts,
    ) {
        $this->parts = array_values($parts);
        $this->boundary = bin2hex(random_bytes(16));
    }

    public function contentType(): string
    {
        return "multipart/form-data; boundary={$this->boundary}";
    }

    public function stream(StreamFactoryInterface $streams): StreamInterface
    {
        $buffer = fopen('php://temp', 'w+b');

        if ($buffer === false) {
            throw new InvalidRequestException('Could not open a temporary stream for the multipart body.');
        }

        $boundary = $this->boundary;

        foreach ($this->parts as $part) {
            fwrite($buffer, "--{$boundary}\r\n{$part->headers()}\r\n");
            $this->copy($part->content, $buffer);
            fwrite($buffer, "\r\n");
        }

        fwrite($buffer, "--{$boundary}--\r\n");
        rewind($buffer);

        return $streams->createStreamFromResource($buffer);
    }

    /**
     * @param  string|StreamInterface|resource  $content
     * @param  resource  $buffer
     */
    private function copy(mixed $content, mixed $buffer): void
    {
        if (is_string($content)) {
            fwrite($buffer, $content);

            return;
        }

        if ($content instanceof StreamInterface) {
            if ($content->isSeekable()) {
                $content->rewind();
            }

            while (! $content->eof()) {
                fwrite($buffer, $content->read(self::CHUNK));
            }

            return;
        }

        stream_copy_to_stream($content, $buffer);
    }
}
