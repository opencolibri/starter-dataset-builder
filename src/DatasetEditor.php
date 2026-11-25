<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Core\Workspace;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes datasets by hand, without AI: an empty dataset, then its collections and products one by one, or a whole
 * dataset imported from a zip (the layout of sylius:dataset:export).
 *
 * Images and zips are uploads: files of the .castor/import/var/.uploads directory, shared with the sales kit admin,
 * named by their file name. They are removed once used.
 */
final class DatasetEditor
{
    /** The source of a dataset written by hand. */
    public const string SOURCE_MANUAL = 'manual';

    /** The source of an imported dataset whose dataset.yaml names none. */
    public const string SOURCE_IMPORT = 'import';

    private const string UPLOAD = '/^[A-Za-z0-9][A-Za-z0-9_-]*\.(zip|webp|png|jpe?g|gif|avif)$/';

    private const string MEDIA = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|png|jpe?g|gif|avif)$/i';

    /** Limits of an imported zip, uncompressed. */
    private const int IMPORT_MAX_ENTRIES = 5000;
    private const int IMPORT_MAX_BYTES = 1_073_741_824;

    public static function uploadsDir(): string
    {
        return Workspace::importDir() . '/var/.uploads';
    }

    /**
     * An empty dataset: its name, description and suggested theme. Products and collections are added afterwards.
     */
    public static function create(string $slug, string $name, string $description = '', ?string $theme = null): Dataset
    {
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Dataset name is required.');
        }

        $slug = normalize_import_name('' !== trim($slug) ? $slug : $name);
        $existing = Datasets::find($slug);

        if (null !== $existing) {
            throw new \RuntimeException(\sprintf('A dataset "%s" already exists: choose another name.', $slug));
        }

        $filesystem = new Filesystem();
        $filesystem->dumpFile(castor_var_path($slug), Yaml::dump(['products' => []]));
        $filesystem->dumpFile(castor_var_path($slug, 'collections'), Yaml::dump(['collections' => []]));
        write_dataset_config($slug, [
            'name' => $name,
            'description' => trim($description),
            'source' => self::SOURCE_MANUAL,
            'theme' => self::theme($theme),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        return Datasets::get($slug);
    }

    /**
     * Changes the name, description and suggested theme of a dataset; the rest of dataset.yaml is kept.
     */
    public static function update(string $slug, string $name, string $description = '', ?string $theme = null): void
    {
        self::editable($slug);
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Dataset name is required.');
        }

        $config = load_dataset_config($slug);
        $config['name'] = $name;
        $config['description'] = trim($description);
        $config['theme'] = self::theme($theme);
        write_dataset_config($slug, $config);
    }

    /**
     * Adds a collection (index null) or changes the one at this index of collections.yaml. Renaming a collection
     * moves its products and sub-collections along. $expected is the name the caller saw at this index: the edit is
     * refused when the file changed in between.
     *
     * @param array{name: string, description?: string|null, parent?: string|null} $values
     */
    public static function saveCollection(string $slug, ?int $index, ?string $expected, array $values, ?string $upload = null): void
    {
        self::editable($slug);
        $collections = self::collections($slug);
        $products = self::products($slug);
        $name = trim($values['name']);
        $parent = trim((string) ($values['parent'] ?? ''));

        if ('' === $name) {
            throw new \InvalidArgumentException('Collection name is required.');
        }

        $collection = null === $index ? [] : self::item($collections['collections'], $index, 'name', $expected, 'collection');
        $previous = trim((string) ($collection['name'] ?? ''));

        if ('' !== $parent && null === self::findCollection($collections['collections'], $parent, $index)) {
            throw new \InvalidArgumentException(\sprintf('Unknown parent collection "%s".', $parent));
        }

        if ('' !== $parent && ($parent === $previous || (isset($collection['code']) && $parent === $collection['code']))) {
            throw new \InvalidArgumentException('A collection cannot be its own parent.');
        }

        // The shop menu has two levels: a parent is a top-level collection, and a parent stays at the top level.
        if ('' !== $parent && '' !== trim((string) (self::findCollection($collections['collections'], $parent, $index)['parent'] ?? ''))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is already under another collection: pick a top-level one.', $parent));
        }

        if ('' !== $parent && '' !== $previous && self::hasChildren($collections['collections'], $collection)) {
            throw new \InvalidArgumentException(\sprintf('"%s" has sub-collections: it stays at the top level.', $previous));
        }

        foreach ($collections['collections'] as $other => $item) {
            if ($other !== $index && \is_array($item) && trim((string) ($item['name'] ?? '')) === $name && trim((string) ($item['parent'] ?? '')) === $parent) {
                throw new \InvalidArgumentException(\sprintf('A collection "%s" already exists.', $name));
            }
        }

        $collection['name'] = $name;
        $collection = self::withText($collection, 'description', $values['description'] ?? null);
        $collection = self::withText($collection, 'parent', $parent);

        $previousImage = $collection['image'] ?? null;

        if (null !== $upload && '' !== $upload) {
            $collection['image'] = self::storeUpload($slug, $upload, 'collection_' . taxon_code_from_name($name));
        }

        if (null === $index) {
            $collections['collections'][] = $collection;
        } else {
            $collections['collections'][$index] = $collection;
        }

        // Products and sub-collections follow a renamed collection (those pointing at its code are unchanged).
        if ('' !== $previous && $previous !== $name) {
            foreach ($products['products'] as $key => $product) {
                if (\is_array($product) && trim((string) ($product['collection_name'] ?? '')) === $previous) {
                    $products['products'][$key]['collection_name'] = $name;
                }
            }

            foreach ($collections['collections'] as $key => $item) {
                if ($key !== $index && \is_array($item) && trim((string) ($item['parent'] ?? '')) === $previous) {
                    $collections['collections'][$key]['parent'] = $name;
                }
            }
        }

        self::write($slug, $products, $collections);
        self::removeUnusedMedia($slug, $previousImage);
    }

    /**
     * Removes a collection: its products and sub-collections stay, without a collection or a parent.
     */
    public static function removeCollection(string $slug, int $index, ?string $expected): void
    {
        self::editable($slug);
        $collections = self::collections($slug);
        $products = self::products($slug);
        $collection = self::item($collections['collections'], $index, 'name', $expected, 'collection');
        $keys = array_filter([trim((string) ($collection['name'] ?? '')), trim((string) ($collection['code'] ?? ''))]);

        foreach ($products['products'] as $key => $product) {
            if (\is_array($product) && (\in_array(trim((string) ($product['collection_name'] ?? '')), $keys, true) || \in_array(trim((string) ($product['collection'] ?? '')), $keys, true))) {
                unset($products['products'][$key]['collection_name'], $products['products'][$key]['collection']);
            }
        }

        foreach ($collections['collections'] as $key => $item) {
            if (\is_array($item) && \in_array(trim((string) ($item['parent'] ?? '')), $keys, true)) {
                unset($collections['collections'][$key]['parent']);
            }
        }

        array_splice($collections['collections'], $index, 1);
        self::write($slug, $products, $collections);
        self::removeUnusedMedia($slug, $collection['image'] ?? null);
    }

    /**
     * Adds a product (index null) or changes the one at this index of products.yaml. Its other keys (code, original
     * price…) are kept. $expected is the title the caller saw at this index.
     *
     * @param array{title: string, collection?: string|null, price?: float|int|string|null, description?: string|null} $values
     */
    public static function saveProduct(string $slug, ?int $index, ?string $expected, array $values, ?string $upload = null): void
    {
        self::editable($slug);
        $collections = self::collections($slug);
        $products = self::products($slug);
        $title = trim($values['title']);
        $collectionKey = trim((string) ($values['collection'] ?? ''));
        $price = $values['price'] ?? null;

        if ('' === $title) {
            throw new \InvalidArgumentException('Product title is required.');
        }

        if (null !== $price && '' !== $price && (!is_numeric($price) || (float) $price < 0)) {
            throw new \InvalidArgumentException('The price must be a positive number.');
        }

        $product = null === $index ? [] : self::item($products['products'], $index, 'title', $expected, 'product');
        $product['title'] = $title;
        unset($product['collection_name'], $product['collection']);

        if ('' !== $collectionKey) {
            $collection = self::findCollection($collections['collections'], $collectionKey)
                ?? throw new \InvalidArgumentException(\sprintf('Unknown collection "%s".', $collectionKey));

            // A collection with a code is pointed at by its code: its name may repeat in another group.
            if ('' !== trim((string) ($collection['code'] ?? ''))) {
                $product['collection'] = trim((string) $collection['code']);
            } else {
                $product['collection_name'] = trim((string) $collection['name']);
            }
        }

        if (null === $price || '' === $price) {
            unset($product['price_eur']);
        } else {
            $product['price_eur'] = round((float) $price, 2);
        }

        $product = self::withText($product, 'description', $values['description'] ?? null);

        $previousImage = $product['image'] ?? null;

        if (null !== $upload && '' !== $upload) {
            $usedCodes = [];
            $code = \is_string($product['code'] ?? null) && '' !== $product['code'] ? $product['code'] : resolve_product_code($product, $usedCodes);
            $product['image'] = self::storeUpload($slug, $upload, $code);
            unset($product['image_prompt']);
        }

        if (null === $index) {
            $products['products'][] = $product;
        } else {
            $products['products'][$index] = $product;
        }

        (new Filesystem())->dumpFile(castor_var_path($slug), self::dump($products));
        self::removeUnusedMedia($slug, $previousImage);
    }

    public static function removeProduct(string $slug, int $index, ?string $expected): void
    {
        self::editable($slug);
        $products = self::products($slug);
        $product = self::item($products['products'], $index, 'title', $expected, 'product');
        array_splice($products['products'], $index, 1);
        (new Filesystem())->dumpFile(castor_var_path($slug), self::dump($products));
        self::removeUnusedMedia($slug, $product['image'] ?? null);
    }

    /**
     * Imports a dataset from a zip: a "<slug>/" directory (as sylius:dataset:export writes it) or the files at its
     * root, i.e. dataset.yaml, products.yaml, collections.yaml and media/. Other files are ignored.
     *
     * @param string|null $slug    the slug of the dataset; the directory of the zip, or the slug of its name, otherwise
     * @param bool        $replace replaces the dataset of this slug built here, if any
     */
    public static function import(string $upload, ?string $slug = null, bool $replace = false): Dataset
    {
        $zipPath = self::upload($upload);

        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('The zip PHP extension is required to import a dataset.');
            }

            $zip = new \ZipArchive();

            if (true !== $zip->open($zipPath, \ZipArchive::RDONLY)) {
                throw new \RuntimeException('The file is not a valid zip archive.');
            }

            try {
                [$root, $entries] = self::zipEntries($zip);

                if (!isset($entries[Dataset::PRODUCTS_FILE])) {
                    throw new \RuntimeException('The zip holds no products.yaml: export a dataset to see the expected layout.');
                }

                $config = self::parseYaml((string) $zip->getFromIndex($entries[Dataset::CONFIG_FILE] ?? -1), Dataset::CONFIG_FILE, isset($entries[Dataset::CONFIG_FILE]));
                $products = self::parseYaml((string) $zip->getFromIndex($entries[Dataset::PRODUCTS_FILE]), Dataset::PRODUCTS_FILE);

                if (!\is_array($products['products'] ?? null)) {
                    throw new \RuntimeException('products.yaml holds no "products" list.');
                }

                if (isset($entries[Dataset::COLLECTIONS_FILE])) {
                    $collections = self::parseYaml((string) $zip->getFromIndex($entries[Dataset::COLLECTIONS_FILE]), Dataset::COLLECTIONS_FILE);

                    if (isset($collections['collections']) && !\is_array($collections['collections'])) {
                        throw new \RuntimeException('collections.yaml holds no "collections" list.');
                    }
                }

                $name = trim((string) ($config['name'] ?? $products['name'] ?? ''));
                $slug = trim((string) $slug);
                $slug = normalize_import_name('' !== $slug ? $slug : ($root ?? ('' !== $name ? $name : pathinfo($upload, \PATHINFO_FILENAME))));
                $existing = Datasets::find($slug);

                if (null !== $existing && $existing->builtIn) {
                    throw new \RuntimeException(\sprintf('"%s" is a ready-made dataset: import it under another name.', $slug));
                }

                if (null !== $existing && !$replace) {
                    throw new \RuntimeException(\sprintf('A dataset "%s" already exists: replace it, or import it under another name.', $slug));
                }

                $filesystem = new Filesystem();
                ensure_castor_var_dir();
                $staging = datasets_dir() . '/.import-' . bin2hex(random_bytes(6));
                $filesystem->mkdir($staging . '/' . Dataset::MEDIA_DIR);

                try {
                    foreach ($entries as $file => $entryIndex) {
                        $stream = $zip->getStream((string) $zip->getNameIndex($entryIndex));

                        if (false === $stream) {
                            throw new \RuntimeException(\sprintf('Could not read "%s" in the zip.', $file));
                        }

                        $target = fopen($staging . '/' . $file, 'w');

                        if (false === $target) {
                            throw new \RuntimeException(\sprintf('Could not write "%s".', $file));
                        }

                        stream_copy_to_stream($stream, $target);
                        fclose($stream);
                        fclose($target);
                    }

                    if (!isset($entries[Dataset::COLLECTIONS_FILE])) {
                        $filesystem->dumpFile($staging . '/' . Dataset::COLLECTIONS_FILE, Yaml::dump(['collections' => []]));
                    }

                    $config['name'] = '' !== $name ? $name : ucwords(str_replace('-', ' ', $slug));
                    $config['source'] = trim((string) ($config['source'] ?? '')) ?: self::SOURCE_IMPORT;
                    $config['created_at'] ??= (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                    $filesystem->dumpFile($staging . '/' . Dataset::CONFIG_FILE, Yaml::dump(array_filter($config, static fn(mixed $value): bool => null !== $value && '' !== $value), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

                    // The dataset it replaces is put back if the new one cannot take its place.
                    $backup = $staging . '.previous';

                    if (is_dir(castor_host_dir($slug))) {
                        $filesystem->rename(castor_host_dir($slug), $backup);
                    }

                    try {
                        $filesystem->rename($staging, castor_host_dir($slug));
                    } catch (\Throwable $exception) {
                        if (is_dir($backup)) {
                            $filesystem->rename($backup, castor_host_dir($slug), true);
                        }

                        throw $exception;
                    }

                    $filesystem->remove($backup);
                } finally {
                    $filesystem->remove($staging);
                }
            } finally {
                $zip->close();
            }
        } finally {
            (new Filesystem())->remove($zipPath);
        }

        return Datasets::get($slug);
    }

    /**
     * The files of the dataset in the zip, by their path in the dataset directory, and the directory they are in.
     *
     * @return array{0: string|null, 1: array<string, int>}
     */
    private static function zipEntries(\ZipArchive $zip): array
    {
        if ($zip->numFiles > self::IMPORT_MAX_ENTRIES) {
            throw new \RuntimeException(\sprintf('The zip holds more than %d files.', self::IMPORT_MAX_ENTRIES));
        }

        $paths = [];
        $size = 0;

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            $path = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            if ('' === $path || str_ends_with($path, '/') || str_starts_with($path, '__MACOSX/') || str_starts_with(basename($path), '.')) {
                continue;
            }

            if (str_starts_with($path, '/') || \in_array('..', explode('/', $path), true)) {
                throw new \RuntimeException(\sprintf('The zip holds an unsafe path: "%s".', $path));
            }

            $size += (int) ($stat['size'] ?? 0);
            $paths[$path] = $index;
        }

        if ($size > self::IMPORT_MAX_BYTES) {
            throw new \RuntimeException('The zip is too large once uncompressed.');
        }

        // The dataset files are at the root, or in a single directory (an export).
        $root = null;

        if (!isset($paths[Dataset::PRODUCTS_FILE])) {
            $roots = array_unique(array_map(static fn(string $path): string => explode('/', $path)[0], array_keys(array_filter($paths, static fn(string $path): bool => str_ends_with($path, '/' . Dataset::PRODUCTS_FILE) && 2 === \count(explode('/', $path)), \ARRAY_FILTER_USE_KEY))));
            $root = 1 === \count($roots) ? reset($roots) : null;
        }

        $entries = [];

        foreach ($paths as $path => $index) {
            if (null !== $root) {
                if (!str_starts_with($path, $root . '/')) {
                    continue;
                }

                $path = substr($path, \strlen($root) + 1);
            }

            $parts = explode('/', $path);

            if (1 === \count($parts) && \in_array($path, Dataset::FILES, true)) {
                $entries[$path] = $index;
            } elseif (2 === \count($parts) && Dataset::MEDIA_DIR === $parts[0] && preg_match(self::MEDIA, $parts[1])) {
                $entries[$path] = $index;
            }
        }

        return [$root, $entries];
    }

    /**
     * @return array<mixed>
     */
    private static function parseYaml(string $yaml, string $file, bool $required = true): array
    {
        if (!$required) {
            return [];
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \RuntimeException(\sprintf('%s is not valid YAML: %s', $file, $exception->getMessage()), 0, $exception);
        }

        return \is_array($data) ? $data : [];
    }

    private static function editable(string $slug): Dataset
    {
        $dataset = Datasets::get(trim($slug));

        if ($dataset->builtIn) {
            throw new \RuntimeException(\sprintf('"%s" is a ready-made dataset: it cannot be changed. Download it and import the zip under another name to change a copy.', $slug));
        }

        return $dataset;
    }

    /**
     * @return array{products: array<int, mixed>}&array<string, mixed>
     */
    private static function products(string $slug): array
    {
        $data = load_import_yaml($slug);
        $data['products'] = array_values(\is_array($data['products'] ?? null) ? $data['products'] : []);

        return $data;
    }

    /**
     * @return array{collections: array<int, mixed>}&array<string, mixed>
     */
    private static function collections(string $slug): array
    {
        $data = is_file(castor_var_path($slug, 'collections')) ? load_collections_yaml($slug) : [];
        $data['collections'] = array_values(\is_array($data['collections'] ?? null) ? $data['collections'] : []);

        return $data;
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return array<string, mixed>
     */
    private static function item(array $items, int $index, string $key, ?string $expected, string $label): array
    {
        $item = $items[$index] ?? null;

        if (!\is_array($item)) {
            throw new \RuntimeException(\sprintf('Unknown %s #%d: the dataset changed, reload the page.', $label, $index));
        }

        if (null !== $expected && trim((string) ($item[$key] ?? '')) !== trim($expected)) {
            throw new \RuntimeException(\sprintf('The %s #%d is no longer "%s": the dataset changed, reload the page.', $label, $index, $expected));
        }

        return $item;
    }

    /**
     * A collection by its code, or else its name.
     *
     * @param array<int, mixed> $collections
     *
     * @return array<string, mixed>|null
     */
    private static function findCollection(array $collections, string $key, ?int $except = null): ?array
    {
        foreach (['code', 'name'] as $field) {
            foreach ($collections as $index => $collection) {
                if ($index !== $except && \is_array($collection) && trim((string) ($collection[$field] ?? '')) === $key) {
                    return $collection;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed>    $collections
     * @param array<string, mixed> $collection
     */
    private static function hasChildren(array $collections, array $collection): bool
    {
        $keys = array_filter([trim((string) ($collection['name'] ?? '')), trim((string) ($collection['code'] ?? ''))]);

        foreach ($collections as $item) {
            if (\is_array($item) && \in_array(trim((string) ($item['parent'] ?? '')), $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function withText(array $item, string $key, ?string $value): array
    {
        $value = trim((string) $value);

        if ('' === $value) {
            unset($item[$key]);
        } else {
            $item[$key] = $value;
        }

        return $item;
    }

    private static function theme(?string $theme): ?string
    {
        $theme = trim((string) $theme);

        if ('' === $theme) {
            return null;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $theme)) {
            throw new \InvalidArgumentException(\sprintf('Invalid theme "%s".', $theme));
        }

        return $theme;
    }

    /**
     * @param array<string, mixed> $products
     * @param array<string, mixed> $collections
     */
    private static function write(string $slug, array $products, array $collections): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile(castor_var_path($slug), self::dump($products));
        $filesystem->dumpFile(castor_var_path($slug, 'collections'), self::dump($collections));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function dump(array $data): string
    {
        return Yaml::dump($data, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private static function upload(string $upload): string
    {
        $upload = trim($upload);
        $path = self::uploadsDir() . '/' . $upload;

        if (!preg_match(self::UPLOAD, $upload) || !is_file($path)) {
            throw new \RuntimeException(\sprintf('Upload "%s" not found.', $upload));
        }

        return $path;
    }

    /**
     * Converts an uploaded image to WebP into media/, under a new name (the admin caches the images). The caller
     * removes the image it replaces once the YAML no longer points at it.
     *
     * @return string the image path to write in the YAML
     */
    private static function storeUpload(string $slug, string $upload, string $code): string
    {
        $source = self::upload($upload);

        try {
            $code = preg_replace('/[^A-Za-z0-9_-]+/', '_', $code) ?: 'image';
            $file = \sprintf('%s_%s.webp', substr($code, 0, 80), bin2hex(random_bytes(3)));
            $target = castor_import_media_dir($slug) . '/' . $file;

            if (!convert_image_to_webp($source, $target, quiet: true)) {
                if ('webp' !== strtolower(pathinfo($source, \PATHINFO_EXTENSION)) || !copy($source, $target)) {
                    throw new \RuntimeException('The image could not be read: upload a JPEG, PNG, GIF or WebP file.');
                }
            }
        } finally {
            (new Filesystem())->remove($source);
        }

        return Dataset::MEDIA_DIR . '/' . $file;
    }

    /**
     * Removes an image of media/ that no product or collection points at anymore.
     */
    private static function removeUnusedMedia(string $slug, mixed $image): void
    {
        if (!\is_string($image) || !str_starts_with(trim($image), Dataset::MEDIA_DIR . '/')) {
            return;
        }

        $image = trim($image);
        $file = basename($image);

        if (!preg_match(self::MEDIA, $file) || Dataset::MEDIA_DIR . '/' . $file !== $image) {
            return;
        }

        foreach ([...self::products($slug)['products'], ...self::collections($slug)['collections']] as $item) {
            if (\is_array($item) && trim((string) ($item['image'] ?? '')) === $image) {
                return;
            }
        }

        (new Filesystem())->remove(castor_host_dir($slug) . '/' . $image);
    }
}
