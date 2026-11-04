<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpConditionalTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Request\RequestService;

/**
 * Requests are the one place where customers' personal data (names, phone numbers) is handed to the model, so these tools need their own scope
 * AND the person's right to «Заявки». Nothing else in this server returns a phone number or an e-mail address of a customer.
 * What a visitor typed is the one text on this server that anybody on the internet can write, so it comes marked as untrusted data (Format::untrusted()).
 * For the same reason a key with this scope cannot also write or publish (McpScopes::conflict()).
 *
 * @internal
 */
abstract class RequestsTool extends AbstractMcpTool implements McpConditionalTool
{
    public function __construct(protected readonly RequestService $requests)
    {
    }

    public function requiredScope(): string
    {
        return McpScopes::REQUESTS;
    }

    public function isAvailableTo(McpContext $context): bool
    {
        return $context->can('requests', 'view');
    }

    /**
     * Everything the visitor typed (and the page he came from) is between <untrusted-data> marks: it is a quotation for the model, never an instruction.
     * The answers of the staff (work status, comment) and the names of the forms are the site's own.
     *
     * @param array<string, mixed> $p RequestService::present()
     *
     * @return array<string, mixed>
     */
    protected static function row(array $p, bool $full = false): array
    {
        $out = [
            'id' => $p['id'], 'receivedAt' => $p['dt'], 'form' => $p['form'], 'formTitle' => $p['place'], 'name' => Format::untrusted($p['name'], 120), 'phone' => Format::untrusted($p['phone'], 40), 'city' => Format::untrusted($p['city'], 120),
            'status' => 'progress' === $p['work'] ? 'in_work' : $p['work'], 'delivered' => 'failed' !== $p['st'], 'message' => Format::untrusted($p['note'], $full ? 2000 : 200), 'comment' => Format::text($p['comment'], 500),
        ];
        if ($full) {
            $out['data'] = Format::untrustedTree($p['data']);
            $out['sourceUrl'] = Format::untrusted($p['sourceUrl'], 300);
            $out['locale'] = $p['locale'];
        }

        return $out;
    }
}
