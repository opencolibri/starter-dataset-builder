<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use function Castor\io;

/**
 * Whether the products still lack their collection or price: a catalog fetched from a website lists every product
 * found, curate_dataset_products() selects and completes them.
 *
 * @param list<array<string, mixed>> $products
 */
function dataset_needs_curation(array $products): bool
{
    foreach ($products as $product) {
        if (!isset($product['collection_name']) && !isset($product['collection'])) {
            return true;
        }

        if (!isset($product['price_eur'])) {
            return true;
        }
    }

    return false;
}

/**
 * Lets the AI pick the products to keep from a fetched catalog, then assign their collection and price, so the
 * dataset is final: loading it needs no AI.
 *
 * @param list<array<string, mixed>> $products
 * @param list<array<string, mixed>> $collections
 *
 * @return list<array<string, mixed>>
 */
function curate_dataset_products(array $products, array $collections, int $limit, ?object $platform = null): array
{
    $catalog = build_product_catalog($products);
    $productMap = build_product_map($products);
    $collectionNames = build_collection_names($collections);

    if ([] === $catalog) {
        throw new \RuntimeException('No products with titles found in the fetched catalog.');
    }

    $platform ??= create_ai_platform();
    import_log(\sprintf('AI provider: %s, model: %s.', ai_provider(), ai_model()));

    if ($limit > \count($catalog)) {
        $limit = \count($catalog);
    }

    io()->info(\sprintf('Selecting %d products from %d via AI...', $limit, \count($catalog)));
    $selectedIds = select_products_via_ai($platform, $catalog, $collectionNames, $limit);
    $selectedIds = merge_promo_products_into_selection($selectedIds, pick_promo_product_ids($catalog), $limit);

    io()->info(\sprintf('Assigning collections and prices to %d products via AI...', \count($selectedIds)));
    $enrichment = assign_taxons_via_ai($platform, $catalog, $selectedIds, $collectionNames);

    $curated = [];
    /** @var array<string, true> $usedCodes */
    $usedCodes = [];

    foreach (deduplicate_products_for_fixtures(filter_products_by_ids($productMap, $selectedIds)) as $product) {
        $id = (int) $product['id'];
        $title = trim((string) $product['title']);
        $assignment = $enrichment[$id] ?? null;

        $curated[] = array_filter([
            'title' => $title,
            // Stable product codes: from the product URL, like the shop it comes from.
            'code' => resolve_product_code($product, $usedCodes),
            'url' => trim((string) ($product['url'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'collection_name' => null !== $assignment && 'category' !== $assignment['collection_name'] ? $assignment['collection_name'] : null,
            'price_eur' => null !== $assignment ? normalize_price_eur((float) $assignment['price_eur'], $id) : IMPORT_PRICE_EUR_FALLBACK,
            'image' => trim((string) ($product['image'] ?? '')),
        ], static fn(mixed $value): bool => null !== $value && '' !== $value);
    }

    import_log(\sprintf('Catalog curated: %d product(s).', \count($curated)));

    return $curated;
}
