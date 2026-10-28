<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Hydration;

use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use JustSteveKing\SdkToolbox\Errors\HydrationException;

/**
 * Hydration with Valinor (cuyz/valinor), strict about types and relaxed
 * about extra fields.
 *
 * - **Extra keys are allowed.** An API adding a field to a response must
 *   not break every SDK built before it, so superfluous keys are ignored.
 * - **snake_case keys map to camelCase properties,** so `created_at` fills
 *   `$createdAt`.
 * - **Types are strict.** A string where an int belongs is a mapping
 *   error, not a quiet cast, and the error says where.
 *
 * Pass your own MapperBuilder to change any of that, or to add Valinor's
 * caching for production.
 */
final readonly class ValinorHydrator implements Hydrator
{
    private TreeMapper $mapper;

    public function __construct(?MapperBuilder $builder = null)
    {
        $this->mapper = ($builder ?? self::defaults())->mapper();
    }

    public static function available(): bool
    {
        return class_exists(MapperBuilder::class);
    }

    public static function defaults(): MapperBuilder
    {
        return (new MapperBuilder)
            ->allowSuperfluousKeys()
            ->configureWith(new ConvertKeysToCamelCase);
    }

    public function hydrate(string $class, mixed $data): object
    {
        try {
            return $this->mapper->map($class, $data);
        } catch (MappingError $e) {
            throw self::failed($class, $e);
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function hydrateList(string $class, mixed $data): array
    {
        try {
            /** @var list<T> $list Valinor checked every item against the signature */
            $list = $this->mapper->map("list<{$class}>", $data);
        } catch (MappingError $e) {
            throw self::failed("list<{$class}>", $e);
        }

        return $list;
    }

    private static function failed(string $type, MappingError $e): HydrationException
    {
        $errors = [];

        foreach ($e->messages()->errors() as $message) {
            $errors[] = "{$message->path()}: {$message->toString()}";
        }

        return new HydrationException($type, $errors, $e);
    }
}
