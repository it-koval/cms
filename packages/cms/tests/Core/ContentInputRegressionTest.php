<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Entity\User;
use Itk\Cms\Field\Select;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\Mcp\ArgumentChecker;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolRegistry;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\Access;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\ContentHeadingTemplate;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Real service writes, with cleanup restricted to the exact objects made here. */
final class ContentInputRegressionTest extends KernelTestCase
{
    /** @var list<string> */
    private array $pageIds = [];
    /** @var list<string> */
    private array $itemIds = [];
    /** @var list<int> */
    private array $requestIds = [];
    private User $user;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: ['schema_file' => __DIR__ . '/Fixture/schema_content_writes.php']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new ContentHeadingTemplate());
        $this->user = new User('r2-content@example.com', 'Content regression', 'admin');
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->user, 'itk_admin', []));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $db = self::getContainer()->get(Connection::class);
        foreach ($this->requestIds as $id) {
            $db->delete('itk_notification', ['type' => 'request.new', 'link' => '/requests/' . $id]);
            $db->delete('itk_request', ['id' => $id]);
        }
        foreach ($this->itemIds as $id) {
            $db->delete('itk_collection_item', ['id' => $id]);
            $db->delete('itk_revision', ['resource_id' => $id]);
        }
        foreach ($this->pageIds as $id) {
            $db->delete('itk_page', ['id' => $id]);
            $db->delete('itk_revision', ['resource_id' => $id]);
        }
        parent::tearDown();
    }

    #[Test]
    public function optionalEmptyFormValuesAreOmittedAndAnEmptyRecordChoiceCanBeSaved(): void
    {
        $requests = self::getContainer()->get(RequestService::class);
        foreach ([[], ['state' => '', 'code' => '']] as $input) {
            $request = $requests->submit('r2_content_form', $input);
            $this->requestIds[] = $request->id;
            self::assertSame([], $request->data);
        }
        $items = self::getContainer()->get(CollectionService::class);
        $row = $items->create('r2_content_rows', ['name' => 'Empty choice', 'state' => '']);
        $this->itemIds[] = $row['id'];
        self::assertSame('', $items->get('r2_content_rows', $row['id'])['item']['state']);
    }

    #[Test]
    public function mcpCanClearAnOptionalChoiceWithoutWeakeningItsTypeCheck(): void
    {
        $field = Select::make('State', 'state')->options(['yes' => 'Yes']);
        $input = self::getContainer()->get(FieldInput::class);
        self::assertSame([], $input->problems([$field], ['state' => '']));
        foreach ([false, [], 0, 'other'] as $value) {
            self::assertNotSame([], $input->problems([$field], ['state' => $value]));
        }
    }

    #[Test]
    public function tableGeneratorRejectsMetadataFieldsAndAcceptsExplicitMapping(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $table = 'r2_content_generator_' . \bin2hex(\random_bytes(6));
        $db->executeStatement('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, status TEXT, locales TEXT, position INTEGER)');
        try {
            $reader = new TableReader();
            try {
                $reader->inspect($db, $table);
                self::fail('Table generator accepted a reserved key');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('status', $error->getMessage());
            }
            $spec = $reader->inspect($db, $table, ['status' => ['state', null], 'locales' => ['languages', null], 'position' => ['sort', null]]);
            self::assertSame(['state', 'languages', 'sort'], \array_column($spec->fields, 'key'));
        } finally {
            $db->executeStatement('DROP TABLE ' . $table);
        }
    }

    #[Test]
    public function explicitFormMaxAppliesToCleanedPlainTextIncludingHeadlineFields(): void
    {
        $requests = self::getContainer()->get(RequestService::class);
        foreach (['12345', '<b>12345</b>', '<123'] as $value) {
            try {
                $request = $requests->submit('r2_content_form', ['heading' => $value]);
                $this->requestIds[] = $request->id;
                self::fail('An oversized headline was silently truncated');
            } catch (ContentException $error) {
                self::assertSame(422, $error->status());
                self::assertArrayHasKey('heading', $error->details());
            }
        }
        $request = $requests->submit('r2_content_form', ['heading' => '<b>123</b>']);
        $this->requestIds[] = $request->id;
        self::assertSame('123', $request->data['heading']);
    }

    #[Test]
    public function h1SynchronizationCannotSaveAnEmptyNameOrBypassTheHeadingMax(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'r2_content_heading', 'title' => 'One', 'url' => '/r2-content-' . \bin2hex(\random_bytes(6))]);
        $this->pageIds[] = $page['id'];
        foreach ([['fields' => ['heading' => '']], ['title' => 'Long']] as $patch) {
            try {
                $pages->update($page['id'], 'ru', $patch);
                self::fail('H1 synchronization bypassed validation');
            } catch (ContentException $error) {
                self::assertSame(422, $error->status());
            }
            $saved = $pages->get($page['id']);
            self::assertSame('One', $saved['title']);
            self::assertSame('One', $saved['fields']['heading']);
        }
        $pages->update($page['id'], 'ru', ['title' => 'Two']);
        self::assertSame('Two', $pages->get($page['id'])['fields']['heading']);
        $pages->update($page['id'], 'ru', ['title' => '', 'fields' => ['heading' => 'New']]);
        self::assertSame('New', $pages->get($page['id'])['title'], 'the changed H1 wins before final validation');
    }

    #[Test]
    public function mcpCreateAndUpdateAcceptTheSameLongNamesAndAddressesAsPhp(): void
    {
        $tools = self::getContainer()->get(McpToolRegistry::class);
        $token = new ApiToken($this->user, 'Content regression', \str_repeat('0', 64), 'test', [McpScopes::READ, McpScopes::WRITE]);
        $context = new McpContext($token, self::getContainer()->get(Access::class));
        $args = ['title' => \str_repeat('я', 100), 'url' => '/r2-content-' . \bin2hex(\random_bytes(6)) . \str_repeat('я', 200), 'sections' => []];
        $create = $tools->get('page_create');
        self::assertSame([], ArgumentChecker::check($create->inputSchema(), $args));
        $result = $create->call($args, $context);
        $this->pageIds[] = $id = $result['page']['id'];
        $update = $tools->get('page_update');
        $args = ['id' => $id, 'title' => \str_repeat('ю', 110), 'url' => '/r2-content-' . \bin2hex(\random_bytes(6)) . \str_repeat('ю', 200)];
        self::assertSame([], ArgumentChecker::check($update->inputSchema(), $args));
        $update->call($args, $context);
        self::assertSame($args['title'], self::getContainer()->get(PageService::class)->get($id)['title']);
        self::assertSame($args['url'], self::getContainer()->get(PageService::class)->get($id)['url']);
        foreach ([$create, $update] as $tool) {
            $args = ['title' => 'Name', 'url' => '/' . \str_repeat('я', 255)];
            if ($tool === $update) {
                $args['id'] = $id;
            }
            $problems = ArgumentChecker::check($tool->inputSchema(), $args);
            self::assertCount(1, $problems);
            self::assertStringContainsString('arguments.url', $problems[0]);
        }
    }
}
