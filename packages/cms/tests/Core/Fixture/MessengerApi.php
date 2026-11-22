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
 * Telegram (TDLib, MessageContent.cpp): at most 4096 code points after the HTML is read, \r is dropped. MAX: at most 4000 code points of the HTML.
 * Both refuse a blank text, broken UTF-8, half an entity and a tag that is not closed.
 */
final class MessengerApi implements TransportInterface, HttpClientInterface
{
    /** The number of the send to refuse, counted from 1 over all chats; 0 = none. */
    public int $refuseAttempt = 0;
    public int $attempts = 0;
    /** @var list<array{channel: string, chat: string, text: string}> */
    private array $taken = [];

    public function forget(): void
    {
        $this->taken = [];
        $this->attempts = 0;
        $this->refuseAttempt = 0;
    }

    /** @return list<string> */
    public function texts(string $channel, ?string $chat = null): array
    {
        return \array_values(\array_column(\array_filter($this->taken, static fn (array $m): bool => $m['channel'] === $channel && (null === $chat || $m['chat'] === $chat)), 'text'));
    }

    public static function plain(string $html): string
    {
        return \html_entity_decode((string) \preg_replace('~</?[a-z][^<>]*>~i', '', $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function take(string $channel, string $chat, string $text): ?string
    {
        if (++$this->attempts === $this->refuseAttempt) {
            return 'test refusal';
        }
        if (!\mb_check_encoding($text, 'UTF-8')) {
            return 'broken UTF-8';
        }
        $open = [];
        \preg_match_all('~<(/?)([a-z][\w-]*)[^<>]*>~i', $text, $tags, \PREG_SET_ORDER);
        foreach ($tags as [, $closes, $name]) {
            if ('' === $closes) {
                $open[] = $name;
            } elseif (\array_pop($open) !== $name) {
                return 'unmatched end tag';
            }
        }
        if ([] !== $open) {
            return 'unclosed tag';
        }
        $bare = (string) \preg_replace('~</?[a-z][^<>]*>~i', '', $text);
        if (1 === \preg_match('~[<>]|&(?!(?:amp|lt|gt|quot|#39);)~', $bare)) {
            return 'broken markup';
        }
        $plain = self::plain($text);
        $length = 'telegram' === $channel ? \mb_strlen(\str_replace("\r", '', $plain)) : \mb_strlen($text);
        if ($length > ('telegram' === $channel ? 4096 : 4000)) {
            return 'message is too long';
        }
        if ('' === \trim($plain)) {
            return 'message text is empty';
        }
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
