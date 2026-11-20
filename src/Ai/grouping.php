<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder;

use OpenColibri\Starter\DatasetBuilder\Dto\TaxonGroupingExtraction;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

use function Castor\io;

/**
 * Turns the collections of a dataset into a two-level menu (e.g. Men > T-shirts) via AI, when more than
 * IMPORT_TAXON_GROUPING_THRESHOLD collections hold products: the groups become collections, the merged collections
 * become their children (with a code, their names may repeat across groups), and each product points at its child.
 * Keeps the flat menu when the catalog is small enough or the AI grouping fails.
 *
 * @param list<array<string, mixed>> $products
 * @param list<array<string, mixed>> $collections
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function group_dataset_collections(array $products, array $collections, ?object $platform = null): array
{
    $taxonIndex = [];
    /** @var array<string, true> $usedCodes */
    $usedCodes = [];

    foreach ($collections as $collection) {
        $name = trim((string) ($collection['name'] ?? ''));

        if ('' === $name || isset($taxonIndex[normalize_label_for_matching($name)])) {
            continue;
        }

        $code = unique_grouped_taxon_code($name, $usedCodes);
        $image = \is_string($collection['image'] ?? null) && '' !== trim($collection['image']) ? trim($collection['image']) : null;
        $taxonIndex[normalize_label_for_matching($name)] = ['code' => $code, 'name' => $name, 'slug' => $code, 'image' => $image];
    }

    $taxonIndex = array_values($taxonIndex);
    $assignments = [];

    foreach ($products as $index => $product) {
        $products[$index]['id'] = $index;
        $code = resolve_collection_to_taxon_code(trim((string) ($product['collection_name'] ?? '')), $taxonIndex);

        if ('category' !== $code) {
            $assignments[$index] = $code;
        }
    }

    $described = describe_used_collections($taxonIndex, $assignments, $products);

    if (\count($described) <= IMPORT_TAXON_GROUPING_THRESHOLD) {
        import_log(\sprintf('%d collection(s) hold products — flat menu, no grouping.', \count($described)));

        return [strip_product_ids($products), $collections];
    }

    try {
        $grouping = group_collections_via_ai($platform ?? create_ai_platform(), $described);
        [$groupedIndex, $groupedAssignments] = apply_taxon_grouping($taxonIndex, $assignments, $grouping);
    } catch (\Throwable $exception) {
        io()->warning(\sprintf('AI taxon grouping failed, keeping a flat menu: %s', $exception->getMessage()));

        return [strip_product_ids($products), $collections];
    }

    if ([] === $groupedIndex) {
        io()->warning('AI taxon grouping returned no usable group, keeping a flat menu.');

        return [strip_product_ids($products), $collections];
    }

    $groupedCollections = [];

    foreach ($groupedIndex as $taxon) {
        $groupedCollections[] = array_filter([
            'name' => $taxon['name'],
            'code' => $taxon['code'],
            'parent' => $taxon['parent'] ?? null,
            'image' => $taxon['image'],
        ], static fn(mixed $value): bool => null !== $value);

        if (!isset($taxon['parent'])) {
            import_log(\sprintf('  %s: %s', $taxon['name'], implode(', ', array_column(
                array_filter($groupedIndex, static fn(array $child): bool => ($child['parent'] ?? null) === $taxon['code']),
                'name',
            ))));
        }
    }

    foreach ($products as $index => $product) {
        unset($products[$index]['collection_name']);

        if (isset($groupedAssignments[$index])) {
            $products[$index]['collection'] = $groupedAssignments[$index];
        }
    }

    return [strip_product_ids($products), $groupedCollections];
}

/**
 * @param list<array<string, mixed>> $products
 *
 * @return list<array<string, mixed>>
 */
function strip_product_ids(array $products): array
{
    return array_values(array_map(static function (array $product): array {
        unset($product['id']);

        return $product;
    }, $products));
}

/**
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}> $taxonIndex
 * @param array<int, string>                                                         $taxonAssignments
 * @param array<int, array<string, mixed>>                                           $selectedProducts
 *
 * @return array<int, array{name: string, products: int, examples: string[]}>
 */
function describe_used_collections(array $taxonIndex, array $taxonAssignments, array $selectedProducts): array
{
    $titles = [];

    foreach ($selectedProducts as $product) {
        $titles[(int) ($product['id'] ?? -1)] = trim((string) ($product['title'] ?? ''));
    }

    $examplesByCode = [];

    foreach ($taxonAssignments as $productId => $code) {
        $examplesByCode[$code][] = $titles[$productId] ?? '';
    }

    $collections = [];

    foreach ($taxonIndex as $taxon) {
        $titlesInTaxon = $examplesByCode[$taxon['code']] ?? [];

        if ([] === $titlesInTaxon) {
            continue;
        }

        $collections[] = [
            'name' => $taxon['name'],
            'products' => \count($titlesInTaxon),
            'examples' => \array_slice(array_values(array_filter($titlesInTaxon, static fn(string $title): bool => '' !== $title)), 0, 3),
        ];
    }

    return $collections;
}

