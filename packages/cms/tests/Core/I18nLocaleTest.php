<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\MailDeliveryException;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationInbox;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\SendRequestNotificationHandler;
use Itk\Cms\Security\RequestPasswordLink;
use Itk\Cms\Security\RoleLabel;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\MailSupport;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Translation\LocaleSwitcher;

/** Which language a person gets, on the test site (admin_locale: ru, framework.default_locale: en, so a language left unset shows). */
final class I18nLocaleTest extends WebTestCase
{
    use MailSupport;

    private KernelBrowser $client;
    private string $csrf = '';
    /** The language of the moment the answer was made: nothing can be read about it after the request. */
    private ?string $spoke = null;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, framework: ['default_locale' => 'en']);
    }

    protected function setUp(): void
    {
        $this->mailerEnv();
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event): void {
            if ($event->isMainRequest()) {
                $this->spoke = I18n::locale();
            }
        });
    }

    protected function tearDown(): void
    {
        $this->wipeMailSettings();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.type LIKE :t')->setParameter('t', 'test.%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-i18n-%@example.com')->execute();
        $em->createQuery('UPDATE Itk\Cms\Entity\Role r SET r.name = :n WHERE r.key = :k')->setParameter('n', 'Редактор')->setParameter('k', Role::EDITOR)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :k')->setParameter('k', 'test-i18n')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key LIKE :k')->setParameter('k', 'test-i18n-%')->execute();
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    private function person(string $role = Role::ADMIN, ?string $locale = null): User
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-i18n-' . \bin2hex(\random_bytes(4)) . '@example.com', 'Test', $role, 'password-for-test');
        $user->locale = $locale;
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    /** @return array<string, mixed> window.ITK_ADMIN of the shell */
    private function signIn(User $user): array
    {
        $this->client->loginUser($user, 'itk_admin');

        return $this->shell();
    }

    /** @return array<string, mixed> */
    private function shell(): array
    {
        $this->client->request('GET', '/admin');
        \preg_match('/window\.ITK_ADMIN = (\{.*\});<\/script>/', (string) $this->client->getResponse()->getContent(), $m);
        $config = (array) \json_decode($m[1] ?? '{}', true);
        $this->csrf = (string) ($config['csrf'] ?? '');

        return $config;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, ?array $body = null, int $status = 200, bool $withToken = true): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($withToken ? ['HTTP_X_CSRF_TOKEN' => $this->csrf] : []);
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, null === $body ? null : (string) \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function aPersonWithoutAChoiceGetsTheLanguageOfTheSite(): void
    {
        $config = $this->signIn($this->person());

        self::assertSame('ru', $config['locale']);
        self::assertSame([['code' => 'en', 'name' => 'English'], ['code' => 'ru', 'name' => 'Русский'], ['code' => 'kk', 'name' => 'Қазақша']], $config['locales']);
        self::assertSame('ru', $config['user']['locale']);
        self::assertNull($config['user']['localePreference']);
        self::assertStringContainsString('<html lang="ru">', (string) $this->client->getResponse()->getContent());
        self::assertSame('ru', $this->spoke);
    }

    #[Test]
    public function theChoiceOfAPersonReachesTheShellAndTheErrorsOfTheApi(): void
    {
        $this->signIn($this->person());
        self::assertStringContainsString('Такого языка в панели нет', (string) $this->api('PUT', 'me/locale', ['locale' => 'de'], 422)['error']);

        self::assertSame(['ok' => true, 'locale' => 'kk', 'localePreference' => 'kk'], $this->api('PUT', 'me/locale', ['locale' => 'kk']));
        self::assertSame('Әкімші панелінде мұндай тіл жоқ. Қолжетімді тілдер: en, ru, kk', $this->api('PUT', 'me/locale', ['locale' => 'de'], 422)['error']);
        self::assertSame('kk', $this->spoke);
        $me = $this->api('GET', 'me');
        self::assertSame(['kk', 'kk'], [$me['locale'], $me['localePreference']]);
        self::assertSame('kk', $this->shell()['locale']);
        self::assertStringContainsString('<html lang="kk">', (string) $this->client->getResponse()->getContent());

        $this->api('PUT', 'me/locale', ['locale' => 'en']);
        self::assertSame('Choose a language from the list', $this->api('PUT', 'me/locale', ['locale' => 5], 422)['details']['locale']);

        self::assertSame(['ok' => true, 'locale' => 'ru', 'localePreference' => null], $this->api('PUT', 'me/locale', ['locale' => null]));
        self::assertSame('Выберите язык из списка', $this->api('PUT', 'me/locale', [], 422)['details']['locale']);
    }

    #[Test]
    public function theTextsOfTheCoreSchemaFollowThePersonNotTheMomentTheSchemaWasBuilt(): void
    {
        $this->signIn($this->person(Role::ADMIN, 'kk'));
        $kk = $this->api('GET', 'schema');
        $this->api('PUT', 'me/locale', ['locale' => 'en']);
        $en = $this->api('GET', 'schema');

        $names = static fn (array $schema): array => \array_column($schema['templates'], 'name', 'key');
        self::assertSame('Басты бет', $names($kk)['home']);
        self::assertSame('Home', $names($en)['home']);
        $article = static fn (array $schema): array => \array_values(\array_filter($schema['templates'], static fn (array $t): bool => 'blog_article' === $t['key']))[0];
        self::assertSame('Мақала тақырыбы', $article($kk)['fields'][0]['label']);
        self::assertNotSame($article($kk)['shortcut'], $article($en)['shortcut']);

        // the label of an automatic list is read from the catalogue even though listing() comes in the chain
        $template = static fn (array $schema, string $key): array => \array_values(\array_filter($schema['templates'], static fn (array $t): bool => $key === $t['key']))[0];
        self::assertSame('Блог мақалалары', $template($kk, 'blog')['listing']['label']);
        self::assertSame('Category articles', $template($en, 'blog_category')['listing']['label']);
        foreach (['blog', 'blog_category'] as $key) {
            self::assertNotSame('', $template($kk, $key)['listing']['label']);
            self::assertNotSame($template($kk, $key)['listing']['label'], $template($en, $key)['listing']['label']);
        }
    }

    #[Test]
    public function everyoneChoosesForHimselfWithoutAnyRightButCsrfAndASignInAreNeeded(): void
    {
        $user = $this->person(Role::REQUESTS);
        $this->signIn($user);

        self::assertSame('csrf', $this->api('PUT', 'me/locale', ['locale' => 'en'], 403, false)['details']['code']);
        self::assertSame('en', $this->api('PUT', 'me/locale', ['locale' => 'en'])['locale']);
        self::assertSame('en', self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->id)?->locale);

        $this->client->restart();
        $this->api('PUT', 'me/locale', ['locale' => 'kk'], 401, false);
    }

    #[Test]
    public function aPageWithoutAPersonSpeaksTheLanguageOfTheSite(): void
    {
        foreach (['/admin/login', '/admin/forgot-password'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('<html lang="ru">', (string) $this->client->getResponse()->getContent());
            self::assertSame('ru', $this->spoke);
        }
        $this->api('GET', 'me', null, 401);
        self::assertSame('ru', $this->spoke);
    }

    #[Test]
    public function anErrorStoppedInsideTheGatesStillSpeaksTheLanguageOfThePerson(): void
    {
        $this->signIn($this->person(Role::EDITOR, 'kk'));

        $this->api('PUT', 'me/locale', ['locale' => 'en'], 403, false); // the CSRF check runs before any controller
        self::assertSame('kk', $this->spoke);
        $this->api('GET', 'users', null, 403); // a right the person does not have
        self::assertSame('kk', $this->spoke);
    }

    #[Test]
    public function aRefusalOfTheRouterComesAheadOfThePersonAndSpeaksTheLanguageOfTheSite(): void
    {
        $this->signIn($this->person(Role::EDITOR, 'kk'));

        $this->api('DELETE', 'me', null, 405);
        self::assertSame('ru', $this->spoke);
        $this->api('GET', 'no-such-thing', null, 404); // an unknown address reaches the route of the pages, past the firewall
        self::assertSame('kk', $this->spoke);
    }

    #[Test]
    public function aLanguageThatLeftTheListCountsAsNoChoice(): void
    {
        $user = new User('a@example.com', 'A');
        $user->locale = 'kk';
        $locale = new AdminLocale('ru', ['ru', 'en'], 'ru', new NullLogger());
        self::assertSame('ru', $locale->forUser($user));
        $user->locale = 'en';
        self::assertSame('en', $locale->forUser($user));
        self::assertSame('ru', $locale->forUser(null));
    }

    #[Test]
    public function aPublicRouteTakesTheMainLanguageWhenThePanelHasIt(): void
    {
        self::assertSame('de', (new AdminLocale('de', ['de'], 'de', new NullLogger()))->forPublic());
        self::assertSame('ru', (new AdminLocale('kk', ['kk', 'ru'], 'ru', new NullLogger()))->forPublic());
        self::assertSame('kk', (new AdminLocale('kk', ['kk', 'en'], 'ru', new NullLogger()))->forPublic());

        $this->client->request('POST', '/request/call', ['phone' => '']);
        self::assertSame('ru', $this->spoke);
    }

    #[Test]
    public function theLanguageDoesNotStayFromOneRequestToTheNext(): void
    {
        $this->signIn($this->person(Role::ADMIN, 'kk'));
        self::assertSame('kk', $this->spoke);
        self::assertSame('en', I18n::locale(), 'between requests: framework.default_locale of the application');

        $this->client->restart();
        $this->client->request('GET', '/admin/login');
        self::assertSame('ru', $this->spoke);
    }

    #[Test]
    public function aPageOfTheSiteKeepsItsOwnLanguage(): void
    {
        $this->signIn($this->person(Role::ADMIN, 'kk'));

        $this->client->request('GET', '/no-such-page-of-the-site');
        self::assertSame('en', $this->spoke, 'framework.default_locale of the site: the bundle does not touch its pages');
        self::assertSame('en', $this->client->getRequest()->getLocale());
        self::assertSame('en', I18n::locale(), 'what runs after the answer (the body of a stream, kernel.terminate) is still the page of the site');
    }

    /** @return array<string, mixed> window.ITK_PREVIEW of the page in the frame */
    private function preview(string $id): array
    {
        $this->client->request('GET', '/admin/preview/' . $id);
        self::assertResponseIsSuccessful();
        \preg_match('/window\.ITK_PREVIEW=(\{.*?\});<\/script>/', (string) $this->client->getResponse()->getContent(), $m);

        return (array) \json_decode($m[1] ?? '{}', true);
    }

    #[Test]
    public function theOverlayOfThePreviewSpeaksThePersonAndThePageItsOwnLanguage(): void
    {
        $page = self::getContainer()->get(PageService::class)->create(['template' => 'default', 'title' => 'Тест языка', 'url' => '/test-i18n-preview-' . \bin2hex(\random_bytes(4))]);
        $id = (string) $page['id'];
        $this->signIn($this->person(Role::ADMIN, 'kk'));

        $texts = (array) $this->preview($id)['texts'];
        self::assertSame('en', $this->spoke, 'the page is drawn in framework.default_locale of the site');
        self::assertSame('en', $this->client->getRequest()->getLocale());
        self::assertSame('Өңдеу', $texts['edit']);
        self::assertSame('«{label}» секциясын өңдеу', $texts['edit_section']);

        $this->api('PUT', 'me/locale', ['locale' => null]);
        self::assertSame('Изменить', ((array) $this->preview($id)['texts'])['edit'], 'without a choice: admin_locale');

        $this->api('PUT', 'me/locale', ['locale' => 'en']);
        $this->client->request('GET', '/admin/preview/no-such-page');
        self::assertResponseStatusCodeSame(404);
        $problem = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="en">', $problem);
        self::assertStringContainsString('<title>Preview unavailable</title>', $problem);

        self::getContainer()->get(PageService::class)->remove($id);
    }

    #[Test]
    public function thePreviewOfALanguageWithoutAVersionSaysSoInTheLanguageOfThePerson(): void
    {
        $page = self::getContainer()->get(PageService::class)->create(['template' => 'default', 'title' => 'Тест языка', 'url' => '/test-i18n-noversion-' . \bin2hex(\random_bytes(4))]);
        $id = (string) $page['id'];
        $this->signIn($this->person(Role::ADMIN, 'kk'));

        $this->client->request('GET', '/admin/preview/' . $id . '?locale=fr');
        self::assertResponseStatusCodeSame(404);
        $problem = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Бұл тілде нұсқа әлі жоқ', $problem);
        self::assertStringNotContainsString('content.no_version', $problem);
        self::assertStringContainsString('<html lang="kk">', $problem);

        self::getContainer()->get(PageService::class)->remove($id);
    }

    #[Test]
    public function outsideARequestTheLanguageIsTheOneOfTheSite(): void
    {
        self::assertSame('en', I18n::locale());
        self::assertSame('Choose a language from the list', I18n::t('i18n.locale_unknown_field'));
    }

    #[Test]
    public function anUnknownKeyIsInTheLog(): void
    {
        self::assertSame('i18n.not_in_the_log_yet', I18n::t('i18n.not_in_the_log_yet'));

        $warnings = self::getContainer()->get('logger')->records;
        $found = \array_filter($warnings, static fn (array $r): bool => 'warning' === $r['level'] && 'i18n.not_in_the_log_yet' === ($r['context']['key'] ?? null));
        self::assertCount(1, $found);
    }

    #[Test]
    public function theLettersOfAWorkerGoInTheLanguageOfTheirPersonAndNothingStays(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => 'localhost', 'port' => 25, 'encryption' => 'none', 'username' => '', 'clearPassword' => true, 'fromAddress' => 'test-site@example.com', 'fromName' => 'Тест']);
        $kazakh = $this->person(Role::EDITOR, 'kk');
        $default = $this->person(Role::EDITOR);
        $english = $this->person(Role::EDITOR, 'en');
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ([$kazakh, $default, $english] as $user) {
            $bus->dispatch(new RequestPasswordLink($user->email, 'https://site.test'));
        }

        $transport = self::getContainer()->get('messenger.transport.async');
        $languages = [];
        $events = self::getContainer()->get('event_dispatcher');
        $listen = function (WorkerRunningEvent $event) use (&$languages): void {
            $languages[] = \count($this->lettersIn()) >= 3 ? $event->getWorker()->stop() : null;
        };
        $events->addListener(WorkerRunningEvent::class, $listen);
        (new Worker(['async' => new class($transport) implements \Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface {
            /** @var array<string, true> */
            private array $done = [];

            public function __construct(private readonly \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport)
            {
            }

            public function get(): iterable
            {
                foreach ($this->transport->getSent() as $envelope) { // one by one and only the requests: the letters stay in the queue for the test to read
                    $id = \spl_object_hash($envelope->getMessage());
                    if ($envelope->getMessage() instanceof RequestPasswordLink && !isset($this->done[$id])) {
                        $this->done[$id] = true;

                        return [$envelope];
                    }
                }

                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        }], $bus, $events))->run(['sleep' => 0]);
        $events->removeListener(WorkerRunningEvent::class, $listen);

        $letters = $this->lettersIn();
        self::assertCount(3, $letters);
        self::assertSame([$kazakh->email, $default->email, $english->email], \array_map(static fn (SendMail $m): string => $m->to, $letters));
        self::assertStringContainsString('<html lang="kk">', $letters[0]->html);
        self::assertStringContainsString('<html lang="ru">', $letters[1]->html);
        self::assertStringContainsString('<html lang="en">', $letters[2]->html);
    }

    /** @return list<SendMail> */
    private function lettersIn(): array
    {
        $out = [];
        foreach (self::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendMail) {
                $out[] = $envelope->getMessage();
            }
        }

        return $out;
    }

    #[Test]
    public function everyMessageOfAWorkerStartsFromTheLanguageOfTheSite(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('kk'); // what a message before this one could have left, or the reset of the services between messages
        self::assertSame('kk', I18n::locale());

        self::getContainer()->get('event_dispatcher')->dispatch(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async'));

        self::assertSame('en', I18n::locale());
    }

    #[Test]
    public function runPutsTheLanguageBackAlsoAfterAnError(): void
    {
        $locale = self::getContainer()->get(AdminLocale::class);
        self::assertSame('kk', $locale->run('kk', static fn (): string => I18n::locale()));
        self::assertSame('en', I18n::locale());
        try {
            $locale->run('en', static function (): void {
                throw new \RuntimeException('in the middle of a letter');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('en', I18n::locale());
    }

    #[Test]
    public function aLetterAboutARequestIsInTheLanguageOfTheSiteWhoeverCausedIt(): void
    {
        $request = self::getContainer()->get(RequestService::class)->submit('call', ['phone' => '+7(900)000-00-44'], ['ip' => '203.0.113.9']);
        $mail = self::getContainer()->get(AdminLocale::class)->run('kk', static fn (): SendMail => self::getContainer()->get(MailComposer::class)->newRequest($request, 'test-site@example.com'));

        self::assertStringContainsString('<html lang="ru">', $mail->html);
        self::getContainer()->get(EntityManagerInterface::class)->remove($request);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    #[Test]
    public function theDateOfALetterAboutARequestIsWrittenTheWayOfTheLanguageOfTheSite(): void
    {
        $request = self::getContainer()->get(RequestService::class)->submit('call', ['phone' => '+7(900)000-00-46'], ['ip' => '203.0.113.9']);
        $mail = self::getContainer()->get(MailComposer::class)->newRequest($request, 'test-site@example.com');

        self::assertStringContainsString($request->createdAt->format('d.m.Y H:i'), $mail->text);
        self::assertSame(['M j, Y, g:i A', 'd.m.Y H:i'], I18n::variants('http.mail_date_format'), 'en reads «Nov 2, 2026, 3:55 AM»; ru and kk keep the day first');
        self::getContainer()->get(EntityManagerInterface::class)->remove($request);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    #[Test]
    public function aMessageForTelegramIsInTheLanguageOfTheSiteAlsoInsideTheRequestOfAPerson(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $request = self::getContainer()->get(RequestService::class)->submit('call', ['phone' => '+7(900)000-00-45'], ['ip' => '203.0.113.9']);
        $request->delivery = \Itk\Cms\Entity\Request::DELIVERY_FAILED;
        $em->flush();
        $seen = null;
        $events = self::getContainer()->get('event_dispatcher');
        $events->addListener(\Itk\Cms\Event\RequestDelivery::class, static function () use (&$seen): void {
            $seen = I18n::locale();
        });

        self::getContainer()->get(AdminLocale::class)->run('kk', static fn () => self::getContainer()->get(SendRequestNotificationHandler::class)(new SendRequestNotification((int) $request->id)));

        self::assertSame('ru', $seen);
        $em->remove($request);
        $em->flush();
    }

    #[Test]
    public function theBellDrawsANotificationOfTheCoreInTheLanguageOfItsReader(): void
    {
        self::getContainer()->get(NotifierInterface::class)->notify(Notification::translated('test.i18n', 'i18n.notification_telegram_failed', ['id' => 7], 'Connection refused'));
        self::getContainer()->get(NotifierInterface::class)->notify(new Notification('test.site', 'Заказ оплачен', 'Текст сайта'));
        $reader = $this->person(Role::EDITOR, 'kk');
        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(StoredNotification::class)->findOneBy(['type' => 'test.i18n']);
        self::assertInstanceOf(StoredNotification::class, $stored);
        self::assertSame('Request #7 did not reach Telegram', $stored->title, 'the fallback, in the language of the moment');
        $reader->createdAt = new \DateTimeImmutable('-1 day');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->signIn($reader);
        $byType = static fn (array $list): array => \array_column($list['items'], null, 'type');
        $items = $byType($this->api('GET', 'notifications'));
        self::assertSame('Өтінім №7 Telegram-ға жетпеді', $items['test.i18n']['title']);
        self::assertSame('Connection refused', $items['test.i18n']['body']);
        self::assertSame('Заказ оплачен', $items['test.site']['title'], 'the text of a site stays as it is');

        $this->api('PUT', 'me/locale', ['locale' => 'en']);
        self::assertSame('Request #7 did not reach Telegram', $byType($this->api('GET', 'notifications'))['test.i18n']['title']);

        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(StoredNotification::class)->findOneBy(['type' => 'test.i18n']);
        self::assertInstanceOf(StoredNotification::class, $stored);
        $stored->i18n = ['title' => ['key' => 'i18n.left_the_catalogues', 'params' => []]];
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('Request #7 did not reach Telegram', $byType($this->api('GET', 'notifications'))['test.i18n']['title']);
    }

    #[Test]
    public function aLetterThatFailedForGoodKeepsItsTextsAsKeysAndTheErrorOfTheServerAsItIs(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $events = self::getContainer()->get('event_dispatcher');
        $general = new SendMail('test-site@example.com', 'Проверка', 'text', '<p>html</p>', SendMail::TEST);
        $request = new SendMail('test-site@example.com', 'Заявка', 'text', '<p>html</p>', SendMail::REQUEST, '77');

        // the retries are over
        $events->dispatch(new WorkerMessageFailedEvent(new Envelope($general, [new RedeliveryStamp(5)]), 'async', new \RuntimeException('no reason for people')));
        $events->dispatch(new WorkerMessageFailedEvent(new Envelope($request, [new RedeliveryStamp(5)]), 'async', new MailDeliveryException('550 refused')));

        $rows = $em->getRepository(StoredNotification::class)->findBy(['type' => Notification::MAIL_FAILED], ['id' => 'DESC'], 2);
        [$second, $first] = $rows;
        self::assertSame('Email not sent: Проверка', $first->title);
        self::assertSame('The mail server did not accept the email', $first->body);
        self::assertSame('i18n.notification_mail_refused', $first->i18n['body']['key'] ?? null);
        self::assertSame('The email about request #77 was not delivered', $second->title);
        self::assertSame('550 refused', $second->body);
        self::assertArrayNotHasKey('body', $second->i18n ?? []);
        self::assertSame('The email about request #77 was not delivered', self::getContainer()->get(AdminLocale::class)->run('en', static fn (): mixed => NotificationInbox::present($second, false)['title']));
        foreach ($rows as $row) {
            $em->remove($row);
        }
        $em->flush();
    }

    #[Test]
    public function aBuiltInRoleSpeaksTheLanguageOfTheReaderUntilItIsRenamed(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $role = new Role('test-i18n', 'Редактор');
        $role->description = 'Роль, созданная человеком';
        $em->persist($role);
        $em->flush();
        $this->signIn($this->person(Role::ADMIN, 'en'));

        $roles = \array_column($this->api('GET', 'roles'), null, 'id');
        self::assertSame('Administrator', $roles[Role::ADMIN]['name']);
        self::assertStringStartsWith('Everything without limits', $roles[Role::ADMIN]['description']);
        self::assertSame('Administrator', $this->api('GET', 'me')['roleName']);
        self::assertSame('Administrator', \array_column($this->api('GET', 'users'), 'roleName', 'email')[$this->api('GET', 'me')['email']]);
        self::assertSame('Редактор', $roles['test-i18n']['name'], 'a role people made is theirs, even under the same name');
        self::assertSame('Роль, созданная человеком', $roles['test-i18n']['description']);

        $this->api('PUT', 'me/locale', ['locale' => 'kk']);
        self::assertSame('Әкімші', \array_column($this->api('GET', 'roles'), null, 'id')[Role::ADMIN]['name']);

        $editor = $em->find(Role::class, Role::EDITOR);
        self::assertInstanceOf(Role::class, $editor);
        self::assertSame('Editor', self::getContainer()->get(AdminLocale::class)->run('en', static fn (): string => RoleLabel::name($editor)), 'its stored Russian name is the catalogue, so it follows the language');
        $editor->name = 'Контент-менеджер';
        $em->flush();
        self::assertSame('Контент-менеджер', \array_column($this->api('GET', 'roles'), null, 'id')[Role::EDITOR]['name']);
    }

    #[Test]
    public function aRoleNamedLikeATranslatedBuiltInOneStaysEditableButCannotBeTakenByARename(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = new Role('test-i18n-a', 'Editor');
        $other = new Role('test-i18n-b', 'Маркетолог');
        $em->persist($old);
        $em->persist($other);
        $em->flush();
        $this->signIn($this->person(Role::ADMIN, 'en'));

        self::assertSame('Still saves', $this->api('PATCH', 'roles/test-i18n-a', ['description' => 'Still saves'])['description']);
        self::assertSame('Editor', $this->api('PATCH', 'roles/test-i18n-a', ['name' => 'Editor', 'description' => 'Same name again'])['name']);
        $this->api('PATCH', 'roles/test-i18n-b', ['name' => 'Editor'], 409);
        $this->api('PATCH', 'roles/test-i18n-b', ['name' => 'Редактор'], 409);
        $this->api('PATCH', 'roles/test-i18n-b', ['name' => 'Marketer']);
    }
}
