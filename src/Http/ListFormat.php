<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * How a list goes into a query string or a form body.
 *
 * There's no standard: RFC 3986 says nothing about repeated names, and the
 * HTML form encoding only says pairs go in order. APIs read lists one of
 * three ways, so the SDK says which. Only lists (`array_is_list()`) change;
 * a keyed array is always `key[name]=value`.
 */
enum ListFormat
{
    /**
     * `expand[0]=a&expand[1]=b`, as PHP's `http_build_query()` writes it.
     * The default, so nothing changes unless an SDK asks.
     */
    case Indexed;

    /**
     * `expand[]=a&expand[]=b`, the form PHP and Rails read back as a list.
     */
    case Brackets;

    /**
     * `expand=a&expand=b`: the name repeated, as most non-PHP servers read
     * a list.
     */
    case Repeated;
}
