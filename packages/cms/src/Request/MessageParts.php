<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\Max\MaxApiSender;

/**
 * A request text as the messages that carry it, in order, and the rules of each messenger for them.
 * A text the messenger takes in one message stays one message, byte for byte.
 *
 * @internal
 */
final class MessageParts
{
    /** Telegram counts code points after it has read the HTML, trimmed the text and dropped some characters (TDLib: MessageContent.cpp, fix_formatted_text). */
    private const TELEGRAM_LIMIT = 4096;
    /** The Bot API refuses a longer raw text before it reads the HTML (telegram-bot-api, Client::get_formatted_text). */
    private const TELEGRAM_BYTES = 32768;
    /** What TDLib deletes before it counts (clean_input_string). */
    private const TELEGRAM_DELETED = '~[\r\x{2028}-\x{202E}\x{30A}\x{333}\x{33F}]~u';
    private const TELEGRAM_ENTITY = '~&(?:\#[0-9]{1,8}|\#[xX][0-9a-fA-F]{1,6}|amp|lt|gt|quot);~';

    /** MAX takes two messages a second in one chat, Telegram asks for one. */
    private const PAUSE = 1.0;
    /** A Telegram group takes 20 messages a minute: a longer request goes slower. */
    private const BURST = 20;
    private const SLOW_PAUSE = 3.1;

    /**
     * @param non-empty-list<string> $texts
     * @param int $burst how many messages the messenger takes before they must go slower
     */
    private function __construct(public readonly array $texts, private readonly int $burst = self::BURST)
    {
    }

    public static function telegram(string $html): self
    {
        if (self::telegramTakesWhole($html)) {
            return new self([$html]);
        }
        $texts = (new MessageSplitter(self::TELEGRAM_LIMIT, self::TELEGRAM_BYTES, self::telegramLength(...)))->split($html);

        // the Telegram bridge of Symfony drops a text "0" (array_filter) and Telegram then refuses the message as empty; it trims the space itself
        return new self(\array_map(static fn (string $text): string => '0' === $text ? '0 ' : $text, $texts));
    }

    /** MAX counts code points of the HTML itself. */
    public static function max(string $html): self
    {
        if (\mb_strlen($html) <= MaxApiSender::TEXT_LIMIT) {
            return new self([$html]);
        }

        return new self(
            (new MessageSplitter(MaxApiSender::TEXT_LIMIT, 4 * MaxApiSender::TEXT_LIMIT, static fn (string $piece): int => \mb_strlen($piece)))->split($html),
            \PHP_INT_MAX, // MAX has no limit a minute
        );
    }

    /**
     * Waits before a message that follows another one in the same chat: sent back to back, the third is refused by the rate limit, on every retry again.
     *
     * @param (\Closure(float): void)|null $sleep takes seconds
     */
    public function pauseBefore(int $index, ?\Closure $sleep = null): void
    {
        if (0 === $index) {
            return;
        }
        $seconds = \count($this->texts) > $this->burst ? self::SLOW_PAUSE : self::PAUSE;
        if (null !== $sleep) {
            $sleep($seconds);

            return;
        }
        $until = \hrtime(true) + $seconds * 1_000_000_000;
        while (($left = $until - \hrtime(true)) > 0) { // a signal (messenger:consume --keepalive) ends usleep() early
            \usleep((int) \ceil($left / 1000));
        }
    }

    /** The reason of a refused message, with its number when the text needed several. */
    public function failure(int $index, string $reason): string
    {
        $total = \count($this->texts);

        return 1 === $total ? $reason : I18n::t('http.request_part_failed', ['part' => $index + 1, 'parts' => $total, 'reason' => $reason]);
    }

    private static function telegramTakesWhole(string $html): bool
    {
        if (\strlen($html) > self::TELEGRAM_BYTES) {
            return false;
        }
        $text = \preg_replace(
            ['~^[ \r\n]+~', '~' . MessageSplitter::TAG . '~', self::TELEGRAM_ENTITY, self::TELEGRAM_DELETED, '~[\x00-\x09\x0B\x0C\x0E-\x20]~'],
            ['', '', 'x', '', ' '],
            $html,
        );

        return null !== $text && \mb_strlen(\rtrim($text, " \n")) <= self::TELEGRAM_LIMIT;
    }

    private static function telegramLength(string $piece): int
    {
        return match (true) {
            '<' === $piece[0] && '<' !== $piece => 0,
            1 === \preg_match(self::TELEGRAM_ENTITY, $piece) => 1,
            default => \mb_strlen((string) \preg_replace(self::TELEGRAM_DELETED, '', $piece)),
        };
    }
}
