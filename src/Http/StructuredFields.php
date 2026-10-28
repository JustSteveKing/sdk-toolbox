<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * Parses RFC 9651 Structured Field Lists, as far as API headers need.
 *
 * Every bare item type is understood: integers, decimals, strings, tokens,
 * byte sequences (left base64-encoded), booleans, dates and display
 * strings. So are inner lists and parameters. Anything malformed returns
 * null for the whole field, because RFC 9651 section 4.2 says a parser
 * that fails MUST ignore the field, not salvage part of it.
 *
 * @internal
 *
 * @phpstan-type Item array{value: mixed, params: array<string, mixed>}
 */
final class StructuredFields
{
    private string $input;

    private int $at = 0;

    private function __construct(string $input)
    {
        $this->input = $input;
    }

    /**
     * @return list<Item>|null
     */
    public static function parseList(string $field): ?array
    {
        $parser = new self(trim($field, ' '));

        return $parser->list();
    }

    /**
     * @return list<Item>|null
     */
    private function list(): ?array
    {
        $members = [];

        while (! $this->done()) {
            $member = $this->peek() === '(' ? $this->innerList() : $this->item();

            if ($member === null) {
                return null;
            }

            $members[] = $member;
            $this->skip(" \t");

            if ($this->done()) {
                return $members;
            }

            if ($this->take() !== ',') {
                return null;
            }

            $this->skip(" \t");

            if ($this->done()) {
                return null;
            }
        }

        return $members;
    }

    /**
     * @return Item|null
     */
    private function innerList(): ?array
    {
        $this->take();
        $items = [];

        while (! $this->done()) {
            $this->skip(' ');

            if ($this->peek() === ')') {
                $this->take();
                $params = $this->params();

                return $params === null ? null : ['value' => $items, 'params' => $params];
            }

            $item = $this->item();

            if ($item === null) {
                return null;
            }

            $items[] = $item;

            if (! in_array($this->peek(), [' ', ')'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return Item|null
     */
    private function item(): ?array
    {
        $value = $this->bareItem();

        if ($value === null) {
            return null;
        }

        $params = $this->params();

        return $params === null ? null : ['value' => $value[0], 'params' => $params];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function params(): ?array
    {
        $params = [];

        while ($this->peek() === ';') {
            $this->take();
            $this->skip(' ');

            if (preg_match('/\G[a-z*][a-z0-9_\-.*]*/', $this->input, $match, 0, $this->at) !== 1) {
                return null;
            }

            $key = $match[0];
            $this->at += strlen($key);
            $value = true;

            if ($this->peek() === '=') {
                $this->take();
                $bare = $this->bareItem();

                if ($bare === null) {
                    return null;
                }

                $value = $bare[0];
            }

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * The value is wrapped so that a legitimately falsy item isn't mistaken
     * for a failure.
     *
     * @return array{mixed}|null
     */
    private function bareItem(): ?array
    {
        $rest = substr($this->input, $this->at);

        return match (true) {
            preg_match('/^-?\d{1,15}(\.\d{1,3})?/', $rest, $m) === 1 => $this->advance($m[0], str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0]),
            preg_match('/^"((?:[\x20\x21\x23-\x5B\x5D-\x7E]|\\\\["\\\\])*)"/', $rest, $m) === 1 => $this->advance($m[0], stripcslashes($m[1])),
            preg_match('/^[A-Za-z*][A-Za-z0-9:\/!#$%&\'*+\-.^_`|~]*/', $rest, $m) === 1 => $this->advance($m[0], $m[0]),
            preg_match('/^:([A-Za-z0-9+\/=]*):/', $rest, $m) === 1 => $this->advance($m[0], $m[1]),
            preg_match('/^\?([01])/', $rest, $m) === 1 => $this->advance($m[0], $m[1] === '1'),
            preg_match('/^@(-?\d{1,15})/', $rest, $m) === 1 => $this->advance($m[0], (int) $m[1]),
            preg_match('/^%"((?:[\x20\x21\x23\x24\x26-\x5B\x5D-\x7E]|%[0-9a-f]{2}|\\\\["\\\\])*)"/', $rest, $m) === 1 => $this->advance($m[0], rawurldecode($m[1])),
            default => null,
        };
    }

    /**
     * @return array{mixed}
     */
    private function advance(string $consumed, mixed $value): array
    {
        $this->at += strlen($consumed);

        return [$value];
    }

    private function peek(): string
    {
        return $this->input[$this->at] ?? '';
    }

    private function take(): string
    {
        return $this->input[$this->at++] ?? '';
    }

    private function skip(string $characters): void
    {
        $this->at += strspn($this->input, $characters, $this->at);
    }

    private function done(): bool
    {
        return $this->at >= strlen($this->input);
    }
}
