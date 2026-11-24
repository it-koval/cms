<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\Max\MaxDeliveryException;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use Mj4444\SimpleHttpClient\Contracts\HttpClientInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpRequestInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpResponseInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The adapter over the real client of max-messenger-bot/max-bot-api-php: what goes over the wire, what is cut, what never shows. */
final class MaxApiSenderTest extends TestCase
{
    private const TOKEN = 'tok-3n-SECRET-value';

    /** @var list<HttpRequestInterface<HttpResponseInterface<*>>> */
    public array $requests = [];

    /** A wire that answers every request with the given status and body and keeps the requests. */
    private function wire(int $status = 200, string $body = '{"message": {}}'): HttpClientInterface
    {
        $test = $this;

        return new class($status, $body, $test) implements HttpClientInterface {
            public function __construct(private readonly int $status, private readonly string $body, private readonly MaxApiSenderTest $test)
            {
            }

            public function request(HttpRequestInterface $request): HttpResponseInterface
            {
                $this->test->requests[] = $request;

                return $request->makeResponse($this->status, $request->getUrl(), $request->getUrl(), null, [], 'application/json', $this->body);
            }
        };
    }

    private function sender(?HttpClientInterface $wire, ?string $token = self::TOKEN): MaxApiSender
    {
        $config = (new MaxApiConfig($token))->setHttpClient($wire)->setRetryAttempts([]);

        return new MaxApiSender(static fn (): MaxApiClient => new MaxApiClient($config), static fn (): MaxApiConfig => $config, new NullLogger());
    }

    #[Test]
    public function aMessageGoesToTheChatAsHtmlWithoutALinkPreview(): void
    {
        $sender = $this->sender($this->wire());

        self::assertTrue($sender->isConnected());
        $sender->send('-70123456789012', 'Заявка <b>1</b>');

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/messages?', $request->getUrl());
        self::assertStringContainsString('chat_id=-70123456789012', $request->getUrl());
        self::assertStringContainsString('disable_link_preview=true', $request->getUrl());
        self::assertContains('Authorization: ' . self::TOKEN, $request->getHeaders());
        $body = (array) \json_decode((string) $request->getBody()?->getBody(), true);
        self::assertSame('Заявка <b>1</b>', $body['text']);
        self::assertSame('html', $body['format']);
    }

    #[Test]
    public function aLongTextIsCutToTheLimitOfMaxAndNeverInTheMiddleOfAnEntityOrATag(): void
    {
        self::assertSame(MaxApiSender::TEXT_LIMIT, \mb_strlen(MaxApiSender::cut(\str_repeat('я', 5000))));
        $cut = MaxApiSender::cut(\str_repeat('a', MaxApiSender::TEXT_LIMIT - 2) . '&amp;tail');
        self::assertSame(\str_repeat('a', MaxApiSender::TEXT_LIMIT - 2), $cut, 'the half of &amp; is dropped');
        self::assertSame('short', MaxApiSender::cut('short'));
        $pad = \str_repeat('a', MaxApiSender::TEXT_LIMIT - 8);
        self::assertSame($pad, MaxApiSender::cut($pad . '<a href="/x">link</a>'), 'the half of a tag is dropped');
        self::assertSame($pad, MaxApiSender::cut($pad . '<a href="?a=1&amp;b=2">link</a>'), 'also inside an attribute');
        self::assertSame($pad . '<b>x</b>', MaxApiSender::cut($pad . '<b>x</b>' . \str_repeat('z', 50)), 'a whole tag stays');

        $sender = $this->sender($this->wire());
        $sender->send(1, \str_repeat('я', 6000));
        self::assertSame(MaxApiSender::TEXT_LIMIT, \mb_strlen((string) \json_decode((string) $this->requests[0]->getBody()?->getBody(), true)['text']));
    }

