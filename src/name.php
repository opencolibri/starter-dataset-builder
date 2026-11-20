<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

function normalize_import_name(string $name): string
{
    $name = trim($name);

    if ('' === $name) {
        throw new \InvalidArgumentException('Project name is required.');
    }

    $normalized = mb_strtolower($name);

    if (\function_exists('transliterator_transliterate')) {
        $normalized = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $normalized) ?: $normalized;
    } elseif (\function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        $normalized = false !== $converted ? strtolower($converted) : $normalized;
    }

    $normalized = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $slug = trim($normalized, '-');

    if ('' === $slug) {
        throw new \InvalidArgumentException('Project name must contain at least one alphanumeric character.');
    }

    return $slug;
}
