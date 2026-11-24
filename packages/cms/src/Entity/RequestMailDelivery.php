<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable SMTP checkpoint; the key hashes the request and recipient, never the message contents.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_request_mail_delivery')]
class RequestMailDelivery
{
    #[ORM\Id]
    #[ORM\Column(name: 'delivery_key', type: Types::STRING, length: 64)]
    public string $deliveryKey;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $state;
}
