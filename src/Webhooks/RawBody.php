<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Webhooks;

use Psr\Http\Message\RequestInterface;

/**
 * @internal
 */
final class RawBody
{
    public static function of(RequestInterface $request): string
    {
        $body = $request->getBody();
        $content = (string) $body;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $content;
    }
}
