<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Health\HealthCheckInterface;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\I18n\I18n;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The line «ИИ-ассистенты (MCP)» of «Состояние сайта»: while MCP is on, the address /mcp must be behind a firewall that has ApiTokenAuthenticator. A site whose security.yaml
 * knows only ^/admin would otherwise answer every call with the internal error mcp_firewall_not_configured (McpController).
 *
 * @internal
 */
final class McpFirewallHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly McpSettings $settings,
    ) {
    }

    public function name(): string
    {
        return I18n::t('ops.mcp_firewall_name');
    }

    public function check(): HealthResult
    {
        if (!$this->settings->isEnabled()) {
            return HealthResult::off(I18n::t('ops.mcp_firewall_off'));
        }
        $firewall = $this->security->getFirewallConfig(Request::create(McpGate::PATH, 'POST'));
        if (null === $firewall || !$firewall->isSecurityEnabled() || !\in_array(ApiTokenAuthenticator::class, $firewall->getAuthenticators(), true)) {
            return HealthResult::warn(I18n::t('ops.mcp_firewall_missing'));
        }

        return HealthResult::ok(I18n::t('ops.mcp_firewall_ok', ['name' => $firewall->getName()]));
    }
}
