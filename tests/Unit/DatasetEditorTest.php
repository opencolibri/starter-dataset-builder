<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit;

use OpenColibri\Starter\Core\Dataset\DatasetDirectories;
use OpenColibri\Starter\Core\Dataset\Datasets;
use OpenColibri\Starter\Core\Workspace;
use OpenColibri\Starter\DatasetBuilder\DatasetEditor;
use OpenColibri\Starter\DatasetBuilder\Tests\CastorContainerTrait;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use function OpenColibri\Starter\DatasetBuilder\castor_host_dir;

final class DatasetEditorTest extends TestCase
{
    use CastorContainerTrait;

    private string $root;

    protected function setUp(): void
    {
        $this->setUpCastorContainer();
        $this->root = sys_get_temp_dir() . '/dataset-editor-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.castor', 0o775, true);
        mkdir($this->root . '/builtin/wine', 0o775, true);
        file_put_contents($this->root . '/builtin/wine/products.yaml', Yaml::dump(['products' => [['title' => 'Margaux']]]));
        Workspace::setRoot($this->root);
        DatasetDirectories::register('test', $this->root . '/builtin');
    }

    protected function tearDown(): void
    {
        DatasetDirectories::unregister('test');
        Workspace::setRoot(null);
        (new Filesystem())->remove($this->root);
    }

    public function testCreatesAnEmptyDataset(): void
    {
        $dataset = DatasetEditor::create('', 'Tea House', 'Loose leaf teas', 'teahouse');

        static::assertSame('tea-house', $dataset->slug);
        static::assertFalse($dataset->builtIn);
        static::assertSame([], $dataset->products());
        static::assertSame('Tea House', $dataset->name());
        static::assertSame('manual', $dataset->configValue('source'));
        static::assertSame('teahouse', $dataset->configValue('theme'));

        DatasetEditor::update('tea-house', 'Tea Garden', '', null);
        static::assertSame('Tea Garden', Datasets::get('tea-house')->name());
        static::assertNull(Datasets::get('tea-house')->configValue('theme'));
        static::assertSame('manual', Datasets::get('tea-house')->configValue('source'));
    }

    public function testRefusesAnExistingOrReadyMadeSlug(): void
    {
        DatasetEditor::create('', 'Tea House');

        foreach (['Tea House', 'Wine'] as $name) {
            try {
                DatasetEditor::create('', $name);
                static::fail(\sprintf('"%s" was created twice.', $name));
            } catch (\RuntimeException $exception) {
                static::assertStringContainsString('already exists', $exception->getMessage());
            }
        }

        $this->expectExceptionMessage('ready-made dataset');
        DatasetEditor::saveProduct('wine', null, null, ['title' => 'Pomerol']);
    }

    public function testEditsCollectionsAndProducts(): void
    {
        DatasetEditor::create('', 'Tea House');
        DatasetEditor::saveCollection('tea-house', null, null, ['name' => 'Green', 'description' => 'Fresh teas']);
        DatasetEditor::saveCollection('tea-house', null, null, ['name' => 'Black']);
        DatasetEditor::saveProduct('tea-house', null, null, ['title' => 'Sencha', 'collection' => 'Green', 'price' => '9.5', 'description' => 'Grassy']);
        DatasetEditor::saveProduct('tea-house', null, null, ['title' => 'Assam', 'collection' => 'Black', 'price' => 8]);

        // Renaming a collection moves its products along.
        DatasetEditor::saveCollection('tea-house', 0, 'Green', ['name' => 'Green teas']);
        $dataset = Datasets::get('tea-house');
        static::assertSame([
            ['title' => 'Sencha', 'collection_name' => 'Green teas', 'price_eur' => 9.5, 'description' => 'Grassy'],
            ['title' => 'Assam', 'collection_name' => 'Black', 'price_eur' => 8.0],
        ], $dataset->products());
        static::assertSame([['name' => 'Green teas'], ['name' => 'Black']], $dataset->collections());

        // A sub-collection, then a third level is refused.
        DatasetEditor::saveCollection('tea-house', null, null, ['name' => 'Darjeeling', 'parent' => 'Black']);

        try {
            DatasetEditor::saveCollection('tea-house', null, null, ['name' => 'First flush', 'parent' => 'Darjeeling']);
            static::fail('A third level was added.');
        } catch (\InvalidArgumentException $exception) {
            static::assertStringContainsString('top-level', $exception->getMessage());
        }

        // Editing a product keeps its other keys; the expected title guards against a stale page.
        DatasetEditor::saveProduct('tea-house', 1, 'Assam', ['title' => 'Assam TGFOP', 'collection' => 'Darjeeling', 'price' => '']);
        static::assertSame(['title' => 'Assam TGFOP', 'collection_name' => 'Darjeeling'], Datasets::get('tea-house')->products()[1]);

        try {
            DatasetEditor::saveProduct('tea-house', 1, 'Assam', ['title' => 'Other']);
            static::fail('A stale edit was saved.');
        } catch (\RuntimeException $exception) {
            static::assertStringContainsString('reload the page', $exception->getMessage());
        }

        // Removing a collection keeps its products, without a collection, and its sub-collections at the top level.
        DatasetEditor::removeCollection('tea-house', 1, 'Black');
        static::assertSame([['name' => 'Green teas'], ['name' => 'Darjeeling']], Datasets::get('tea-house')->collections());

        DatasetEditor::removeProduct('tea-house', 0, 'Sencha');
        static::assertSame([['title' => 'Assam TGFOP', 'collection_name' => 'Darjeeling']], Datasets::get('tea-house')->products());
    }

    #[RequiresFunction('imagewebp')]
    public function testStoresTheUploadedImagesAsWebp(): void
    {
        DatasetEditor::create('', 'Tea House');
        DatasetEditor::saveProduct('tea-house', null, null, ['title' => 'Sencha'], $this->uploadImage('first.png'));

        $image = Datasets::get('tea-house')->products()[0]['image'];
        static::assertMatchesRegularExpression('#^media/sencha_[0-9a-f]{6}\.webp$#', $image);
        static::assertNotNull(Datasets::get('tea-house')->imageFile($image));
        static::assertFileDoesNotExist(DatasetEditor::uploadsDir() . '/first.png');

        // A new image replaces the previous file.
        DatasetEditor::saveProduct('tea-house', 0, 'Sencha', ['title' => 'Sencha'], $this->uploadImage('second.jpg'));
        $replaced = Datasets::get('tea-house')->products()[0]['image'];
        static::assertNotSame($image, $replaced);
        static::assertFileDoesNotExist(castor_host_dir('tea-house') . '/' . $image);
        static::assertFileExists(castor_host_dir('tea-house') . '/' . $replaced);

        DatasetEditor::removeProduct('tea-house', 0, 'Sencha');
        static::assertFileDoesNotExist(castor_host_dir('tea-house') . '/' . $replaced);
    }

    public function testImportsAnExportedZip(): void
    {
        $this->uploadZip('export.zip', [
            'tea/dataset.yaml' => Yaml::dump(['name' => 'Tea House', 'theme' => 'teahouse']),
            'tea/products.yaml' => Yaml::dump(['products' => [['title' => 'Sencha', 'image' => 'media/sencha.webp']]]),
            'tea/media/sencha.webp' => 'image',
            'tea/notes.txt' => 'ignored',
            '__MACOSX/tea/._products.yaml' => 'ignored',
        ]);

        $dataset = DatasetEditor::import('export.zip');

        static::assertSame('tea', $dataset->slug);
        static::assertSame('Tea House', $dataset->name());
        static::assertSame('import', $dataset->configValue('source'));
        static::assertSame([], $dataset->collections());
        static::assertNotNull($dataset->imageFile('media/sencha.webp'));
        static::assertFileDoesNotExist($dataset->path . '/notes.txt');
        static::assertFileDoesNotExist(DatasetEditor::uploadsDir() . '/export.zip');

        // The same slug again needs --replace.
        $this->uploadZip('again.zip', ['products.yaml' => Yaml::dump(['products' => [['title' => 'Matcha']]])]);

        try {
            DatasetEditor::import('again.zip', 'tea');
            static::fail('An existing dataset was replaced.');
        } catch (\RuntimeException $exception) {
            static::assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->uploadZip('again.zip', ['products.yaml' => Yaml::dump(['products' => [['title' => 'Matcha']]])]);
        $replaced = DatasetEditor::import('again.zip', 'tea', replace: true);
        static::assertSame([['title' => 'Matcha']], $replaced->products());
        static::assertFileDoesNotExist($replaced->path . '/media/sencha.webp');
    }

    public function testRefusesAnInvalidZip(): void
    {
        $this->uploadZip('empty.zip', ['readme.md' => 'nothing']);

        try {
            DatasetEditor::import('empty.zip');
            static::fail('A zip without products.yaml was imported.');
        } catch (\RuntimeException $exception) {
            static::assertStringContainsString('no products.yaml', $exception->getMessage());
        }

        $this->uploadZip('wine.zip', ['wine/products.yaml' => Yaml::dump(['products' => []])]);
        $this->expectExceptionMessage('ready-made dataset');
        DatasetEditor::import('wine.zip', replace: true);
    }

    private function uploadImage(string $file): string
    {
        $image = imagecreatetruecolor(4, 4);
        (new Filesystem())->mkdir(DatasetEditor::uploadsDir());
        str_ends_with($file, '.png') ? imagepng($image, DatasetEditor::uploadsDir() . '/' . $file) : imagejpeg($image, DatasetEditor::uploadsDir() . '/' . $file);

        return $file;
    }

    /**
     * @param array<string, string> $files
     */
    private function uploadZip(string $file, array $files): void
    {
        (new Filesystem())->mkdir(DatasetEditor::uploadsDir());
        $zip = new \ZipArchive();
        $zip->open(DatasetEditor::uploadsDir() . '/' . $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();
    }
}
