<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaLint;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ReservedSettingsGroupsTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? true, null, '', [], [], ['schema_file' => __DIR__ . '/Fixture/schema_reserved_settings.php']);
    }

    #[Test]
    public function lintNamesEveryReservedGroupAndExplainsHowToRenameIt(): void
    {
        self::bootKernel();
        $errors = \implode("\n", self::getContainer()->get(SchemaLint::class)->run()['errors']);
        foreach (['menus', 'redirects', 'seo', 'notifications', 'mail', 'bots', 'theme', 'languages', 'users', 'mcp', 'seo-files'] as $key) {
            self::assertStringContainsString('"' . $key . '"', $errors);
        }
        self::assertStringContainsString('Rename', $errors);
        self::assertStringContainsString('SettingsResource::key()', $errors);
        self::assertStringContainsString('set:<new-key>', $errors);
    }

    #[Test]
    public function productionSchemaLoadingRejectsReservedGroupsToo(): void
    {
        self::bootKernel(['debug' => false]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"notifications"');
        self::getContainer()->get(Schema::class);
    }
}
