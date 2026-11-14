<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Setting;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Security\TwoFactor\SecretBox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BotSettingsTest extends TestCase
{
    private const TELEGRAM = '123456789:abcdefghijklmnopqrstuvwxyz_123456789';
    private const MAX = 'max-private-token';

    /** @var array<string, Setting> */
    private array $rows = [];
    private BotSettings $settings;

    protected function setUp(): void
    {
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('find')->willReturnCallback(fn (array $id): ?Setting => $this->rows[$id['key']] ?? null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (Setting $row): void { $this->rows[$row->key] = $row; });
        $this->settings = new BotSettings($em, $repo, new SecretBox('bot-settings-test-secret'));
    }

    #[Test]
    public function tokensAreSealedSharedKeptOnEmptyAndRemovedExplicitly(): void
    {
        $this->settings->save(['telegram' => ['token' => self::TELEGRAM], 'max' => ['token' => self::MAX]]);
        foreach (['telegramBot' => self::TELEGRAM, 'maxBot' => self::MAX] as $key => $token) {
            self::assertSame('*', $this->rows[$key]->locale);
            self::assertStringStartsWith('v1.', $this->rows[$key]->value['token']);
            self::assertStringNotContainsString($token, (string) \json_encode($this->rows[$key]->value));
        }
        $this->settings->save(['telegram' => ['token' => ''], 'max' => []]);
        self::assertSame(self::TELEGRAM, $this->settings->token('telegram'));
        self::assertSame(self::MAX, $this->settings->token('max'));
        $replacement = '987654321:abcdefghijklmnopqrstuvwxyz_987654321';
        $this->settings->save(['telegram' => ['token' => $replacement], 'max' => ['token' => 'replaced-max-token']]);
        self::assertSame($replacement, $this->settings->token('telegram'));
        self::assertSame('replaced-max-token', $this->settings->token('max'));
        $this->settings->save(['telegram' => ['clear' => true], 'max' => ['clear' => true]]);
        self::assertNull($this->settings->token('telegram'));
        self::assertNull($this->settings->token('max'));
    }

    #[Test]
    public function validationRejectsBothFieldsWithoutSavingOrEchoingTheInput(): void
    {
        $this->settings->save(['telegram' => ['token' => self::TELEGRAM]]);
        foreach (['ru', 'en', 'kk'] as $locale) {
            I18n::useLocale($locale);
            try {
                $this->settings->save(['telegram' => ['token' => 'bad telegram secret'], 'max' => ['token' => 'bad max secret']]);
                self::fail('Expected validation');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
                self::assertSame(['telegram', 'max'], \array_keys($e->details()));
                self::assertStringNotContainsString('http.', $e->getMessage());
                self::assertStringNotContainsString('bad telegram secret', $e->getMessage());
                self::assertStringNotContainsString('bad max secret', $e->getMessage());
            } finally {
                I18n::useLocale('ru');
            }
        }
        self::assertSame(self::TELEGRAM, $this->settings->token('telegram'));
        self::assertNull($this->settings->token('max'));
    }

    #[Test]
    public function invalidTypesControlCharactersAndOversizedMaxTokensAreRejected(): void
    {
        foreach ([['max' => ['token' => \str_repeat('x', 513)]], ['max' => ['token' => "a\0b"]], ['max' => ['token' => "a\nb"]], ['telegram' => ['token' => []]], ['max' => 'secret'], ['max' => ['clear' => 'yes']]] as $input) {
            try {
                $this->settings->save($input);
                self::fail('Expected validation');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    #[Test]
    public function unreadableSealedTokensCountAsAbsent(): void
    {
        $this->rows['telegramBot'] = new Setting('telegramBot', '*', ['token' => (new SecretBox('old-secret'))->seal(self::TELEGRAM)]);
        self::assertNull($this->settings->token('telegram'));
        self::assertTrue($this->settings->unreadable('telegram'));
        self::assertFalse($this->settings->unreadable('max'));
    }
}
