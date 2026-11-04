<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Logging\ScrubbedThrowable;
use Itk\Cms\Logging\SecretScrubProcessor;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Токен Telegram-бота не попадает в журнал: ни из текста, ни из контекста, ни из исключения (и его предыдущих исключений). */
final class SecretScrubProcessorTest extends TestCase
{
    private const TOKEN = 'bot123456:AAFakeToken_x-y';

    /** @param array<string, mixed> $context */
    private function process(array $context, string $message = 'ошибка'): LogRecord
    {
        return (new SecretScrubProcessor())(new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, $message, $context));
    }

    private function line(LogRecord $record): string
    {
        return (new LineFormatter(null, null, true, true))->format($record);
    }

    #[Test]
    public function aTokenInTheMessageAndInStringContextIsHidden(): void
    {
        $record = $this->process(['url' => 'https://api.telegram.org/' . self::TOKEN . '/sendMessage', 'nested' => ['x' => self::TOKEN]], 'POST ' . self::TOKEN);

        self::assertStringNotContainsString('AAFakeToken', $this->line($record));
        self::assertSame('POST bot***', $record->message);
    }

    #[Test]
    public function aTokenInTheMessageOfAnExceptionIsHidden(): void
    {
        $e = new \RuntimeException('Could not resolve host for "https://api.telegram.org/' . self::TOKEN . '/sendMessage"', 7);

        $record = $this->process(['exception' => $e]);
        $line = $this->line($record);

        self::assertStringNotContainsString('AAFakeToken', $line);
        self::assertStringContainsString('bot***', $line);
        self::assertStringContainsString(\RuntimeException::class, $line, 'the class of the exception is still there');
        self::assertInstanceOf(ScrubbedThrowable::class, $record->context['exception']);
        self::assertSame(7, $record->context['exception']->getCode());
        self::assertSame(\RuntimeException::class, $record->context['exception']->originalClass());
        self::assertSame(__FILE__, $record->context['exception']->getFile());
        self::assertStringNotContainsString('AAFakeToken', (string) $record->context['exception']);
    }

    #[Test]
    public function aTokenInAPreviousExceptionIsHidden(): void
    {
        $inner = new \RuntimeException('GET https://api.telegram.org/' . self::TOKEN . '/getMe failed');
        $outer = new \LogicException('Не удалось отправить заявку', 0, new \DomainException('middle', 0, $inner));

        $record = $this->process(['exception' => $outer, 'again' => ['deep' => $outer]]);
        $line = $this->line($record);

        self::assertStringNotContainsString('AAFakeToken', $line);
        self::assertStringContainsString('Не удалось отправить заявку', $line);
        self::assertStringContainsString('middle', $line);
        self::assertStringContainsString('bot***', $line);
        $e = $record->context['exception'];
        self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        self::assertStringNotContainsString('AAFakeToken', (string) $e->getPrevious()?->getPrevious()?->getMessage());
    }

    #[Test]
    public function anExceptionWithoutASecretIsLeftAsItIs(): void
    {
        $e = new \InvalidArgumentException('ничего секретного');

        self::assertSame($e, $this->process(['exception' => $e])->context['exception']);
    }
}
