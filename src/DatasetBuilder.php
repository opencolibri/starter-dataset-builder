<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Core\Progress\TaskProgress;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use function Castor\io;

/**
 * Builds the datasets of .castor/import/datasets/: a source writes the catalog, the AI completes it (product selection,
 * collections and prices of a fetched catalog, a two-level menu for many collections), then its images are stored in
 * media/. The dataset is then final: loading it needs neither the source site nor an AI (see Dataset).
 */
final class DatasetBuilder
{
    /**
     * Builds (or rebuilds) a dataset. The previous catalog is kept when the source fails.
     */
    public static function build(string $source, string $slug, string $name, string $description, ?string $url, int $limit): Dataset
    {
        $build = DatasetSources::get($source);
        $existing = Datasets::find($slug);

        if (null !== $existing && $existing->builtIn) {
            throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: choose another name.', $slug));
        }

        $filesystem = new Filesystem();
        $previousConfig = load_dataset_config($slug);
        $productsPath = castor_var_path($slug);
        $backupPath = $productsPath . '.previous';

        if (is_file($productsPath)) {
            $filesystem->rename($productsPath, $backupPath, true);
        }

        $progressKey = TaskProgress::datasetKey($slug);
        // A single AI or fetch call: the step has no intermediate progress.
        TaskProgress::start($progressKey, 'ai' === $source ? 'Writing the catalog with AI' : 'Fetching the catalog', 1);

        try {
            $build($slug, $name, $description, $url, $limit);
        } finally {
            TaskProgress::finish($progressKey);
        }

        // The sources report their errors and return: keep the previous catalog.
        if (!is_file($productsPath)) {
            if (is_file($backupPath)) {
                $filesystem->rename($backupPath, $productsPath);
            }

            throw new \RuntimeException(\sprintf('The %s build of dataset "%s" did not write any product.', $source, $slug));
        }

        $filesystem->remove($backupPath);
        self::complete($slug, $limit);

        $config = load_dataset_config($slug);
        write_dataset_config($slug, [
            'name' => trim((string) ($config['name'] ?? $name)),
            'description' => trim((string) ($config['description'] ?? $description)),
            'url' => trim((string) ($config['url'] ?? $url ?? '')),
            'source' => $source,
            'theme' => $previousConfig['theme'] ?? null,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        self::storeImages($slug);
        io()->success(\sprintf('Dataset %s built.', $slug));

        return Datasets::get($slug);
    }

    /**
     * Makes the catalog final: selects and completes the products of a fetched catalog, groups many collections into
     * a two-level menu, and rewrites the YAML in the dataset format.
     */
    public static function complete(string $slug, int $limit): void
    {
        $productsData = load_import_yaml($slug);
        $products = array_values(array_filter(\is_array($productsData['products'] ?? null) ? $productsData['products'] : [], \is_array(...)));
        $collectionsData = is_file(castor_var_path($slug, 'collections')) ? load_collections_yaml($slug) : [];
        $collections = array_values(array_filter(\is_array($collectionsData['collections'] ?? null) ? $collectionsData['collections'] : [], \is_array(...)));

        ensure_import_ai_ready();

        if (dataset_needs_curation($products)) {
            $products = curate_dataset_products($products, $collections, $limit);
        }

        [$products, $collections] = group_dataset_collections($products, $collections);

        $filesystem = new Filesystem();
        $filesystem->dumpFile(castor_var_path($slug), Yaml::dump(['products' => $products], 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $filesystem->dumpFile(castor_var_path($slug, 'collections'), Yaml::dump(['collections' => $collections], 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }

    /**
     * Downloads (image URL) or generates (image prompt) the image of every product and collection of a dataset into
     * its media/ directory, and points the YAML at it. Images that are already there are kept; media files no longer
     * used are removed.
     *
     * @return array{images: int, failures: int}
     */
    public static function storeImages(string $slug): array
    {
        $dataset = Datasets::get($slug);

        if ($dataset->builtIn) {
            throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: its images are already stored.', $slug));
        }

        $productsData = load_import_yaml($slug);
        $products = \is_array($productsData['products'] ?? null) ? $productsData['products'] : [];
        $collectionsData = is_file(castor_var_path($slug, 'collections')) ? load_collections_yaml($slug) : [];
        $collections = \is_array($collectionsData['collections'] ?? null) ? $collectionsData['collections'] : [];
        $styleReferencePrompt = find_first_image_prompt($products);

        // Downloads and image generation read the AI and HTTP settings of the project.
        foreach ([...$products, ...$collections] as $item) {
            $image = \is_array($item) ? trim((string) ($item['image'] ?? '')) : '';

            if (null !== $styleReferencePrompt || ('' !== $image && !Dataset::isLocalImage($image))) {
                ensure_import_ai_ready();

                break;
            }
        }

        $used = [];
        $images = 0;
        $failures = 0;
        /** @var array<string, true> $usedCodes */
        $usedCodes = [];
        $progressKey = TaskProgress::datasetKey($slug);
        $isNamed = static fn(mixed $item, string $key): bool => \is_array($item) && '' !== trim((string) ($item[$key] ?? ''));

        io()->section(\sprintf('Storing the images of dataset %s', $slug));
        TaskProgress::start($progressKey, 'Product images', \count(array_filter($products, static fn(mixed $product): bool => $isNamed($product, 'title'))));

        foreach ($products as $index => $product) {
            if (!$isNamed($product, 'title')) {
                continue;
            }

            TaskProgress::current($progressKey, trim((string) $product['title']));
            $image = self::storeImage(
                $slug,
                \is_string($product['code'] ?? null) && '' !== $product['code'] ? $product['code'] : resolve_product_code($product, $usedCodes),
                trim((string) ($product['image'] ?? '')),
                trim((string) ($product['image_prompt'] ?? '')),
            );
            TaskProgress::advance($progressKey);

            if (null === $image) {
                $failures += '' !== trim((string) ($product['image'] ?? '')) || '' !== trim((string) ($product['image_prompt'] ?? '')) ? 1 : 0;

                continue;
            }

            $products[$index]['image'] = $image;
            $used[$image] = true;
            ++$images;
        }

        TaskProgress::start($progressKey, 'Collection images', \count(array_filter($collections, static fn(mixed $collection): bool => $isNamed($collection, 'name'))));
        /** @var array<string, true> $usedTaxonCodes */
        $usedTaxonCodes = [];

        foreach ($collections as $index => $collection) {
            if (!$isNamed($collection, 'name')) {
                continue;
            }

            $name = trim((string) $collection['name']);
            TaskProgress::current($progressKey, $name);
            $image = self::storeImage(
                $slug,
                'collection_' . unique_taxon_code(\is_string($collection['code'] ?? null) ? $collection['code'] : $name, $usedTaxonCodes),
                \is_string($collection['image'] ?? null) && 'null' !== strtolower(trim($collection['image'])) ? trim($collection['image']) : '',
                null !== $styleReferencePrompt ? \sprintf('Category thumbnail for "%s", same visual style as: %s', $name, $styleReferencePrompt) : '',
            );
            TaskProgress::advance($progressKey);

            if (null !== $image) {
                $collections[$index]['image'] = $image;
                $used[$image] = true;
            }
        }

        TaskProgress::finish($progressKey);

        $filesystem = new Filesystem();
        $productsData['products'] = $products;
        $filesystem->dumpFile(castor_var_path($slug), Yaml::dump($productsData, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        if ([] !== $collectionsData) {
            $collectionsData['collections'] = $collections;
            $filesystem->dumpFile(castor_var_path($slug, 'collections'), Yaml::dump($collectionsData, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        foreach (glob(castor_import_media_dir($slug) . '/*') ?: [] as $file) {
            if (is_file($file) && !isset($used[Dataset::MEDIA_DIR . '/' . basename($file)])) {
                $filesystem->remove($file);
            }
        }

        import_log(\sprintf('Dataset images: %d/%d product(s) with an image.', $images, \count($products)));

        if ($failures > 0) {
            io()->warning(\sprintf('%d product image(s) could not be downloaded or generated.', $failures));
        }

        return ['images' => $images, 'failures' => $failures];
    }

    /**
     * Zips a dataset into a "<slug>/" directory (dataset.yaml, products.yaml, collections.yaml, media/), the layout
     * of a built-in dataset.
     */
    public static function export(string $slug, ?string $output = null): string
    {
        $dataset = Datasets::get($slug);
        $output = null !== $output && '' !== trim($output) ? trim($output) : dataset_exports_dir() . '/' . $slug . '.zip';

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The zip PHP extension is required to export a dataset.');
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($output));
        $temporary = $output . '.tmp';
        $zip = new \ZipArchive();

        if (true !== $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(\sprintf('Could not create "%s".', $output));
        }

        foreach (Dataset::FILES as $file) {
            if (is_file($dataset->path . '/' . $file)) {
                $zip->addFile($dataset->path . '/' . $file, $slug . '/' . $file);
            }
        }

        $media = glob($dataset->mediaDir() . '/*') ?: [];
        sort($media);

        foreach ($media as $file) {
            if (is_file($file)) {
                $entry = $slug . '/' . Dataset::MEDIA_DIR . '/' . basename($file);
                $zip->addFile($file, $entry);
                // Images are already compressed.
                $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
            }
        }

        if (!$zip->close()) {
            throw new \RuntimeException(\sprintf('Could not write "%s".', $output));
        }

        $filesystem->rename($temporary, $output, true);

        return $output;
    }

    public static function delete(string $slug): void
    {
        $dataset = Datasets::get($slug);

        if ($dataset->builtIn) {
            throw new \RuntimeException(\sprintf('"%s" is a built-in dataset: it cannot be deleted.', $slug));
        }

        $root = realpath(datasets_dir());
        $path = realpath($dataset->path);

        if (false === $root || false === $path || !str_starts_with($path, $root . \DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(\sprintf('Refusing to delete "%s" outside of the datasets directory.', $dataset->path));
        }

        (new Filesystem())->remove([$path, dataset_exports_dir() . '/' . $slug . '.zip']);
    }

    /**
     * Returns the image path relative to the dataset directory, or null.
     */
    private static function storeImage(string $slug, string $code, string $image, string $prompt): ?string
    {
        $filename = $code . '.webp';

        // A stored image that went missing is generated again from its prompt.
        if ('' !== $prompt && Dataset::isLocalImage($image) && null === resolve_local_import_image_path($image, $slug)) {
            $image = '';
        }

        if ('' !== $image) {
            $stored = download_import_image($image, $slug, $code, quiet: true);
        } elseif ('' !== $prompt) {
            $stored = generate_import_image_from_prompt($prompt, $slug, $code, quiet: true);
        } else {
            return null;
        }

        return null !== $stored ? Dataset::MEDIA_DIR . '/' . $filename : null;
    }
}
