<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Mj4444\SimpleHttpClient\Contracts\HttpClientInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpRequestInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpResponseInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * Both messengers without a network, as strict as the real ones: a Telegram transport and the HTTP wire of the MAX client.
 * Telegram (TDLib: MessageContent.cpp, fix_formatted_text; telegram-bot-api: Client.cpp): at most 32768 bytes of HTML, at most 4096 code points after the HTML
 * is read, trimmed and cleaned, 20 messages a minute in a group. MAX: at most 4000 code points of the HTML, 2 messages a second in a chat.
 * Both refuse an empty text, broken UTF-8, half an entity and a tag that is not closed. Time passes only in sleep().
 */
final class MessengerApi implements TransportInterface, HttpClientInterface
{
    private const TAG = '~</?[a-zA-Z](?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>|</>~';

    /** The number of the send to refuse, counted from 1 over all chats; 0 = none. */
    public int $refuseAttempt = 0;
    public int $attempts = 0;
    public ?\Closure $beforeAttempt = null;
    /** @var list<array{channel: string, chat: string, text: string}> */
    private array $taken = [];
    private float $now = 0.0;
    /** @var array<string, list<float>> when each chat got a message */
    private array $times = [];

    public function forget(): void
    {
        $this->taken = [];
        $this->attempts = 0;
        $this->refuseAttempt = 0;
        $this->times = []; // what follows comes later
    }

    /** The sleep of the notifiers. */
    public function sleep(float $seconds): void
    {
        $this->now += $seconds;
    }

    /** How many seconds the notifiers waited. */
    public function waited(): float
    {
        return $this->now;
    }

    /** @return list<string> */
    public function texts(string $channel, ?string $chat = null): array
    {
        return \array_values(\array_column(\array_filter($this->taken, static fn (array $m): bool => $m['channel'] === $channel && (null === $chat || $m['chat'] === $chat)), 'text'));
    }

    public static function plain(string $html): string
    {
        return \html_entity_decode((string) \preg_replace(self::TAG, '', $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function take(string $channel, string $chat, string $text): ?string
    {
        $this->beforeAttempt?->__invoke();
        if (++$this->attempts === $this->refuseAttempt) {
            return 'test refusal';
        }
        if (!\mb_check_encoding($text, 'UTF-8')) {
            return 'broken UTF-8';
        }
        $telegram = 'telegram' === $channel;
        if ($telegram && \strlen($text) > 32768) {
            return 'text is too long in bytes';
        }
        $open = [];
        \preg_match_all(self::TAG, $text, $tags);
        foreach ($tags[0] as $tag) {
            if ('</>' === $tag) {
                if (null === \array_pop($open)) {
                    return 'unmatched end tag';
                }
                continue;
            }
            \preg_match('~^</?([a-zA-Z][\w-]*)~', $tag, $name);
            if ('/' !== $tag[1]) {
                $open[] = $name[1];
            } elseif (\array_pop($open) !== $name[1]) {
                return 'unmatched end tag';
            }
        }
        if ([] !== $open) {
            return 'unclosed tag';
        }
        $bare = (string) \preg_replace(self::TAG, '', $text);
        if (1 === \preg_match('~[<>]|&(?!(?:amp|lt|gt|quot|#39);)~', $bare)) {
            return 'broken markup';
        }
        $shown = self::plain($text);
        if ($telegram) {
            $shown = (string) \preg_replace(['~[\r\x{2028}-\x{202E}\x{30A}\x{333}\x{33F}]~u', '~[\x00-\x09\x0B\x0C\x0E-\x20]~'], ['', ' '], $shown);
        }
        if (($telegram ? \mb_strlen(\trim($shown, " \n")) : \mb_strlen($text)) > ($telegram ? 4096 : 4000)) {
            return 'message is too long';
        }
        $empty = '~^[\x00-\x20\x{A0}\x{1680}\x{180E}\x{2000}-\x{200F}\x{202E}\x{202F}\x{205F}\x{2800}\x{3000}\x{FEFF}\x{FFFC}\x{E0000}-\x{E007F}]*$~uD';
        if (($telegram && '0' === $text) || 1 === \preg_match($empty, $shown)) { // "0": the bridge of Symfony drops it
            return 'message text is empty';
        }
        [$most, $seconds] = $telegram ? [20, 60.0] : [2, 1.0];
        $key = $channel . $chat;
        $this->times[$key] = \array_values(\array_filter($this->times[$key] ?? [], fn (float $at): bool => $at > $this->now - $seconds));
        if (\count($this->times[$key]) >= $most) {
            return 'too many requests';
        }
        $this->times[$key][] = $this->now;
        $this->taken[] = ['channel' => $channel, 'chat' => $chat, 'text' => $text];

        return null;
    }

    public function send(MessageInterface $message): SentMessage
    {
        \assert($message instanceof ChatMessage);
        $error = $this->take('telegram', (string) ($message->getOptions()?->toArray()['chat_id'] ?? ''), $message->getSubject());
        if (null !== $error) {
            throw new \RuntimeException($error);
        }

        return new SentMessage($message, (string) $this);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof ChatMessage;
    }

    public function __toString(): string
    {
        return 'recorder://default';
    }

    public function request(HttpRequestInterface $request): HttpResponseInterface
    {
        \parse_str((string) \parse_url($request->getUrl(), \PHP_URL_QUERY), $query);
        $body = (array) \json_decode((string) $request->getBody()?->getBody(), true);
        $error = $this->take('max', (string) ($query['chat_id'] ?? ''), (string) ($body['text'] ?? ''));
        $answer = null === $error ? '{"message": {}}' : (string) \json_encode(['code' => 'proto.payload', 'message' => $error]);

        return $request->makeResponse(null === $error ? 200 : 400, $request->getUrl(), $request->getUrl(), null, [], 'application/json', $answer);
    }
}
