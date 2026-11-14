<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\HealthResult;
use Itk\Cms\Mcp\McpFirewallHealthCheck;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** The line «ИИ-ассистенты (MCP)» of «Состояние сайта»: MCP is on, but is there a firewall for /mcp that checks the keys? */
final class McpFirewallHealthTest extends KernelTestCase
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

    /** A security layer whose firewall map answers with $config for every request. */
    private function check(?FirewallConfig $config): McpFirewallHealthCheck
    {
        $map = new class($config) {
            public function __construct(private readonly ?FirewallConfig $config)
            {
            }

            public function getFirewallConfig(object $request): ?FirewallConfig
            {
                return $this->config;
            }
        };

        return new McpFirewallHealthCheck(new Security(new ServiceLocator(['security.firewall.map' => static fn (): object => $map])), self::getContainer()->get(McpSettings::class));
    }

    #[Test]
    public function theFirewallOfTheTestSiteIsFound(): void
    {
        $r = self::getContainer()->get(McpFirewallHealthCheck::class)->check();
        self::assertSame(HealthResult::OK, $r->status, $r->message);
        self::assertStringContainsString('itk_mcp', $r->message);
    }

    #[Test]
    public function aSiteWithoutTheFirewallOrWithoutTheAuthenticatorGetsAWarningWithTheHintWhileMcpIsOn(): void
    {
        foreach ([null, new FirewallConfig('main', 'user_checker', null, true, false, null, null, null, null, null, ['form_login']), new FirewallConfig('itk_admin', 'user_checker', null, false)] as $config) {
            $r = $this->check($config)->check();
            self::assertSame(HealthResult::WARN, $r->status);
            self::assertStringContainsString('security.yaml', $r->message);
            self::assertStringContainsString('ApiTokenAuthenticator', $r->message);
        }
        $ok = $this->check(new FirewallConfig('anything', 'user_checker', null, true, true, null, null, null, null, null, ['Itk\Cms\Mcp\ApiTokenAuthenticator']))->check();
        self::assertSame(HealthResult::OK, $ok->status, 'the name of the firewall is the site\'s choice: the authenticator counts');
    }

    #[Test]
    public function whileMcpIsOffThereIsNothingToWarnAbout(): void
    {
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        $r = $this->check(null)->check();
        self::assertSame(HealthResult::OFF, $r->status);
        self::assertStringContainsString('Выключены', $r->message);
    }
}
