<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\Method;

it('knows which methods are safe and idempotent (RFC 9110 9.2)', function (Method $method, bool $safe, bool $idempotent): void {
    expect($method->isSafe())->toBe($safe)
        ->and($method->isIdempotent())->toBe($idempotent);
})->with([
    [Method::Get, true, true],
    [Method::Head, true, true],
    [Method::Options, true, true],
    [Method::Put, false, true],
    [Method::Delete, false, true],
    [Method::Post, false, false],
    [Method::Patch, false, false],
]);
