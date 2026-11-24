<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class RequestFormRateLimitTest extends WebTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, limitForms: true);
    }

    #[Test]
    public function thePublicFormLimitsOneAddressWithoutBlockingOthers(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();

        try {
            $place = 'test-rate-limit-' . \bin2hex(\random_bytes(8));
            $network = '2001:db8:' . \bin2hex(\random_bytes(2)) . ':' . \bin2hex(\random_bytes(2)) . '::';
            $fields = ['phone' => '+7(900)000-00-11', 'place' => $place];
            for ($i = 0; $i < 10; ++$i) {
                $client->request('POST', '/request/call', $fields, server: ['REMOTE_ADDR' => $network . '1']);
                self::assertResponseIsSuccessful();
            }

            $client->request('POST', '/request/call', $fields, server: ['REMOTE_ADDR' => $network . '1']);
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            $body = \json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($body);
            self::assertFalse($body['success']);
            self::assertNotEmpty($body['message']);

            $client->request('POST', '/request/call', $fields, server: ['REMOTE_ADDR' => $network . '2']);
            self::assertResponseIsSuccessful();
            self::assertSame(11, (int) $connection->fetchOne("SELECT COUNT(*) FROM itk_request WHERE data ->> 'place' = ?", [$place]));
        } finally {
            $connection->rollBack();
        }
    }
}
