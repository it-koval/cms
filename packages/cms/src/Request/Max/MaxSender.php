<?php

declare(strict_types=1);

namespace Itk\Cms\Request\Max;

/**
 * What the CMS needs from MAX: say whether there is a bot to send with, and send one text (MAX HTML) to one chat.
 * The one implementation, MaxApiSender, talks to the Bot API through the bundle yuri-kovalev/max-bot-symfony-bundle; the tests of a site replace it.
 */
interface MaxSender
{
    /** Is there a MAX bot to send with: the client is installed and has an access token? */
    public function isConnected(): bool;

    /**
     * @param int|string $chatId the id of a chat of the bot (an integer, a group has a negative one)
     * @param string $html the text in the HTML of MAX; longer than the limit of MAX it is cut (MaxNotifier sends a long request as several texts)
     *
     * @throws MaxDeliveryException when MAX is not connected or did not take the message; the message of the exception never carries the token
     */
    public function send(int|string $chatId, string $html): void;
}
