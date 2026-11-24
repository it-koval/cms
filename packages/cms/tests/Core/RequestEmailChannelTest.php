<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Health\HealthService;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Mail\MailDeliveryException;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SendMailHandler;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\MailSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/** Письма о новых заявках: адреса в «Уведомлениях», канал, письмо, Telegram как раньше, ошибки доставки в колокольчике и на экране состояния. */
final class RequestEmailChannelTest extends KernelTestCase
{
    use MailSupport;

    /** @var list<int> */
    private array $requestIds = [];
    private bool $domainWasSet = false;
    private mixed $domainBefore = null;
    private int $failedQueueMark = 0;

    /**
     * The site has a connected Telegram (nothing here really sends: the bus only records): only then a request queues its delivery, see TelegramChannelTest.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', ['notifier' => ['chatter_transports' => ['telegram' => 'telegram://123456:test-token@default']]]);
    }

    protected function setUp(): void
    {
        $this->mailerEnv();
        self::bootKernel();
        // the link to the request in a letter is whole only when the site knows its address
        $settings = self::getContainer()->get(SettingsService::class);
        $this->domainWasSet = $settings->isSet('domain');
        $this->domainBefore = $settings->get('domain', null, '');
        $settings->set('domain', 'site.example.com');
        // a letter that failed for good goes to the failure transport (a table of the database): what this test adds is removed after it
        $this->failedQueueMark = (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COALESCE(MAX(id), 0) FROM messenger_messages WHERE queue_name = 'failed'");
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        if ([] !== $this->requestIds) {
            $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.type IN (:t) AND n.dedupeKey LIKE :k')->setParameter('t', ['mail.failed'])->setParameter('k', 'mail.failed:%')->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.type = :t')->setParameter('t', 'request.new')->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id IN (:ids)')->setParameter('ids', $this->requestIds)->execute();
        }
        $this->wipeMailSettings();
        self::getContainer()->get(Connection::class)->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed' AND id > ?", [$this->failedQueueMark]);
        $settings = self::getContainer()->get(SettingsService::class);
        if ($this->domainWasSet) {
            $settings->set('domain', $this->domainBefore);
        } else {
            $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
        }
        foreach (['test-req-a@example.com', 'test-req-b@example.com', 'test-req-c@example.com'] as $to) {
            $this->mailpitWipe($to);
        }
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    /** @param array<array-key, mixed> $routes */
    private function routes(array $routes): void
    {
        self::getContainer()->get(SettingsService::class)->update(['mailRoutes' => $routes]);
    }

    /** @param array<string, mixed> $data */
    private function submit(string $form = 'call', array $data = []): Request
    {
        $input = $data + ('call' === $form ? ['phone' => '+7(900)000-11-22', 'city' => 'Москва'] : ['phone' => '+7(900)000-11-22', 'name' => 'Иван', 'city' => 'Казань']);
        $r = self::getContainer()->get(RequestService::class)->submit($form, $input);
        $this->requestIds[] = (int) $r->id;

        return $r;
    }

    /** @return list<object> */
    private function sentOfAll(): array
    {
        return \array_map(static fn (Envelope $e): object => $e->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent());
    }

    #[Test]
    public function theAddressesAreCheckedCleanedAndKeptPerForm(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);

        $settings->update(['mailRoutes' => ['*' => " A@Example.com, b@example.com;\n a@example.com ", 'call' => 'c@example.com', 'maintenance' => '  ']]);

