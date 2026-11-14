<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The delivery of a request to each messenger, and the sum of them that the list, the counts and the health screen read. */
final class RequestChannelsTest extends TestCase
{
    #[Test]
    public function aNewRequestIsPendingEverywhere(): void
    {
        $r = new Request('call');

        self::assertSame('pending', $r->delivery);
        self::assertNull($r->deliveryError);
        self::assertSame('pending', $r->channelState(Request::CHANNEL_TELEGRAM));
        self::assertSame('pending', $r->channelState(Request::CHANNEL_MAX));
        self::assertSame([], $r->channelStates());
    }

    #[Test]
    public function oneFailedMessengerFailsTheSumAndTheErrorsAreLabelled(): void
    {
        $r = new Request('call');
        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_SENT);
        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_FAILED, 'chat 1: no access');

        self::assertSame('failed', $r->delivery);
        self::assertSame('MAX: chat 1: no access', $r->deliveryError);
        self::assertSame('sent', $r->channelState(Request::CHANNEL_TELEGRAM));
        self::assertSame('failed', $r->channelState(Request::CHANNEL_MAX));
        self::assertSame('chat 1: no access', $r->channelError(Request::CHANNEL_MAX));

        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_FAILED, 'bad chat');
        self::assertSame("Telegram: bad chat\nMAX: chat 1: no access", $r->deliveryError, 'Telegram first, as everywhere');
    }

    #[Test]
    public function theSumIsSentWhenAnyMessengerGotItAndNoneFailed(): void
    {
        $r = new Request('call');
        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_SENT);
        self::assertSame('sent', $r->delivery, 'the other messenger is off: the one that is on decides');
        self::assertNull($r->deliveryError);

        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_FAILED, 'x');
        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_SENT);
        self::assertSame('sent', $r->delivery);
        self::assertNull($r->deliveryError, 'a repaired delivery takes its error with it');
    }

    #[Test]
    public function pendingTakesAMessengerBackOutOfTheSum(): void
    {
        $r = new Request('call');
        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_FAILED, 'x');
        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_SENT);
        $r->markChannel(Request::CHANNEL_TELEGRAM, Request::DELIVERY_PENDING);

        self::assertSame('sent', $r->delivery);
        self::assertSame([Request::CHANNEL_MAX => ['state' => 'sent', 'error' => null]], $r->channelStates());

        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_PENDING);
        self::assertSame('pending', $r->delivery);
        self::assertSame([], $r->channelStates());
    }

    #[Test]
    public function aDeliveryWrittenWithoutChannelsIsTelegrams(): void
    {
        $r = new Request('call');
        $r->delivery = Request::DELIVERY_FAILED;
        $r->deliveryError = 'old error';

        self::assertSame('failed', $r->channelState(Request::CHANNEL_TELEGRAM));
        self::assertSame('old error', $r->channelError(Request::CHANNEL_TELEGRAM));
        self::assertSame('pending', $r->channelState(Request::CHANNEL_MAX));

        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_SENT);
        self::assertSame('failed', $r->delivery, 'the Telegram failure that was there stays');
        self::assertSame('Telegram: old error', $r->deliveryError);
    }

    #[Test]
    public function anUnknownMessengerIsAMistakeOfTheCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Request('call'))->markChannel('signal', Request::DELIVERY_SENT);
    }
}
