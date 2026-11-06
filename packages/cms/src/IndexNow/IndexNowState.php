<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

/**
 * Whether the site tells search engines about changed pages, and if not, why (IndexNowSite::state()).
 *
 * @internal
 */
enum IndexNowState
{
    /** the toggle «IndexNow» is off */
    case Off;
    /** the setting `domain` is empty or not an address */
    case NoAddress;
    /** the address is localhost, an IP or a reserved zone: a search engine cannot fetch the key file there */
    case NotPublic;
    /** symfony/http-client is not installed */
    case NoHttpClient;
    case Ready;
}
