<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OpenColibri\Starter\DatasetBuilder\Tests\CastorContainerTrait;

use function OpenColibri\Starter\DatasetBuilder\apply_taxon_grouping;
use function OpenColibri\Starter\DatasetBuilder\group_dataset_collections;
use function OpenColibri\Starter\DatasetBuilder\parse_taxon_grouping;

final class TaxonGroupingTest extends TestCase
{
    use CastorContainerTrait;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
    }

    public function testGroupsCollectionsIntoATwoLevelTree(): void
    {
        $taxonIndex = [
            $this->taxon('sweats_homme', 'Sweats Homme', 'https://example.test/sweats.jpg'),
            $this->taxon('sweat_capuche_homme', 'Sweat à Capuche Homme'),
            $this->taxon('t_shirt_homme_noel', 'T-shirt Homme pour Noël'),
            $this->taxon('t_shirts_femme', 'T-shirts Femme'),
            $this->taxon('empty', 'Empty'),
        ];

        [$index, $assignments] = apply_taxon_grouping(
            $taxonIndex,
            [1 => 'sweats_homme', 2 => 'sweat_capuche_homme', 3 => 'sweat_capuche_homme', 4 => 't_shirt_homme_noel', 5 => 't_shirts_femme', 6 => 'category'],
            [
                ['name' => 'Homme', 'children' => [
                    // The AI also put "T-shirts Femme" here: the Femme group wins as the name mentions it.
                    ['name' => 'T-shirts', 'collections' => ['T-shirts Femme', 'T-shirt Homme pour Noël']],
                    ['name' => 'Sweats', 'collections' => ['Sweat à Capuche Homme', 'sweats homme', 'Unknown']],
                ]],
                ['name' => 'Femme', 'children' => [
                    ['name' => 'T-shirts', 'collections' => ['T-shirts Femme', 'Sweats Homme']],
                ]],
            ],
        );

        static::assertSame([
            ['code' => 'homme', 'name' => 'Homme', 'slug' => 'homme', 'image' => 'https://example.test/sweats.jpg'],
            ['code' => 'homme_sweats', 'name' => 'Sweats', 'slug' => 'homme_sweats', 'image' => 'https://example.test/sweats.jpg', 'parent' => 'homme'],
            ['code' => 'homme_t_shirts', 'name' => 'T-shirts', 'slug' => 'homme_t_shirts', 'image' => null, 'parent' => 'homme'],
            ['code' => 'femme', 'name' => 'Femme', 'slug' => 'femme', 'image' => null],
            ['code' => 'femme_t_shirts', 'name' => 'T-shirts', 'slug' => 'femme_t_shirts', 'image' => null, 'parent' => 'femme'],
        ], $index);
        static::assertSame([
            1 => 'homme_sweats',
            2 => 'homme_sweats',
            3 => 'homme_sweats',
            4 => 'homme_t_shirts',
            5 => 'femme_t_shirts',
            6 => 'category',
        ], $assignments);
    }

    public function testCollectionsLeftOutOfTheTreeFallBackToTheRoot(): void
    {
        [$index, $assignments] = apply_taxon_grouping(
            [$this->taxon('polos', 'Polos'), $this->taxon('soldes', 'Soldes')],
            [1 => 'polos', 2 => 'soldes'],
            [
                ['name' => 'Homme', 'children' => [['name' => 'Polos', 'collections' => ['Polos']]]],
                ['name' => 'Vide', 'children' => [['name' => 'Rien', 'collections' => ['Unknown']]]],
            ],
        );

        static::assertSame(['homme', 'homme_polos'], array_column($index, 'code'));
        static::assertSame([1 => 'homme_polos'], $assignments);
    }

    public function testParsesTheAiTreeAndIgnoresMalformedEntries(): void
    {
        static::assertSame(
            [['name' => 'Homme', 'children' => [['name' => 'Sweats', 'collections' => ['Sweats Homme']]]]],
            parse_taxon_grouping(['groups' => [
                ['name' => ' Homme ', 'children' => [
                    ['name' => 'Sweats', 'collections' => ['Sweats Homme', '', 42]],
                    ['name' => 'Broken'],
                ]],
                'not a group',
            ]]),
        );
    }

    public function testSmallCatalogsKeepAFlatMenuWithoutCallingTheAi(): void
    {
        $products = [['title' => 'Polo bleu', 'collection_name' => 'Polos']];
        $collections = [['name' => 'Polos'], ['name' => 'Empty']];

        static::assertSame([$products, $collections], group_dataset_collections($products, $collections));
    }

    /**
     * @return array{code: string, name: string, slug: string, image: ?string}
     */
    private function taxon(string $code, string $name, ?string $image = null): array
    {
        return ['code' => $code, 'name' => $name, 'slug' => $code, 'image' => $image];
    }
}
