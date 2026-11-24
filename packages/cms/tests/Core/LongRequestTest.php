<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\LongText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** A long field of a form reaches the staff whole, or the visitor is told that it is too long. */
final class LongRequestTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', [], [], ['schema_file' => __DIR__ . '/Fixture/schema_long_requests.php']);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("DELETE FROM itk_notification WHERE type = 'request.new' AND link IN (SELECT '/requests/' || id FROM itk_request WHERE form_key LIKE 'long_%')");
        $db->executeStatement("DELETE FROM itk_request WHERE form_key LIKE 'long_%'");
        self::getContainer()->get(EntityManagerInterface::class)->createQuery("DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.email = 'test-long-admin@example.com'")->execute();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<array-key, mixed>
     */
    private static function order(array $fields = []): array
    {
        return $fields + ['name' => 'Иван', 'phone' => '+79000000011', 'items' => 'Шлем', 'consent' => '1'];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(string $form, array $fields): array
    {
        $this->client->request('POST', '/request/' . $form, $fields);
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => (array) \json_decode((string) $response->getContent(), true)];
    }

    /**
     * What is in the table, not what the entity manager remembers.
     *
     * @return array<array-key, mixed>|null
     */
    private function stored(string $form = 'long_order'): ?array
    {
        $json = self::getContainer()->get(Connection::class)->fetchOne('SELECT data FROM itk_request WHERE form_key = ? ORDER BY id DESC LIMIT 1', [$form]);

        return false === $json ? null : \json_decode((string) $json, true);
    }

    /** @param array<string, mixed> $fields */
    private function assertSaved(array $fields, string $key, string $expected): void
    {
        $out = $this->post('long_order', self::order($fields));

        self::assertSame(200, $out['status'], (string) ($out['body']['message'] ?? ''));
        self::assertSame(\mb_strlen($expected), \mb_strlen((string) ($this->stored()[$key] ?? '')));
        self::assertSame($expected, $this->stored()[$key]);
    }

    /** @param array<string, mixed> $fields */
    private function assertTooLong(array $fields, string $key, int $max): void
    {
        $out = $this->post('long_order', self::order($fields));

        self::assertSame(422, $out['status']);
        self::assertFalse($out['body']['success']);
        self::assertStringContainsString((string) $max, $out['body']['errors'][$key]);
        self::assertStringContainsString('длиннее', $out['body']['errors'][$key]);
        self::assertNull($this->stored(), 'no request is created');
    }

    /** @return iterable<array-key, mixed> */
    public static function lengthsWithinTheLimit(): iterable
    {
        foreach ([1000, 1001, 1002, 5999, 6000] as $length) {
            yield (string) $length => [$length];
        }
    }

    #[Test]
    #[DataProvider('lengthsWithinTheLimit')]
    public function aValueWithinTheLimitOfItsFieldIsSavedWhole(int $length): void
    {
        $text = LongText::order($length);

        $this->assertSaved(['items' => $text], 'items', $text);
    }

    /** @return iterable<array-key, mixed> */
    public static function lengthsOverTheLimit(): iterable
    {
        foreach ([6001, 6500, 100000] as $length) {
            yield (string) $length => [$length];
        }
    }

    #[Test]
    #[DataProvider('lengthsOverTheLimit')]
    public function aValueOverTheLimitOfItsFieldIsAnErrorAndCreatesNoRequest(int $length): void
    {
        $this->assertTooLong(['items' => LongText::order($length)], 'items', 6000);
    }

    #[Test]
    public function aShortLimitTakesExactlyItsLength(): void
    {
        $this->assertSaved(['remark' => \str_repeat('п', 120)], 'remark', \str_repeat('п', 120));
    }

    #[Test]
    public function aShortLimitRefusesOneMoreCharacter(): void
    {
        $this->assertTooLong(['remark' => \str_repeat('п', 121)], 'remark', 120);
    }

    /** @return iterable<array-key, mixed> */
    public static function valuesWithoutALimit(): iterable
    {
        yield '1000 stays' => [1000, 1000];
        yield '1001 stays' => [1001, 1001];
        yield '1002 is cut' => [1002, 1001];
        yield '100000 is cut' => [100000, 1001];
    }

    #[Test]
    #[DataProvider('valuesWithoutALimit')]
    public function aFieldWithoutALimitStillKeeps1001CharactersWithoutAnError(int $sent, int $kept): void
    {
        $this->assertSaved(['free' => \str_repeat('б', $sent)], 'free', \str_repeat('б', $kept));
    }

    /** @return iterable<array-key, mixed> */
    public static function valuesThatAreMeasuredAfterCleaning(): iterable
    {
        $cyrillic = \str_repeat('ж', 6000);
        yield 'Cyrillic is counted in characters' => [$cyrillic, $cyrillic];
        $emoji = \str_repeat('😀', 6000);
        yield 'emoji is counted in characters' => [$emoji, $emoji];
        yield 'tags do not count' => ['<p><b>' . $cyrillic . '</b></p>', $cyrillic];
        yield 'spaces at the edges do not count' => [\str_repeat(" \n", 2000) . $cyrillic . \str_repeat("\t ", 2000), $cyrillic];
    }

    #[Test]
    #[DataProvider('valuesThatAreMeasuredAfterCleaning')]
    public function theLengthIsCountedAfterTrimAndStripTags(string $sent, string $kept): void
    {
        $this->assertSaved(['items' => $sent], 'items', $kept);
    }

    /** @return iterable<array-key, mixed> */
    public static function valuesTooLongAfterCleaning(): iterable
    {
        yield 'Cyrillic' => [\str_repeat('ж', 6001)];
        yield 'emoji' => [\str_repeat('😀', 6001)];
        yield 'inside tags and spaces' => ['  <b>' . \str_repeat('ж', 6001) . '</b>  '];
    }

    #[Test]
    #[DataProvider('valuesTooLongAfterCleaning')]
    public function oneCharacterOverTheLimitAfterCleaningIsAnError(string $sent): void
    {
        $this->assertTooLong(['items' => $sent], 'items', 6000);
    }

    #[Test]
    public function brokenUtf8IsStillStoredAsAQuestionMark(): void
    {
        $this->assertSaved(['items' => "ab\xffc"], 'items', 'ab?c');
    }

    #[Test]
    public function aJsonBodyIsNotCutEither(): void
    {
        $text = LongText::order(5000);
        $this->client->jsonRequest('POST', '/request/long_order', self::order(['items' => $text]));

        self::assertResponseIsSuccessful();
        self::assertSame($text, $this->stored()['items']);
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function refusalsThatDidNotChange(): iterable
    {
        yield 'empty' => [['items' => ''], 'items', 'нужно заполнить'];
        yield 'only tags and spaces' => [['items' => " <b> </b>\n"], 'items', 'нужно заполнить'];
        yield 'toggle off' => [['consent' => '0'], 'consent', 'нужно заполнить'];
        yield 'pattern' => [['phone' => '12345'], 'phone', 'Проверьте номер телефона'];
    }

    /** @param array<string, string> $fields */
    #[Test]
    #[DataProvider('refusalsThatDidNotChange')]
    public function requiredPatternAndToggleWorkAsBefore(array $fields, string $key, string $message): void
    {
        $out = $this->post('long_order', self::order($fields));

        self::assertSame(422, $out['status']);
        self::assertStringContainsString($message, $out['body']['errors'][$key]);
        self::assertNull($this->stored());
    }

    #[Test]
    public function thePatternReadsTheWholeLongValue(): void
    {
        $out = $this->post('long_order', self::order(['code' => \str_repeat('к', 3000) . '#']));

        self::assertSame(422, $out['status']);
        self::assertSame('Без решётки', $out['body']['errors']['code']);
    }

    #[Test]
    public function thePatternAcceptsAValidValueThatIsLongerThanTheStackOfTheJit(): void
    {
        $text = \str_repeat('ж', 6000);

        self::assertSame(200, $this->post('long_open', ['safe' => $text])['status']);
        self::assertSame($text, $this->stored('long_open')['safe']);
        self::assertSame(422, $this->post('long_open', ['safe' => \str_repeat('ж', 5999) . '>'])['status']);
    }

    #[Test]
    public function theLargestPossibleLimitIsNotAnOverflow(): void
    {
        $text = LongText::order(8000);

        self::assertSame(200, $this->post('long_open', ['items' => $text])['status']);
        self::assertSame($text, $this->stored('long_open')['items']);
    }

    #[Test]
    public function theHoneypotStillPretendsSuccessAndSavesNothing(): void
    {
        $out = $this->post('long_order', self::order(['items' => \str_repeat('б', 100000), 'itk_hp' => 'robot']));

        self::assertSame(200, $out['status']);
        self::assertSame(['success' => true], $out['body']);
        self::assertNull($this->stored());
    }

    /** @return array<array-key, mixed> */
    private function longCard(): array
    {
        $sent = ['name' => \str_repeat('И', 400), 'phone' => '+7 900 000-00-22, добавочный номер склада 123456', 'city' => \str_repeat('К', 300), 'message' => LongText::order(5500), 'items' => LongText::cart(20)];
        self::assertSame(200, $this->post('long_card', $sent)['status']);
        $stored = $this->stored('long_card');
        foreach ($sent as $key => $value) {
            self::assertSame($value, $stored[$key], $key);
        }

        return $sent;
    }

    private function signIn(): void
    {
        $admin = self::getContainer()->get(UserManager::class)->create('test-long-admin@example.com', 'Тест', 'admin', 'password-for-test');
        $this->client->loginUser($admin, 'itk_admin');
    }

    /** @return array<array-key, mixed> */
    private function read(string $path): array
    {
        $this->client->request('GET', '/admin/api/' . $path);
        self::assertResponseIsSuccessful();

        return \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function last(): Request
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Request::class)->findOneBy(['form' => 'long_card'], ['id' => 'DESC']);
    }

    #[Test]
    public function theCardOfTheAdminShowsEveryFieldWhole(): void
    {
        $sent = $this->longCard();
        $id = $this->last()->id;
        $this->signIn();
        $card = $this->read('requests/' . $id);
        self::assertSame($sent['items'], $card['data']['items']);
        self::assertSame($sent['message'], $card['note']);
        self::assertSame($sent['name'], $card['name']);
        self::assertSame($sent['phone'], $card['phone']);
        self::assertSame($sent['city'], $card['city']);
    }

    #[Test]
    public function theCardKeepsTheColumnWhenTheDataSaysSomethingElse(): void
    {
        $r = new Request('long_card');
        $r->name = 'Исправленное имя';
        $r->data = ['name' => 'Другое имя'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $this->signIn();

        self::assertSame('Исправленное имя', $this->read('requests/' . $r->id)['name']);
    }

    #[Test]
    public function theCardStaysWholeAfterTheStatusIsChanged(): void
    {
        $sent = $this->longCard();
        $id = $this->last()->id;
        $this->signIn();
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->client->jsonRequest('PATCH', '/admin/api/requests/' . $id, ['work' => 'progress'], ['HTTP_X_CSRF_TOKEN' => $m[1]]);

        self::assertResponseIsSuccessful();
        self::assertSame($sent['name'], \json_decode((string) $this->client->getResponse()->getContent(), true)['name']);
    }

    #[Test]
    public function theListAndTheDashboardKeepTheShortColumns(): void
    {
        $this->longCard();
        $id = $this->last()->id;
        $this->signIn();

        foreach ([$this->read('requests'), $this->read('dashboard')['newRequests']] as $rows) {
            $row = \array_values(\array_filter($rows, static fn (array $r): bool => $r['id'] === $id))[0];
            self::assertSame(32, \mb_strlen($row['phone']));
            self::assertSame(120, \mb_strlen($row['name']));
            self::assertSame(120, \mb_strlen($row['city']));
        }
    }

    #[Test]
    public function theLetterCarriesEveryFieldWhole(): void
    {
        $sent = $this->longCard();
        $letter = self::getContainer()->get(MailComposer::class)->newRequest($this->last(), 'test-long@example.com');

        foreach ($sent as $value) {
            self::assertStringContainsString($value, $letter->text);
            self::assertStringContainsString($value, \html_entity_decode($letter->html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        }
    }

    #[Test]
    public function theBellStaysOneShortLine(): void
    {
        $this->longCard();
        $body = (string) self::getContainer()->get(Connection::class)->fetchOne("SELECT body FROM itk_notification WHERE type = 'request.new' AND link = ?", ['/requests/' . $this->last()->id]);

        self::assertNotSame('', $body);
        self::assertLessThan(350, \mb_strlen($body));
        self::assertStringNotContainsString("\n", $body);
        self::assertStringNotContainsString('Итого', $body);
    }
}