/**
 * @param array<int, array{name: string, products: int, examples: string[]}> $collections
 *
 * @return array<int, array{name: string, children: array<int, array{name: string, collections: string[]}>}>
 */
function group_collections_via_ai(object $platform, array $collections): array
{
    $groupLimit = IMPORT_TAXON_GROUP_LIMIT;
    $childLimit = IMPORT_TAXON_GROUP_CHILD_LIMIT;

    $messages = new MessageBag(
        Message::forSystem(
            <<<PROMPT
                You organize the navigation menu of an online shop.
                You receive the shop's collections that hold products, with their product count and example product titles.

                Return a two-level menu:
                - at most {$groupLimit} groups (top-level menu entries), each with at most {$childLimit} children;
                - groups follow the main axis shoppers browse by in this catalog (audience such as Men/Women/Kids, or product family);
                - children are product types: merge near-duplicate collections into one child (e.g. "Crew neck sweatshirts for men" and "Hooded sweatshirts for men" become "Sweatshirts" under "Men");
                - seasonal, promotional or marketing collections (sales, Christmas, Father's Day, new arrivals, best sellers, gift ideas, packs) are not menu entries: never create a group or a child for them, put each one in the child matching its products and audience;
                - a collection naming an audience (e.g. "Women", "Men") goes in that audience's group;
                - every collection goes in exactly one child, its name copied character-for-character in "collections";
                - group and child names are short (1 to 3 words), in the language of the collections, and a child name does not repeat its group name.
                PROMPT
        ),
        Message::ofUser(json_encode(['collections' => $collections], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
    );

    $text = invoke_ai_structured(
        $platform,
        $messages,
        TaxonGroupingExtraction::class,
        'Taxon grouping',
        build_taxon_grouping_response_format(array_column($collections, 'name')),
    );

    return parse_taxon_grouping(decode_ai_json(normalize_ai_json($text)));
}

/**
 * @param string[] $collectionNames
 *
 * @return array<string, mixed>
 */
function build_taxon_grouping_response_format(array $collectionNames): array
{
    $responseFormat = (new ResponseFormatFactory())->create(TaxonGroupingExtraction::class);
    $responseFormat['json_schema']['schema']['properties']['groups']['items']['properties']['children']['items']['properties']['collections']['items']['enum'] = array_values($collectionNames);

    return $responseFormat;
}

/**
 * @param array<string, mixed> $data
 *
 * @return array<int, array{name: string, children: array<int, array{name: string, collections: string[]}>}>
 */
function parse_taxon_grouping(array $data): array
{
    $groups = $data['groups'] ?? null;

    if (!\is_array($groups)) {
        throw new \RuntimeException('AI response has no groups array.');
    }

    $parsed = [];

    foreach ($groups as $group) {
        if (!\is_array($group) || !\is_array($group['children'] ?? null)) {
            continue;
        }

        $children = [];

        foreach ($group['children'] as $child) {
            if (!\is_array($child) || !\is_array($child['collections'] ?? null)) {
                continue;
            }

            $children[] = [
                'name' => trim((string) ($child['name'] ?? '')),
                'collections' => array_values(array_filter(
                    array_map(static fn(mixed $name): string => \is_string($name) ? trim($name) : '', $child['collections']),
                    static fn(string $name): bool => '' !== $name,
                )),
            ];
        }

        $parsed[] = ['name' => trim((string) ($group['name'] ?? '')), 'children' => $children];
    }

    return $parsed;
}

/**
 * Builds the grouped taxons from the AI tree: each child becomes a taxon holding the products of its collections,
 * under a group taxon. Unknown collection names are ignored, and products of a collection the tree left out fall back
 * to the root taxon. A collection the AI placed in several groups goes to the group its name mentions (e.g. "Pulls
 * Femme" goes to "Femme"), otherwise to the first one.
 *
 * @param array<int, array{code: string, name: string, slug: string, image: ?string}>                     $taxonIndex
 * @param array<int, string>                                                                             $taxonAssignments
 * @param array<int, array{name: string, children: array<int, array{name: string, collections: string[]}>}> $grouping
 *
 * @return array{0: array<int, array{code: string, name: string, slug: string, image: ?string, parent?: string}>, 1: array<int, string>}
 */
function apply_taxon_grouping(array $taxonIndex, array $taxonAssignments, array $grouping): array
{
    $productCounts = array_count_values($taxonAssignments);
    $collectionsByName = [];

    foreach ($taxonIndex as $taxon) {
        if (($productCounts[$taxon['code']] ?? 0) > 0) {
            $collectionsByName[normalize_label_for_matching($taxon['name'])] = $taxon;
        }
    }

    $grouping = \array_slice(array_values(array_filter($grouping, static fn(array $group): bool => '' !== $group['name'])), 0, IMPORT_TAXON_GROUP_LIMIT);
    /** @var array<string, array{0: int, 1: int}> $owners source collection code => [group key, child key] */
    $owners = [];

    foreach ($grouping as $groupKey => $group) {
        foreach ($group['children'] as $childKey => $child) {
            foreach ($child['collections'] as $name) {
                $source = $collectionsByName[normalize_label_for_matching($name)] ?? null;

                if (null === $source) {
                    continue;
                }

                $owner = $owners[$source['code']] ?? null;

                if (null === $owner || (
                    !label_mentions($source['name'], $grouping[$owner[0]]['name'])
                    && label_mentions($source['name'], $group['name'])
                )) {
                    $owners[$source['code']] = [$groupKey, $childKey];
                }
            }
        }
    }

    $index = [];
    /** @var array<string, string> $targetCodes source collection code => grouped taxon code */
    $targetCodes = [];
    /** @var array<string, true> $usedCodes */
    $usedCodes = [];

    foreach ($grouping as $groupKey => $group) {
        $groupCode = unique_grouped_taxon_code($group['name'], $usedCodes);
        $children = [];
        /** @var string[] $overflowCodes collections of the children past the limit, kept in the group itself */
        $overflowCodes = [];

        foreach ($group['children'] as $childKey => $child) {
            $sources = [];

            foreach ($child['collections'] as $name) {
                $source = $collectionsByName[normalize_label_for_matching($name)] ?? null;

                if (null !== $source && [$groupKey, $childKey] === ($owners[$source['code']] ?? null) && !isset($targetCodes[$source['code']])) {
                    $sources[] = $source;
                    $targetCodes[$source['code']] = '';
                }
            }

            if ([] === $sources) {
                continue;
            }

            if ('' === $child['name'] || \count($children) >= IMPORT_TAXON_GROUP_CHILD_LIMIT) {
                array_push($overflowCodes, ...array_column($sources, 'code'));

                continue;
            }

            $childCode = unique_grouped_taxon_code($group['name'] . ' ' . $child['name'], $usedCodes);
            usort($sources, static fn(array $a, array $b): int => $productCounts[$b['code']] <=> $productCounts[$a['code']]);
            $children[] = [
                'code' => $childCode,
                'name' => $child['name'],
                'slug' => $childCode,
                'image' => first_taxon_image($sources),
                'parent' => $groupCode,
                'products' => array_sum(array_map(static fn(array $source): int => $productCounts[$source['code']], $sources)),
            ];

            foreach ($sources as $source) {
                $targetCodes[$source['code']] = $childCode;
            }
        }

        foreach ($overflowCodes as $code) {
            $targetCodes[$code] = $groupCode;
        }

        if ([] === $children) {
            foreach ($overflowCodes as $code) {
                unset($targetCodes[$code]);
            }
            unset($usedCodes[$groupCode]);

            continue;
        }

        usort($children, static fn(array $a, array $b): int => $b['products'] <=> $a['products']);
        $index[] = [
            'code' => $groupCode,
            'name' => $group['name'],
            'slug' => $groupCode,
            'image' => first_taxon_image($children),
        ];

        foreach ($children as $child) {
            unset($child['products']);
            $index[] = $child;
        }
    }

    $sourceCodes = array_column($taxonIndex, 'code', 'code');
    $assignments = [];

    foreach ($taxonAssignments as $productId => $code) {
        if (!isset($sourceCodes[$code])) {
            // Codes outside the index (e.g. the "category" fallback) are left alone.
            $assignments[$productId] = $code;
        } elseif ('' !== ($targetCodes[$code] ?? '')) {
            $assignments[$productId] = $targetCodes[$code];
        }
    }

    return [$index, $assignments];
}

/**
 * Whether every word of $needle is a word of $label, accents and case aside.
 */
function label_mentions(string $label, string $needle): bool
{
    $words = static fn(string $text): array => preg_split('/[^a-z0-9]+/', normalize_label_for_matching($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    $needleWords = $words($needle);

    return [] !== $needleWords && [] === array_diff($needleWords, $words($label));
}

/**
 * @param array<int, array{image: ?string}> $taxons
 */
function first_taxon_image(array $taxons): ?string
{
    foreach ($taxons as $taxon) {
        if (null !== $taxon['image'] && '' !== $taxon['image']) {
            return $taxon['image'];
        }
    }

    return null;
}

/**
 * @param array<string, true> $usedCodes
 */
function unique_grouped_taxon_code(string $name, array &$usedCodes): string
{
    $baseCode = taxon_code_from_name($name);
    $code = $baseCode;

    for ($suffix = 2; isset($usedCodes[$code]); ++$suffix) {
        $code = \sprintf('%s_%d', $baseCode, $suffix);
    }

    $usedCodes[$code] = true;

    return $code;
}
