<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OpenColibri\Starter\DatasetBuilder\Dto\AiCatalogExtraction;
use OpenColibri\Starter\DatasetBuilder\Dto\AiProductEntry;
use OpenColibri\Starter\DatasetBuilder\Dto\CollectionEntry;
use OpenColibri\Starter\DatasetBuilder\Dto\CollectionExtraction;
use OpenColibri\Starter\DatasetBuilder\Dto\ProductSelectionEntry;
use OpenColibri\Starter\DatasetBuilder\Dto\ProductSelectionExtraction;
use OpenColibri\Starter\DatasetBuilder\Dto\ProductTaxonAssignmentEntry;
use OpenColibri\Starter\DatasetBuilder\Dto\ProductTaxonAssignmentExtraction;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

final class AiCatalogDtoTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    public static function dtoClassProvider(): array
    {
        return [
            [CollectionEntry::class],
            [CollectionExtraction::class],
            [AiProductEntry::class],
            [AiCatalogExtraction::class],
            [ProductSelectionEntry::class],
            [ProductSelectionExtraction::class],
            [ProductTaxonAssignmentEntry::class],
            [ProductTaxonAssignmentExtraction::class],
        ];
    }

    /**
     * Nested AI DTOs must live in their own PSR-4 files. Otherwise Symfony
     * type-info cannot resolve phpdoc like CollectionEntry[] when building
     * the structured-output schema from a sibling class.
     *
     * @param class-string $class
     */
    #[DataProvider('dtoClassProvider')]
    public function testDtoClassIsAutoloadableFromItsOwnFile(string $class): void
    {
        $relative = str_replace('OpenColibri\\Starter\\DatasetBuilder\\', '', $class);
        $expectedFile = \dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';

        static::assertFileExists($expectedFile);
        static::assertTrue(class_exists($class));
    }

    /**
     * @return list<class-string>
     */
    public static function structuredOutputClassProvider(): array
    {
        return [
            [AiCatalogExtraction::class],
            [CollectionExtraction::class],
            [ProductSelectionExtraction::class],
            [ProductTaxonAssignmentExtraction::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('structuredOutputClassProvider')]
    public function testStructuredOutputSchemaCanBeBuilt(string $class): void
    {
        if (!class_exists(ResponseFormatFactory::class)) {
            static::markTestSkipped('symfony/ai-platform is not installed in this vendor.');
        }

        $format = (new ResponseFormatFactory())->create($class);

        static::assertSame('json_schema', $format['type']);
        static::assertIsArray($format['json_schema']['schema']);
    }
}
