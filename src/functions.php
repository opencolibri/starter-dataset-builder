<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use OpenColibri\Starter\Core\Dataset\DatasetDirectories;
use OpenColibri\Starter\Core\Task\TaskProviderRegistry;
use OpenColibri\Starter\DatasetBuilder\Tasks\DatasetTasks;

use function Castor\io;
use function OpenColibri\Starter\DatasetBuilder\Fetch\fetch_import_data;

/** Shown before a website is fetched. */
const SCRAPING_WARNING = 'This command scrapes the website you point it to. Scraping may be forbidden by the website terms of use, by copyright and database rights (images, descriptions, catalog), or by personal data law. Only fetch a website you own or have the written permission to copy, for example to migrate an existing shop to Sylius.';

TaskProviderRegistry::register('dataset-builder', static fn(): iterable => (new DatasetTasks())());

// The datasets built here are editable, unlike the built-in ones.
DatasetDirectories::register('built', static fn(): string => datasets_dir(), builtIn: false);

DatasetSources::register(
    'ai',
    static function (string $slug, string $name, string $description, ?string $url, int $limit): void {
        build_ai_import_data($name, $description, $url, min($limit, IMPORT_AI_PRODUCT_LIMIT), $slug);
    },
);

DatasetSources::register(
    'existing',
    static function (string $slug, string $name, string $description, ?string $url): void {
        io()->warning(SCRAPING_WARNING);
        fetch_import_data($url, $name, $description, $slug);
    },
);
