<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use OpenColibri\Starter\Core\Dataset\Dataset;
use OpenColibri\Starter\Core\Util\Log;
use OpenColibri\Starter\Core\Workspace;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

/**
 * The datasets built by this package, next to the shops of the sales kit. The sources (AI build, website fetch) and
 * the image storage read and write a dataset directory by its slug.
 */
function datasets_dir(): string
{
    return Workspace::importDir() . '/datasets';
}

function dataset_exports_dir(): string
{
    return Workspace::importDir() . '/exports';
}

function castor_var_dir(): string
{
    return datasets_dir();
}

function ensure_castor_var_dir(): void
{
    if (!is_dir(datasets_dir())) {
        mkdir(datasets_dir(), 0o775, true);
    }
}

function castor_host_dir(string $slug): string
{
    return datasets_dir() . '/' . $slug;
}

function ensure_castor_host_dir(string $slug): void
{
    if (!is_dir(castor_host_dir($slug))) {
        mkdir(castor_host_dir($slug), 0o775, true);
    }
}

function castor_var_path(string $slug, string $suffix = ''): string
{
    ensure_castor_host_dir($slug);

    return castor_host_dir($slug) . '/' . ('collections' === $suffix ? Dataset::COLLECTIONS_FILE : Dataset::PRODUCTS_FILE);
}

function castor_import_media_dir(string $slug): string
{
    $directory = castor_host_dir($slug) . '/' . Dataset::MEDIA_DIR;

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    return $directory;
}

/**
 * The path a dataset YAML stores for an image of its media/ directory.
 */
function import_media_fixture_path(string $slug, string $filename): string
{
    return Dataset::MEDIA_DIR . '/' . $filename;
}

/**
 * @return array<string, mixed>
 */
function load_yaml_file(string $path): array
{
    if (!file_exists($path)) {
        throw new \RuntimeException(\sprintf('File "%s" not found.', $path));
    }

    $data = Yaml::parseFile($path);

    if (!\is_array($data)) {
        throw new \RuntimeException(\sprintf('File "%s" is empty or invalid.', $path));
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function load_import_yaml(string $slug): array
{
    return load_yaml_file(castor_var_path($slug));
}

/**
 * @return array<string, mixed>
 */
function load_collections_yaml(string $slug): array
{
    return load_yaml_file(castor_var_path($slug, 'collections'));
}

/**
 * @return array<string, mixed>
 */
function load_dataset_config(string $slug): array
{
    $file = castor_host_dir($slug) . '/' . Dataset::CONFIG_FILE;

    return is_file($file) ? load_yaml_file($file) : [];
}

/**
 * @param array<string, mixed> $config
 */
function write_dataset_config(string $slug, array $config): void
{
    ensure_castor_host_dir($slug);
    file_put_contents(
        castor_host_dir($slug) . '/' . Dataset::CONFIG_FILE,
        Yaml::dump(array_filter($config, static fn(mixed $value): bool => null !== $value && '' !== $value), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
    );
}

/**
 * Keeps what a source tells about the catalog (name, description, site) in dataset.yaml.
 *
 * @param array{name?: string, description?: string, url?: string, source?: string} $metadata
 */
function persist_project_config(string $slug, array $metadata): void
{
    write_dataset_config($slug, array_filter([
        'name' => trim((string) ($metadata['name'] ?? '')),
        'description' => trim((string) ($metadata['description'] ?? '')),
        'url' => trim((string) ($metadata['url'] ?? $metadata['source'] ?? '')),
    ]) + load_dataset_config($slug));
}

function import_log(string $message): void
{
    Log::comment($message);
}

/**
 * Loads the AI settings (AI_PROVIDER, AI_MODEL…) of the project: .env, then .env.local.
 */
function ensure_import_ai_ready(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $root = Workspace::root();
    $dotenv = new Dotenv();

    foreach (['.env', '.env.dist'] as $file) {
        if (is_file($root . '/' . $file)) {
            $dotenv->load($root . '/' . $file);

            break;
        }
    }

    if (is_file($root . '/.env.local')) {
        $dotenv->overload($root . '/.env.local');
    }

    $loaded = true;
}
