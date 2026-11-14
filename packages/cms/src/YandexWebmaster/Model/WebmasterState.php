<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * Where the connection to Yandex Webmaster stands; the value is what the admin API says (`state`).
 *
 * @internal
 */
enum WebmasterState: string
{
    /** symfony/http-client is not installed */
    case NoHttpClient = 'no_http_client';
    /** «Настройки → Сайт → адрес» is empty or not public (localhost, an IP, a reserved zone) */
    case NoAddress = 'no_address';
    case NotConnected = 'not_connected';
    /** Yandex rejected the stored token: the owner pastes a new one */
    case TokenInvalid = 'token_invalid';
    /** the ownership check is NONE or IN_PROGRESS (or the first answer has not come yet) */
    case Verifying = 'verifying';
    case VerificationFailed = 'verification_failed';
    case Ready = 'ready';
}