    #[Test]
    public function aRefusalIsOneExceptionWithoutTheToken(): void
    {
        $sender = $this->sender($this->wire(403, '{"code": "access.denied", "message": "Bot is not a member of the chat ' . self::TOKEN . '"}'));

        try {
            $sender->send('-70123456789012', 'text');
            self::fail('a refused message must throw');
        } catch (MaxDeliveryException $e) {
            self::assertStringContainsString('Bot is not a member', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    #[Test]
    public function theServerClientDoesNotRepeatAnAmbiguousPost(): void
    {
        $config = (new MaxApiConfig(self::TOKEN))->setHttpClient($this->wire(503, '{"code":"unavailable","message":"unavailable"}'))->setRetryAttempts([1]);
        $sender = new MaxApiSender(static fn (): MaxApiClient => new MaxApiClient($config), static fn (): MaxApiConfig => $config, new NullLogger());
        try {
            $sender->send(1, 'text');
            self::fail('an uncertain response must be surfaced');
        } catch (\Itk\Cms\Request\DeliveryUncertainException) {
            self::assertCount(1, $this->requests);
            self::assertSame([1], $config->getRetryAttempts());
        }
    }

    #[Test]
    public function aRefusalHidesEncodedTokenFormsAsWellAsTheHeaderValue(): void
    {
        $token = 'secret/+value:with-punctuation';
        $forms = [$token, \rawurlencode($token), \urlencode($token), \base64_encode($token)];
        $body = (string) \json_encode(['code' => 'access.denied', 'message' => 'Refused ' . \implode(' ', $forms)]);
        $sender = $this->sender($this->wire(403, $body), $token);

        try {
            $sender->send(1, 'text');
            self::fail('the refusal must throw');
        } catch (MaxDeliveryException $e) {
            self::assertStringContainsString('Refused', $e->getMessage());
            foreach ($forms as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    #[Test]
    public function aClientWithoutATokenIsNotConnectedAndSendsNothing(): void
    {
        foreach ([null, ''] as $token) {
            $sender = $this->sender($this->wire(), $token);

            self::assertFalse($sender->isConnected());
            try {
                $sender->send(1, 'text');
                self::fail('no token: nothing may be sent');
            } catch (MaxDeliveryException) {
                self::assertSame([], $this->requests);
            }
        }
    }

    #[Test]
    public function withoutTheBundleThereIsNoClientAndMaxIsNotConnected(): void
    {
        $sender = new MaxApiSender(null, null, new NullLogger());

        self::assertFalse($sender->isConnected());
        $this->expectException(MaxDeliveryException::class);
        $sender->send(1, 'text');
    }

    #[Test]
    public function aClientThatCannotBeBuiltIsNotConnected(): void
    {
        $sender = new MaxApiSender(static fn (): never => throw new \RuntimeException('no token'), null, new NullLogger());

        self::assertFalse($sender->isConnected());
    }

    #[Test]
    public function aClientAndAConfigThatBothThrowAreNotConnectedAndDoNotThrow(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . \json_encode($context);
            }
        };
        $boom = static fn (): never => throw new \RuntimeException('Environment variable not found');
        $sender = new MaxApiSender($boom, $boom, $logger);

        self::assertFalse($sender->isConnected());
        self::assertCount(1, $logger->lines);
        self::assertStringStartsWith('warning', $logger->lines[0]);
    }

    #[Test]
    public function aChatIdThatIsNoWholeNumberIsRefusedBeforeTheWire(): void
    {
        $sender = $this->sender($this->wire());

        foreach (['@channel', 'abc', '1.5', '99999999999999999999'] as $bad) {
            try {
                $sender->send($bad, 'text');
                self::fail($bad);
            } catch (MaxDeliveryException) {
            }
        }
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function accidentallyPastingTheTokenAsAChatIdDoesNotEchoItInTheError(): void
    {
        $sender = $this->sender($this->wire());

        try {
            $sender->send(self::TOKEN, 'text');
            self::fail('a token is not a chat id');
        } catch (MaxDeliveryException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertSame([], $this->requests);
        }
    }
}