        self::assertSame(['*' => 'a@example.com, b@example.com', 'call' => 'c@example.com'], $settings->get('mailRoutes'));
        self::assertSame(['c@example.com'], $settings->mailRecipientsFor('call'));
        self::assertSame(['a@example.com', 'b@example.com'], $settings->mailRecipientsFor('maintenance'), 'a form without its own list uses the common one');
        self::assertTrue($settings->hasMailRecipients());
        foreach ([['*' => 'not-an-email'], ['*' => 'a@example.com, broken@'], ['*' => \implode(',', \array_map(static fn (int $i): string => "u$i@example.com", \range(1, 11)))]] as $bad) {
            try {
                $settings->update(['mailRoutes' => $bad]);
                self::fail('a wrong list is refused');
            } catch (ContentException $e) {
                self::assertArrayHasKey('mailRoutes', $e->details());
            }
        }
        self::assertSame(['*' => 'a@example.com, b@example.com', 'call' => 'c@example.com'], $settings->get('mailRoutes'), 'a refused save changes nothing');
        $settings->update(['mailRoutes' => []]);
        self::assertFalse($settings->hasMailRecipients());
    }

    #[Test]
    public function aNewRequestQueuesOneLetterPerAddressAndTelegramGoesAsBefore(): void
    {
        $this->useMailpit();
        $this->routes(['*' => 'test-req-a@example.com, test-req-b@example.com']);

        $r = $this->submit('call', ['place' => 'Шапка']);

        $mail = \array_values(\array_filter($this->sentOfAll(), static fn (object $m): bool => $m instanceof SendMail));
        self::assertSame(['test-req-a@example.com', 'test-req-b@example.com'], \array_map(static fn (SendMail $m): string => $m->to, $mail));
        self::assertSame([SendMail::REQUEST, (string) $r->id], [$mail[0]->kind, $mail[0]->ref]);
        self::assertSame('Новая заявка №' . $r->id . ' — Заявка на звонок', $mail[0]->subject);
        self::assertStringContainsString('Телефон: +7(900)000-11-22', $mail[0]->text);
        self::assertStringContainsString('Город: Москва', $mail[0]->text);
        self::assertStringContainsString('Откуда отправлено: Шапка', $mail[0]->text);
        self::assertMatchesRegularExpression('~Открыть заявку в панели: https?://[^\s]+/admin#/requests/' . $r->id . '\b~', $mail[0]->text);
        self::assertStringContainsString('/admin#/requests/' . $r->id, $mail[0]->html);
        $telegram = \array_values(\array_filter($this->sentOfAll(), static fn (object $m): bool => $m instanceof SendRequestNotification));
        self::assertCount(1, $telegram, 'Telegram is queued exactly as before');
        self::assertSame($r->id, $telegram[0]->requestId);
    }

    #[Test]
    public function aFormWithItsOwnListWritesOnlyToIt(): void
    {
        $this->useMailpit();
        $this->routes(['*' => 'test-req-a@example.com', 'maintenance' => 'test-req-c@example.com']);

        $this->submit('call');
        $this->submit('maintenance');

        $to = \array_map(static fn (SendMail $m): string => $m->to, $this->queuedMail());
        self::assertSame(['test-req-a@example.com', 'test-req-c@example.com'], $to);
    }

    #[Test]
    public function nothingIsSentWithoutAddressesOrWithoutConfiguredMail(): void
    {
        $this->useMailpit();
        $this->routes([]);
        $this->submit('call');
        self::assertSame([], $this->queuedMail(), 'no addresses');

        $this->routes(['*' => 'test-req-a@example.com']);
        self::getContainer()->get(MailSettings::class)->save(['enabled' => false]);
        $this->clearQueue();
        $this->submit('call');
        self::assertSame([], $this->queuedMail(), 'mail is not configured: no letters, no error for the visitor');
        self::assertCount(1, \array_filter($this->sentOfAll(), static fn (object $m): bool => $m instanceof SendRequestNotification), 'Telegram does not care');
    }

    #[Test]
    public function theTextOfAVisitorCannotBreakTheLetter(): void
    {
        $this->useMailpit();
        $this->routes(['*' => 'test-req-a@example.com']);

        $this->submit('maintenance', ['name' => 'Иван & "Пётр" <b>x</b>', 'comment' => "первая строка\nвторая строка"]);

        $mail = $this->queuedMail()[0];
        self::assertStringContainsString('Имя и фамилия: Иван & "Пётр" x', $mail->text, 'plain text stays as the visitor wrote it (tags are cut when a request is accepted)');
        self::assertStringContainsString('Иван &amp; &quot;Пётр&quot; x', $mail->html, 'HTML is escaped');
        self::assertStringNotContainsString('<b>', $mail->html);
    }

    #[Test]
    public function theLetterReachesTheMailServerWithBothPartsOfTheMessage(): void
    {
        $this->useMailpit();
        $this->routes(['*' => 'test-req-a@example.com']);
        $this->mailpitWipe('test-req-a@example.com');
        $r = $this->submit('call');

        self::getContainer()->get(SendMailHandler::class)($this->queuedMail()[0]);

        $found = $this->mailpitFor('test-req-a@example.com');
        self::assertCount(1, $found);
        $message = $this->mailpitMessage($found[0]['ID']);
        self::assertSame('Новая заявка №' . $r->id . ' — Заявка на звонок', $message['Subject']);
        self::assertStringContainsString('Москва', $message['Text']);
        self::assertStringContainsString('Москва', $message['HTML']);
        self::assertSame('test-site@example.com', $message['From']['Address']);
    }

    #[Test]
    public function aLetterThatFailedForGoodRingsTheBellOnceAndOnlyAfterTheRetriesAreOver(): void
    {
        $this->useMailpit();
        $this->routes(['*' => 'test-req-a@example.com']);
        $r = $this->submit('call');
        $mail = $this->queuedMail()[0];
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $failure = new HandlerFailedException(new Envelope($mail), [new MailDeliveryException('Сервер отказал: пароль не подошёл')]);
        // the retry strategy of the queue decides: a first failure goes back for another try, the sixth is the last (max_retries: 5)
        $exhausted = new Envelope($mail, [new RedeliveryStamp(5)]);

        $events->dispatch(new WorkerMessageFailedEvent(new Envelope($mail), 'async', $failure));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(StoredNotification::class)->count(['type' => 'mail.failed']), 'a retry is not a failure yet');

        $events->dispatch(new WorkerMessageFailedEvent($exhausted, 'async', $failure));
        $events->dispatch(new WorkerMessageFailedEvent($exhausted, 'async', $failure));

        $rows = $em->getRepository(StoredNotification::class)->findBy(['type' => 'mail.failed']);
        self::assertCount(1, $rows, 'one notification per request');
        self::assertSame(['Письмо о заявке №' . $r->id . ' не дошло', '/requests/' . $r->id, 'error', 'requests:edit'], [$rows[0]->title, $rows[0]->link, $rows[0]->level, $rows[0]->audience]);
        self::assertStringContainsString('Сервер отказал', $rows[0]->body);
        // delivered later: the warning is taken back
        self::getContainer()->get(SendMailHandler::class)($mail);
        $em->clear();
        self::assertSame(0, $em->getRepository(StoredNotification::class)->count(['type' => 'mail.failed']));
    }

    #[Test]
    public function aFailureOfSendingIsOnTheHealthScreenWithoutAnyPassword(): void
    {
        $this->useMailpit(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'starttls', 'username' => 'robot', 'password' => 'S3cr3t!pa$$word']);
        $this->routes(['*' => 'test-req-a@example.com']);
        $this->submit('call');

        try {
            self::getContainer()->get(SendMailHandler::class)($this->queuedMail()[0]);
            self::fail('the server is not there');
        } catch (MailDeliveryException $e) {
            self::assertStringNotContainsString('S3cr3t', $e->getMessage());
            self::assertNull($e->getPrevious(), 'no exception with a secret travels on');
        }

        $report = self::getContainer()->get(HealthService::class)->report();
        $line = \array_values(\array_filter($report['checks'], static fn (array $c): bool => 'Почта' === $c['name']))[0];
        self::assertSame('warn', $line['status']);
        self::assertStringContainsString('Последнее письмо не отправилось', $line['message']);
        self::assertStringNotContainsString('S3cr3t', \json_encode($report, \JSON_UNESCAPED_UNICODE));
        self::assertNotSame('ok', $report['status']);
        // a good letter makes the line green again
        $this->useMailpit();
        self::getContainer()->get(SiteMailer::class)->deliver(new SendMail('test-req-a@example.com', 'Проверка', 'Текст', '<p>Текст</p>', SendMail::TEST));
        $line = \array_values(\array_filter(self::getContainer()->get(HealthService::class)->report()['checks'], static fn (array $c): bool => 'Почта' === $c['name']))[0];
        self::assertSame('ok', $line['status']);
    }

    #[Test]
    public function theHealthLineWarnsWhenAddressesAreSetButMailIsNot(): void
    {
        $line = static fn (): array => \array_values(\array_filter(self::getContainer()->get(HealthService::class)->report()['checks'], static fn (array $c): bool => 'Почта' === $c['name']))[0];

        self::assertSame('off', $line()['status']);
        self::assertStringContainsString('не настроена', $line()['message']);
        $this->routes(['*' => 'test-req-a@example.com']);
        self::assertSame('warn', $line()['status']);
    }

    #[Test]
    public function aLetterInTheFailedQueueIsNamedWithoutTheFullAddress(): void
    {
        $label = new \ReflectionMethod(MessengerMonitor::class, 'label');
        $text = $label->invoke(null, new SendMail('ivan.petrov@example.com', 'Тема', 'т', 'т', SendMail::REQUEST, '15'));

        self::assertStringContainsString('Письмо о заявке № 15', $text);
        self::assertStringNotContainsString('ivan.petrov', $text);
        self::assertStringContainsString('i***@example.com', $text);
        self::assertStringContainsString('Письмо со ссылкой', $label->invoke(null, new SendMail('a@example.com', 'Тема', 'т', 'т', SendMail::RESET)));
    }

    #[Test]
    public function aSiteChangesALetterWithAFileOfItsOwnInTemplatesEmail(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir') . '/templates/email';
        $made = !\is_dir($dir);
        @\mkdir($dir, 0775, true);
        $file = $dir . '/test.txt.twig';
        \file_put_contents($file, 'Своё письмо для {{ site_name }}: проверка');
        try {
            self::ensureKernelShutdown();
            self::bootKernel();
            $mail = self::getContainer()->get(\Itk\Cms\Mail\MailComposer::class)->test('test-req-a@example.com');

            self::assertStringStartsWith('Своё письмо для ', \trim($mail->text), 'the text of the site wins');
            self::assertStringContainsString('Почта работает', $mail->html, 'the HTML letter is still the bundle\'s');
        } finally {
            @\unlink($file);
            if ($made) {
                @\rmdir($dir);
            }
            self::ensureKernelShutdown();
            self::bootKernel();
        }
    }
}
