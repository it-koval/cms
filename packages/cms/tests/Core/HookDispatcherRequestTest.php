<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\Itk\Collection\RequestLoadsCollection;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** A persistent HTTP kernel must load each resource again for the next request. */
final class HookDispatcherRequestTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test_hook_request', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша']], // i18n-ignore: language names
        ]);
    }

    #[Test]
    public function consecutiveMcpRequestsLoadAgainButEachBatchDeduplicatesByLanguage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . \random_int(1, 250));
        $db = self::getContainer()->get(Connection::class);
        $before = $db->fetchAssociative('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [McpSettings::KEY, '*']);
        $email = 'test-request-loads-' . \bin2hex(\random_bytes(6)) . '@example.com';
        RequestLoadsCollection::$loads = [];
        try {
            self::getContainer()->get(McpSettings::class)->setEnabled(true);
            $user = self::getContainer()->get(UserManager::class)->create($email, 'Request loads', 'admin', 'test-request-loads-password');
            $key = self::getContainer()->get(ApiTokenService::class)->create($user, 'Request loads', [McpScopes::READ], 1);
            $batch = [];
            foreach (['ru', 'ru', 'kk', 'kk'] as $index => $locale) {
                $batch[] = ['jsonrpc' => '2.0', 'id' => $index + 1, 'method' => 'tools/call', 'params' => [
                    'name' => 'collection_list', 'arguments' => ['collection' => 'test_request_loads', 'locale' => $locale],
                ]];
            }
            for ($request = 1; $request <= 2; ++$request) {
                $client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key['plain']], (string) \json_encode($batch));
                $text = (string) $client->getResponse()->getContent();
                self::assertSame(200, $client->getResponse()->getStatusCode(), $text);
                $replies = \json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
                self::assertCount(4, $replies);
                foreach ($replies as $reply) {
                    self::assertFalse($reply['result']['isError'], $text);
                }
                self::assertSame(\array_merge(...\array_fill(0, $request, ['ru', 'kk'])), RequestLoadsCollection::$loads);
            }
        } finally {
            $db = self::getContainer()->get(Connection::class);
            $db->executeStatement('DELETE FROM itk_user WHERE email = ?', [$email]); // keys cascade with their owner
            if (false === $before) {
                $db->executeStatement('DELETE FROM itk_setting WHERE setting_key = ? AND locale = ?', [McpSettings::KEY, '*']);
            } else {
                $db->executeStatement('UPDATE itk_setting SET setting_value = ? WHERE setting_key = ? AND locale = ?', [$before['setting_value'], McpSettings::KEY, '*']);
            }
            RequestLoadsCollection::$loads = [];
        }
    }
}
