<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use Psr\Http\Message\ResponseInterface;

/**
 * Parses `WWW-Authenticate` (RFC 9110 section 11.6.1).
 *
 * The grammar is awkward because commas separate both challenges and the
 * parameters inside one: `Bearer realm="api", error="invalid_token", Basic
 * realm="x"` is two challenges. A token followed by `=` is a parameter;
 * any other token starts the next challenge.
 */
final class Challenges
{
    /**
     * @return list<Challenge>
     */
    public static function from(ResponseInterface $response): array
    {
        $challenges = [];

        foreach ($response->getHeader('WWW-Authenticate') as $line) {
            array_push($challenges, ...self::parse($line));
        }

        return $challenges;
    }

    /**
     * @return list<Challenge>
     */
    public static function parse(string $header): array
    {
        $tokenPattern = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]+';
        $challenges = [];
        $scheme = null;
        $parameters = [];
        $token68 = null;
        $at = 0;
        $length = strlen($header);

        while ($at < $length) {
            $at += strspn($header, " \t,", $at);

            if ($at >= $length) {
                break;
            }

            // name = value: a parameter of the current challenge.
            if ($scheme !== null && preg_match("/\\G({$tokenPattern})[ \\t]*=[ \\t]*(\"(?:[^\"\\\\]|\\\\.)*\"|{$tokenPattern})/", $header, $m, 0, $at) === 1) {
                $value = $m[2];
                $parameters[strtolower($m[1])] ??= str_starts_with($value, '"') ? stripcslashes(substr($value, 1, -1)) : $value;
                $at += strlen($m[0]);

                continue;
            }

            if (preg_match("/\\G({$tokenPattern})/", $header, $m, 0, $at) !== 1) {
                return $challenges;
            }

            if ($scheme !== null) {
                $challenges[] = new Challenge($scheme, $parameters, $token68);
            }

            $scheme = strtolower($m[1]);
            $parameters = [];
            $token68 = null;
            $at += strlen($m[0]);

            // A scheme can carry a token68 instead of parameters.
            if (preg_match('/\G[ \t]+([A-Za-z0-9\-._~+\/]+=*)[ \t]*(?=,|$)/', $header, $m, 0, $at) === 1) {
                $token68 = $m[1];
                $at += strlen($m[0]);
            }
        }

        if ($scheme !== null) {
            $challenges[] = new Challenge($scheme, $parameters, $token68);
        }

        return $challenges;
    }

    public static function bearer(ResponseInterface $response): ?Challenge
    {
        return array_find(self::from($response), static fn (Challenge $c): bool => $c->scheme === 'bearer');
    }
}
