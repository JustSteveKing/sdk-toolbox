<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Hydration;

use JustSteveKing\SdkToolbox\Errors\HydrationException;

/**
 * Turns decoded JSON into typed objects.
 *
 * A port, so an SDK isn't tied to one mapper: ValinorHydrator is the one
 * the toolbox ships, and anything else (Symfony's serializer, Crell's
 * Serde, hand-written factories) fits behind the same two methods.
 */
interface Hydrator
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws HydrationException when the data doesn't fit the class
     */
    public function hydrate(string $class, mixed $data): object;

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     *
     * @throws HydrationException when the data isn't a list of the class
     */
    public function hydrateList(string $class, mixed $data): array;
}
