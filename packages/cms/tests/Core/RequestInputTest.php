<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class RequestInputTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], ['schema_file' => __DIR__ . '/Fixture/schema_f1_input.php']);
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("DELETE FROM itk_notification WHERE type = 'request.new' AND link IN (SELECT '/requests/' || id FROM itk_request WHERE form_key = 'f1_input')");
        $db->executeStatement("DELETE FROM itk_request WHERE form_key = 'f1_input'");
        parent::tearDown();
    }

    public static function bodies(): iterable
    {
        foreach ([false, true] as $length) {
            yield 'boundary ' . (int) $length => ['{"text":"ok"}' . \str_repeat(' ', 262144 - 13), $length, 200];
            yield 'over ' . (int) $length => ['{"text":"ok"}' . \str_repeat(' ', 262145 - 13), $length, 413];
        }
        yield 'invalid JSON' => ['{"text":', false, 422];
        yield 'top-level list' => ['["ok"]', false, 422];
        yield 'top-level scalar' => ['"ok"', false, 422];
        yield 'nested optional field' => ['{"text":{"a":"b"}}', false, 422];
        yield 'deep nesting' => ['{"text":' . \str_repeat('[', 40) . '0' . \str_repeat(']', 40) . '}', false, 422];
        yield 'many scalar fields' => [\json_encode(\array_fill_keys(\array_map(static fn ($i) => 'key' . $i, \range(1, 1001)), 'x')), false, 422];
        yield 'many small objects' => ['{"text":[' . \implode(',', \array_fill(0, 10000, '{}')) . ']}', false, 422];
    }

    #[DataProvider('bodies')]
    public function testPublicInputIsBoundedBeforeItCanCreateARequest(string $body, bool $length, int $status): void
    {
        $client = static::createClient();
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($length) {
            $headers['CONTENT_LENGTH'] = (string) \strlen($body);
        }
        $client->request('POST', '/request/f1_input', [], [], $headers, $body);
        self::assertResponseStatusCodeSame($status);
        $response = \json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(200 === $status, $response['success']);
        self::assertSame(200 === $status ? 1 : 0, (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM itk_request WHERE form_key = 'f1_input'"));
    }

    public function testOrdinaryPostIgnoresAnUndeclaredMultipleHelperField(): void
    {
        $client = static::createClient();
        $client->request('POST', '/request/f1_input', ['text' => 'ok', 'helper' => ['one', 'two']]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testOrdinaryPostRejectsAnArrayInADeclaredField(): void
    {
        $client = static::createClient();
        $client->request('POST', '/request/f1_input', ['text' => ['one', 'two']]);
        self::assertResponseStatusCodeSame(422);
    }
}
