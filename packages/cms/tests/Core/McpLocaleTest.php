<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Field\Text;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Several languages share one MCP context and one set of services, as in a stdio session. */
final class McpLocaleTest extends KernelTestCase
{
    use McpSupport;

    private const RESOURCE = 'test_mcp_locale';
    private const SETTING = 'testMcpLocaleTitle';

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mcpBoot();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :key')->setParameter('key', self::RESOURCE)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\MenuArea m WHERE m.key = :key')->setParameter('key', self::RESOURCE)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :key')->setParameter('key', self::SETTING)->execute();
        $this->mcpClean();
        parent::tearDown();
    }

    #[Test]
    public function oneSessionReadsEachLanguageAndLoadsEachResourceOncePerLanguage(): void
    {
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $template = new class extends TemplateResource {
            /** @var list<string> */
            public array $loads = [];

            public function key(): string
            {
                return 'test_mcp_locale';
            }

            public function title(): string
            {
                return 'MCP locale page';
            }

            public function fields(): iterable
            {
                return [];
            }

            public function onLoad(Context $ctx): void
            {
                $this->loads[] = $ctx->locale;
            }
        };
        $collection = new class extends CollectionResource {
            /** @var list<string> */
            public array $loads = [];

            public function key(): string
            {
                return 'test_mcp_locale';
            }

            public function title(): string
            {
                return 'MCP locale records';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title')->required();
            }

            public function onLoad(Context $ctx): void
            {
                $this->loads[] = $ctx->locale;
            }
        };
        $registry->add($template);
        $registry->add($collection);
        $registry->add(new class extends SettingsResource {
            public function key(): string
            {
                return 'test_mcp_locale';
            }

            public function title(): string
            {
                return 'MCP locale settings';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'testMcpLocaleTitle')->translatable();
            }
        });

        $user = $this->mcpUser();
        $ctx = $this->mcpContext($this->mcpKey($user, [McpScopes::READ])['token']);
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['title' => 'TestMcp RU page', 'url' => '/test-mcp-locale-' . \bin2hex(\random_bytes(5)), 'template' => self::RESOURCE]);
        $this->mcpPageIds[] = $page['id'];
        $pages->addTranslation($page['id'], 'kk', 'ru');
        $pages->saveDraft($page['id'], 'kk', ['title' => 'TestMcp KK page']);
        $collections = self::getContainer()->get(CollectionService::class);
        $item = $collections->create(self::RESOURCE, ['title' => 'RU record']);
        $collections->addTranslation(self::RESOURCE, $item['id'], 'kk', 'ru');
        $collections->update(self::RESOURCE, $item['id'], ['title' => 'KK record'], 'kk');

        $menu = new MenuArea(self::RESOURCE, 'MCP locale menu');
        foreach (['ru' => 'RU', 'kk' => 'KK'] as $locale => $label) {
            $menu->items[$locale] = [['label' => $label . ' menu', 'link' => ['kind' => 'url', 'url' => '/' . $locale . '/menu']]];
            self::getContainer()->get(SettingsService::class)->set(self::SETTING, $label . ' setting', $locale);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($menu);
        $em->flush();

        // Alternate languages repeatedly without rebooting the kernel or signing in again.
        foreach (['ru' => 'RU', 'kk' => 'KK', 'ru-again' => 'RU', 'kk-again' => 'KK', 'default' => 'RU'] as $turn => $label) {
            $locale = 'default' === $turn ? [] : ['locale' => \substr($turn, 0, 2)];
            $readPage = $this->read($ctx, 'page_get', ['id' => $page['id']] + $locale);
            self::assertSame($page['id'], $readPage['id']);
            self::assertSame('TestMcp ' . $label . ' page', $readPage['title']);
            self::assertSame('KK' === $label ? 'kk' : 'ru', $readPage['locale']);
            $list = $this->read($ctx, 'collection_list', ['collection' => self::RESOURCE] + $locale);
            self::assertSame(1, $list['total']);
            self::assertSame($item['id'], $list['items'][0]['id']);
            self::assertSame($label . ' record', $list['items'][0]['title']);
            $menus = $this->read($ctx, 'menus_get', ['area' => self::RESOURCE] + $locale);
            self::assertSame($label . ' menu', $menus['areas'][0]['items'][0]['label']);
            $settings = $this->read($ctx, 'settings_get', ['group' => self::RESOURCE] + $locale);
            self::assertSame($label . ' setting', $settings['groups'][0]['settings'][self::SETTING]['value']);
        }
        self::assertSame(['ru', 'kk'], $template->loads, 'onLoad runs twice total: once for each page language');
        self::assertSame(['ru', 'kk'], $collection->loads, 'kind and locale both take part in the dedup key');

        foreach (['page_get' => ['id' => $page['id']], 'collection_list' => ['collection' => self::RESOURCE], 'menus_get' => ['area' => self::RESOURCE], 'settings_get' => ['group' => self::RESOURCE]] as $tool => $args) {
            foreach (['zz', 'KK', 'en-US'] as $unknown) {
                $error = $this->mcpCall($ctx, $tool, $args + ['locale' => $unknown]);
                self::assertFalse($error['ok'], $tool . ': ' . $unknown);
                self::assertNull($error['rpc'], 'an unknown locale is a normal tool argument error');
                self::assertStringContainsString('locale', $error['text']);
            }
        }
        self::assertSame(['ru', 'kk'], $template->loads, 'invalid arguments do not load another language');
        self::assertSame(['ru', 'kk'], $collection->loads);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function read(McpContext $context, string $tool, array $arguments): array
    {
        $reply = $this->mcpCall($context, $tool, $arguments);
        self::assertTrue($reply['ok'], $reply['text']);

        return $reply['data'];
    }
}
