<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\Mail\RequestMailCheckpoint;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RequestMailRecoveryWithoutFailureTransportTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, framework: ['messenger' => ['failure_transport' => null, 'transports' => ['async' => 'in-memory://']]]);
    }

    #[Test]
    public function aVerifiedRejectionCanBeQueuedAgainWithoutAFailedEnvelope(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $c->get(Connection::class);
        $request = new Request('call');
        $em->persist($request);
        $em->flush();
        $id = (string) $request->id;
        $recipient = 'confirmed@example.test';
        $key = RequestMailCheckpoint::recipientKey($id, $recipient);
        $db->insert('itk_request_mail_delivery', ['delivery_key' => $key, 'state' => 'unknown']);
        try {
            $app = new Application(self::$kernel);
            $resolve = new CommandTester($app->find('itk:requests:mail:resolve'));
            self::assertSame(0, $resolve->execute(['request' => $id, 'recipient' => $recipient, 'outcome' => 'rejected']));
            self::assertStringContainsString('itk:requests:resend ' . $id . ' --channel=mail --recipient=' . \escapeshellarg($recipient), $resolve->getDisplay());
            self::assertStringNotContainsString('messenger:failed:retry', $resolve->getDisplay());

            $resend = new CommandTester($app->find('itk:requests:resend'));
            self::assertSame(1, $resend->execute(['request' => $id, '--channel' => 'mail', '--recipient' => 'unverified@example.test']));
            self::assertSame(0, $resend->execute(['request' => $id, '--channel' => 'mail', '--recipient' => $recipient]));
            $transport = $c->get('messenger.transport.async');
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            $queued = $transport->getSent();
            self::assertCount(1, $queued);
            $mail = $queued[0]->getMessage();
            self::assertInstanceOf(SendMail::class, $mail);
            self::assertSame([$id, $recipient], [$mail->ref, $mail->to]);
        } finally {
            $db->delete('itk_request_mail_delivery', ['delivery_key' => $key]);
            $db->delete('itk_request', ['id' => $id]);
        }
    }
}
