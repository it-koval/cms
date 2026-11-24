<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolRegistry;
use Itk\Cms\Tests\App\RecordingLogger;
use Itk\Cms\Tests\Core\Fixture\EchoMcpTool;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class McpBoundaryRegressionTest extends KernelTestCase
{
    use McpSupport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mcpBoot();
    }

    protected function tearDown(): void
    {
        $this->mcpClean();
        parent::tearDown();
    }

    #[Test]
    public function theTokenServiceRejectsAnUnknownScopeBeforeSaving(): void
    {
        $user = $this->mcpUser();
        $tokens = self::getContainer()->get(ApiTokenService::class);
        try {
            $tokens->create($user, 'F2 unknown scope', [McpScopes::READ, 'f2_unknown'], 1);
            self::fail('an unknown scope must be rejected instead of silently dropped');
        } catch (ContentException $error) {
            self::assertSame(422, $error->status());
            self::assertArrayHasKey('scopes', $error->details());
        }
    }

    #[Test]
    public function anAlreadyRegisteredServiceIsNotConstructedAgainForItsExtensionEntry(): void
    {
        $extensions = new Registry();
        $extensions->mcpTools(F2BrokenMcpTool::class);
        $logger = new RecordingLogger();
        $registry = new McpToolRegistry([new F2BrokenMcpTool(true)], $extensions, null, $logger);
        self::assertSame(['f2_broken'], \array_keys($registry->all()));
        self::assertSame([], $logger->records, 'a service with dependencies is a valid tool');
    }

    #[Test]
    public function anUnconstructibleToolIsLoggedAndItsNeighbourRemainsAvailable(): void
    {
        $extensions = new Registry();
        $extensions->mcpTools(F2BrokenMcpTool::class, EchoMcpTool::class);
        $logger = new RecordingLogger();
        $registry = new McpToolRegistry([], $extensions, null, $logger);
        self::assertSame(['test_echo'], \array_keys($registry->all()));
        self::assertCount(1, $logger->records);
        self::assertSame(F2BrokenMcpTool::class, $logger->records[0]['context']['tool']);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertSame('error', $logger->records[0]['level']);
        $registry->all();
        self::assertCount(1, $logger->records, 'cached diagnostics do not repeat on every lookup');
    }
}

final class F2BrokenMcpTool extends AbstractMcpTool
{
    public function __construct(bool $ready = false)
    {
        if ($ready) {
            return;
        }
        throw new \RuntimeException('F2 fixture constructor failed');
    }

    public function name(): string
    {
        return 'f2_broken';
    }

    public function description(): string
    {
        return 'F2 fixture';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false];
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        return [];
    }
}
