<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit\Fetch;

use PHPUnit\Framework\TestCase;

use function OpenColibri\Starter\DatasetBuilder\Fetch\prepare_html_for_ai_collections;

final class PrepareHtmlForAiCollectionsTest extends TestCase
{
    public function testPrepareHtmlStripsScriptsFromNavSnippetForAi(): void
    {
        $html = <<<'HTML'
            <html><body>
            <nav>
              <a href="/collections/bikes">Bikes</a>
              <script>alert(1)</script>
            </nav>
            </body></html>
            HTML;

        $prepared = prepare_html_for_ai_collections($html);

        static::assertStringContainsString('Bikes', $prepared);
        static::assertStringNotContainsString('alert(1)', $prepared);
    }
}
