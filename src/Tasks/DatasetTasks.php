<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tasks;

use Castor\Api\Attribute\AsApi;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Exception\ProblemException;
use OpenColibri\Starter\DatasetBuilder\DatasetBuilder;
use OpenColibri\Starter\DatasetBuilder\DatasetSources;

use function Castor\io;
use function OpenColibri\Starter\DatasetBuilder\normalize_import_name;

use const OpenColibri\Starter\DatasetBuilder\IMPORT_AI_PRODUCT_LIMIT;
use const OpenColibri\Starter\DatasetBuilder\IMPORT_FETCHED_PRODUCT_LIMIT;

/**
 * Builds datasets with AI. See docs/datasets/build.md.
 */
final class DatasetTasks
{
    /**
     * @return iterable<int, array{task: AsTask, function: callable}>
     */
    public function __invoke(): iterable
    {
        yield [
            'task' => new AsTask('build', 'sylius:dataset', 'Build a dataset (catalog and images) with AI, from a description or an existing website'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'dataset:{dataset}')] function (
                #[AsOption(description: 'Where the catalog comes from: "ai" (written from the description) or "existing" (fetched from the website)')]
                string $source = 'ai',
                #[AsOption(description: 'Dataset slug (defaults to the slug of the name)')]
                ?string $dataset = null,
                #[AsOption]
                ?string $name = null,
                #[AsOption]
                ?string $description = null,
                #[AsOption(description: 'Website to import ("existing" source), or the site the AI catalog is for')]
                ?string $url = null,
                #[AsOption(description: 'Number of products (20 at most for an AI catalog, 100 by default for a fetched one)')]
                ?int $limit = null,
            ): void {
                self::run(static function () use ($source, $dataset, $name, $description, $url, $limit): void {
                    $name = trim((string) ($name ?? io()->ask('Name of the shop')));

                    if ('' === $name) {
                        throw new \InvalidArgumentException('Dataset name is required.');
                    }

                    if ('ai' === $source && '' === trim((string) ($description ??= io()->ask('Description of the shop')))) {
                        throw new \InvalidArgumentException('Dataset description is required.');
                    }

                    if ('ai' !== $source && '' === trim((string) $url)) {
                        throw new \InvalidArgumentException('Site URL is required.');
                    }

                    DatasetSources::get($source);
                    $limit = 'ai' === $source ? min($limit ?? IMPORT_AI_PRODUCT_LIMIT, IMPORT_AI_PRODUCT_LIMIT) : ($limit ?? IMPORT_FETCHED_PRODUCT_LIMIT);

                    if ($limit <= 0) {
                        throw new \InvalidArgumentException('Limit must be greater than 0.');
                    }

                    $slug = normalize_import_name('' !== trim((string) $dataset) ? (string) $dataset : $name);
                    DatasetBuilder::build($source, $slug, $name, trim((string) $description), $url, $limit);
                    io()->writeln(\sprintf('Next: castor sylius:dataset:load %s', $slug));
                });
            },
        ];

        yield [
            'task' => new AsTask('images', 'sylius:dataset', 'Download or generate the missing images of a dataset into its media/ directory'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(async: true, lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
            ): void {
                self::run(static function () use ($dataset): void {
                    $result = DatasetBuilder::storeImages(trim($dataset));
                    io()->success(\sprintf('%d image(s) stored for dataset %s.', $result['images'], trim($dataset)));
                });
            },
        ];

        yield [
            'task' => new AsTask('export', 'sylius:dataset', 'Zip a dataset (YAML and images), by default into .castor/import/exports/<dataset>.zip'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
                #[AsOption(description: 'Path of the zip file')]
                ?string $output = null,
            ): void {
                self::run(static function () use ($dataset, $output): void {
                    io()->success(\sprintf('Dataset exported to %s.', DatasetBuilder::export(trim($dataset), $output)));
                });
            },
        ];

        yield [
            'task' => new AsTask('delete', 'sylius:dataset', 'Delete a dataset built by sylius:dataset:build (catalogs loaded from it are kept)'),
            /** @phpstan-ignore-next-line  */
            'function' => #[AsApi(lock: 'dataset:{dataset}')] function (
                #[AsArgument]
                string $dataset,
            ): void {
                self::run(static function () use ($dataset): void {
                    if (stream_isatty(\STDIN) && !io()->confirm(\sprintf('Delete dataset "%s"?', $dataset), false)) {
                        io()->comment('Aborted.');

                        return;
                    }

                    DatasetBuilder::delete(trim($dataset));
                    io()->success(\sprintf('Dataset %s deleted.', trim($dataset)));
                });
            },
        ];
    }

    /**
     * Errors end the task with a non-zero exit code, so castor-api reports the run as failed.
     */
    private static function run(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new ProblemException($exception->getMessage(), previous: $exception);
        }
    }
}
