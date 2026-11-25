<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit;

use OpenColibri\Starter\Core\Dataset\DatasetDirectories;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Core\Workspace;
use OpenColibri\Starter\DatasetBuilder\DatasetBuilder;
use OpenColibri\Starter\DatasetBuilder\DatasetSources;
use OpenColibri\Starter\DatasetBuilder\Tests\CastorContainerTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use function OpenColibri\Starter\DatasetBuilder\castor_host_dir;
use function OpenColibri\Starter\DatasetBuilder\download_import_image;
use function OpenColibri\Starter\DatasetBuilder\persist_project_config;

final class DatasetBuilderTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/dataset-builder-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.castor', 0o775, true);
        Workspace::setRoot($this->root);
        $this->writeDataset($this->root . '/builtin/wine', ['name' => 'Wine Merchant', 'theme' => 'cellar']);
        DatasetDirectories::register('test', $this->root . '/builtin');
    }

    protected function tearDown(): void
    {
        DatasetDirectories::unregister('test');
        DatasetSources::unregister('test');
        Workspace::setRoot(null);
        (new Filesystem())->remove($this->root);
    }

    public function testBuiltDatasetsAreListedAfterTheBuiltInOnes(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House', 'source' => 'ai']);
        // A built dataset cannot shadow a built-in one.
        $this->writeDataset($this->root . '/.castor/import/datasets/wine', ['name' => 'Other wine']);

        static::assertArrayHasKey('tea', Datasets::all());
        static::assertFalse(Datasets::get('tea')->builtIn);
        static::assertTrue(Datasets::get('wine')->builtIn);
        static::assertSame('Wine Merchant', Datasets::get('wine')->name());
    }

    public function testCopiesTheLocalImagesInsteadOfDownloadingThem(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House']);

        static::assertSame('media/tea_margaux.webp', download_import_image('media/margaux.webp', 'tea', 'tea_margaux', quiet: true));
        static::assertSame('bottle', file_get_contents(castor_host_dir('tea') . '/media/tea_margaux.webp'));
        static::assertNull(download_import_image('media/missing.webp', 'tea', 'tea_missing', quiet: true));
        static::assertNull(download_import_image('../../../../builtin/wine/media/margaux.webp', 'tea', 'tea_escape', quiet: true));
    }

    public function testBuildsADatasetAndStoresItsImages(): void
    {
        DatasetSources::register('test', static function (string $slug, string $name, string $description): void {
            $dir = castor_host_dir($slug);
            mkdir($dir . '/media', 0o775, true);
            file_put_contents($dir . '/products.yaml', Yaml::dump(['mode' => 'ai', 'products' => [
                ['title' => 'Green Tea', 'collection_name' => 'Green', 'price_eur' => 9, 'image' => '../../../../source.webp'],
                ['title' => 'Black Tea', 'collection_name' => 'Black', 'price_eur' => 8, 'image' => 'media/stale.webp'],
            ]]));
            file_put_contents($dir . '/collections.yaml', Yaml::dump(['collections' => [['name' => 'Green'], ['name' => 'Black']]]));
            persist_project_config($slug, ['name' => $name, 'description' => $description]);
            file_put_contents($dir . '/media/stale.webp', 'stale');
            file_put_contents($dir . '/media/unused.webp', 'unused');
        });

        $dataset = DatasetBuilder::build('test', 'tea', 'Tea House', 'Loose leaf teas', null, 20);

        static::assertFalse($dataset->builtIn);
        static::assertSame('Tea House', $dataset->name());
        static::assertSame('test', $dataset->configValue('source'));
        static::assertSame('Loose leaf teas', $dataset->configValue('description'));

        $products = $dataset->products();
        // ".." never leaves the dataset directory: the first image is dropped, the second one is stored.
        static::assertSame('../../../../source.webp', $products[0]['image']);
        static::assertSame('media/black_tea.webp', $products[1]['image']);
        static::assertSame('stale', file_get_contents($dataset->path . '/media/black_tea.webp'));
        static::assertFileDoesNotExist($dataset->path . '/media/unused.webp');
        static::assertFileDoesNotExist($dataset->path . '/media/stale.webp');
        // The dataset format: the keys of the source are dropped.
        static::assertArrayNotHasKey('mode', Yaml::parseFile($dataset->path . '/products.yaml'));
        static::assertSame([], glob($this->root . '/.castor/import/var/.progress/*') ?: [], 'The progress files are removed.');

        $this->expectExceptionMessage('"wine" is a built-in dataset');
        DatasetBuilder::build('test', 'wine', 'Wine', 'Wine', null, 20);
    }

    public function testBuildsADatasetWithoutItsImages(): void
    {
        DatasetSources::register('test', static function (string $slug): void {
            file_put_contents(castor_host_dir($slug) . '/products.yaml', Yaml::dump(['products' => [
                ['title' => 'Green Tea', 'collection_name' => 'Green', 'price_eur' => 9, 'image_prompt' => 'A cup of green tea'],
                ['title' => 'Black Tea', 'collection_name' => 'Green', 'price_eur' => 8, 'image' => 'https://example.com/black.jpg'],
            ]]));
            file_put_contents(castor_host_dir($slug) . '/collections.yaml', Yaml::dump(['collections' => [['name' => 'Green']]]));
        });

        $dataset = DatasetBuilder::build('test', 'tea', 'Tea House', 'Teas', null, 20, images: false);

        // Nothing is downloaded or generated: the prompt and the URL stay, for sylius:dataset:images.
        static::assertSame('A cup of green tea', $dataset->products()[0]['image_prompt']);
        static::assertSame('https://example.com/black.jpg', $dataset->products()[1]['image']);
        static::assertSame([], glob($dataset->path . '/media/*') ?: []);
        static::assertSame(0, $dataset->describe()['imageCount']);
    }

    public function testKeepsThePreviousCatalogWhenTheSourceFails(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House']);
        DatasetSources::register('test', static function (): void {});

        try {
            DatasetBuilder::build('test', 'tea', 'Tea House', 'Teas', null, 20);
            static::fail('The build fails.');
        } catch (\RuntimeException $exception) {
            static::assertStringContainsString('did not write any product', $exception->getMessage());
        }

        static::assertCount(2, Datasets::get('tea')->products());

        // A first build that fails leaves no empty directory.
        try {
            DatasetBuilder::build('test', 'coffee', 'Coffee', 'Coffees', null, 20);
            static::fail('The build fails.');
        } catch (\RuntimeException) {
        }

        static::assertDirectoryDoesNotExist($this->root . '/.castor/import/datasets/coffee');
    }

    public function testExportsADatasetAsAZip(): void
    {
        $zipPath = DatasetBuilder::export('wine');

        static::assertSame($this->root . '/.castor/import/exports/wine.zip', $zipPath);

        $zip = new \ZipArchive();
        static::assertTrue($zip->open($zipPath));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }

        static::assertSame(['wine/dataset.yaml', 'wine/products.yaml', 'wine/collections.yaml', 'wine/media/margaux.webp'], $names);
        static::assertSame('bottle', $zip->getFromName('wine/media/margaux.webp'));
        $zip->close();
    }

    public function testDeletesOnlyBuiltDatasets(): void
    {
        $this->writeDataset($this->root . '/.castor/import/datasets/tea', ['name' => 'Tea House']);

        DatasetBuilder::delete('tea');
        static::assertDirectoryDoesNotExist($this->root . '/.castor/import/datasets/tea');

        $this->expectExceptionMessage('"wine" is a built-in dataset: it cannot be deleted.');
        DatasetBuilder::delete('wine');
    }

    /**
     * @param array<string, string> $config
     */
    private function writeDataset(string $dir, array $config): void
    {
        mkdir($dir . '/media', 0o775, true);
        file_put_contents($dir . '/dataset.yaml', Yaml::dump($config));
        file_put_contents($dir . '/collections.yaml', Yaml::dump(['collections' => [['name' => 'Bordeaux']]]));
        file_put_contents($dir . '/products.yaml', Yaml::dump(['products' => [
            ['title' => 'Margaux', 'collection_name' => 'Bordeaux', 'price_eur' => 60, 'image' => 'media/margaux.webp'],
            ['title' => 'Médoc', 'collection_name' => 'Bordeaux', 'price_eur' => 20],
        ]]));
        file_put_contents($dir . '/media/margaux.webp', 'bottle');
    }
}
