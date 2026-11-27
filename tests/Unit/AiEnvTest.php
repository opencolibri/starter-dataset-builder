<?php

declare(strict_types=1);

namespace OpenColibri\Starter\DatasetBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function OpenColibri\Starter\DatasetBuilder\ai_image_model;
use function OpenColibri\Starter\DatasetBuilder\ai_model;
use function OpenColibri\Starter\DatasetBuilder\ai_provider;
use function OpenColibri\Starter\DatasetBuilder\load_ai_env;
use function OpenColibri\Starter\DatasetBuilder\validate_ai_config;

final class AiEnvTest extends TestCase
{
    private const VARIABLES = ['AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY', 'SYMFONY_DOTENV_VARS'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ai-env-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.castor', 0o775, true);
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        (new Filesystem())->remove($this->root);
    }

    public function testTheAiSettingsComeFromTheCastorDirectory(): void
    {
        file_put_contents($this->root . '/.castor/.env', "AI_PROVIDER=ollama\nAI_MODEL=qwen2.5:latest\n");

        load_ai_env($this->root . '/.castor', $this->root);

        static::assertSame('ollama', ai_provider());
        static::assertSame('qwen2.5:latest', ai_model());
    }

    public function testTheLocalFilesWin(): void
    {
        file_put_contents($this->root . '/.castor/.env', "AI_PROVIDER=ollama\nAI_MODEL=qwen2.5:latest\n");
        file_put_contents($this->root . '/.castor/.env.local', "AI_MODEL=llama3.1:latest\n");

        load_ai_env($this->root . '/.castor', $this->root);

        static::assertSame('ollama', ai_provider());
        static::assertSame('llama3.1:latest', ai_model());
    }

    public function testMistralNeedsAnApiKeyAndHasADefaultModel(): void
    {
        file_put_contents($this->root . '/.castor/.env', "AI_PROVIDER=mistral\n");

        load_ai_env($this->root . '/.castor', $this->root);

        static::assertSame('mistral', ai_provider());
        static::assertSame('mistral-small-latest', ai_model());
        static::assertSame('mistral-medium-latest', ai_image_model());

        $this->expectException(\RuntimeException::class);
        validate_ai_config();
    }

    private function clear(): void
    {
        foreach (self::VARIABLES as $variable) {
            unset($_ENV[$variable], $_SERVER[$variable]);
            putenv($variable);
        }
    }
}
