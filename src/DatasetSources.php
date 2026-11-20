<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

/**
 * Sources a dataset can be built from (sylius:dataset:build --source=…). This package registers "ai" (a catalog
 * written by the AI from a description) and "existing" (the catalog fetched from a website); a project can register
 * others.
 *
 * A source writes products.yaml and collections.yaml into the directory of the dataset slug it receives (see
 * castor_host_dir()). Its products may lack their collection or price: the builder then lets the AI select and
 * complete them.
 */
final class DatasetSources
{
    /** @var array<string, \Closure(string, string, string, ?string, int): void> */
    private static array $sources = [];

    /**
     * @param callable(string $slug, string $name, string $description, ?string $url, int $limit): void $source
     */
    public static function register(string $name, callable $source): void
    {
        self::$sources[$name] = $source(...);
    }

    public static function unregister(string $name): void
    {
        unset(self::$sources[$name]);
    }

    /**
     * @return \Closure(string, string, string, ?string, int): void
     */
    public static function get(string $name): \Closure
    {
        return self::$sources[$name] ?? throw new \InvalidArgumentException(\sprintf(
            'Unknown dataset source "%s". Available sources: %s.',
            $name,
            implode(', ', self::names()) ?: 'none',
        ));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_keys(self::$sources);
        sort($names);

        return $names;
    }
}
