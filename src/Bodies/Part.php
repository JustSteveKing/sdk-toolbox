<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Psr\Http\Message\StreamInterface;

/**
 * One part of a multipart/form-data body (RFC 7578).
 *
 * Files come in as streams or open resources, never as paths, so an SDK
 * can't be talked into reading a file from a path someone else supplied.
 */
final readonly class Part
{
    /**
     * @param  string|StreamInterface|resource  $content
     */
    private function __construct(
        public string $name,
        public mixed $content,
        public ?string $filename,
        public ?string $contentType,
    ) {
        if ($name === '') {
            throw new InvalidRequestException('Every multipart part needs a name (RFC 7578 section 4.2).');
        }
    }

    public static function field(string $name, string $value): self
    {
        return new self($name, $value, null, null);
    }

    /**
     * @param  StreamInterface|resource|string  $content  a stream, an open resource, or the file's contents
     */
    public static function file(string $name, mixed $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        if (! is_string($content) && ! $content instanceof StreamInterface && ! is_resource($content)) {
            throw new InvalidRequestException('A file part takes a stream, an open resource or a string.');
        }

        return new self($name, $content, $filename, $contentType);
    }

    /**
     * The part's headers, with `"`, CR and LF in names percent-encoded as
     * the HTML form encoding does, so a filename can't break the framing.
     */
    public function headers(): string
    {
        $disposition = 'Content-Disposition: form-data; name="'.self::quote($this->name).'"';

        if ($this->filename !== null) {
            $disposition .= '; filename="'.self::quote($this->filename).'"';
        }

        return $this->contentType === null
            ? "{$disposition}\r\n"
            : "{$disposition}\r\nContent-Type: {$this->contentType}\r\n";
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
