<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\RequestRepository;

/** A request (заявка) from a form of the public site. */
#[ORM\Entity(repositoryClass: RequestRepository::class)]
#[ORM\Table(name: 'itk_request')]
#[ORM\Index(name: 'itk_request_created_idx', columns: ['created_at'])]
class Request
{
    public const WORK_NEW = 'new';
    public const WORK_IN_WORK = 'in_work';
    public const WORK_CLOSED = 'closed';
    public const WORK_SPAM = 'spam';
    public const WORK_STATUSES = [self::WORK_NEW, self::WORK_IN_WORK, self::WORK_CLOSED, self::WORK_SPAM];

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_MAX = 'max';
    /** The messengers a request is delivered to, in the order they are shown. */
    public const CHANNELS = [self::CHANNEL_TELEGRAM => 'Telegram', self::CHANNEL_MAX => 'MAX'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    /** Key of the form in the schema. */
    #[ORM\Column(name: 'form_key', type: Types::STRING, length: 64)]
    public string $form;

    /** @var array<string, mixed> everything the visitor sent (validated fields) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $data = [];

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    public ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    public ?string $city = null;

    /** Private note of the staff. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $work = self::WORK_NEW;

    /** Delivery to all the messengers together: «failed» when any of them failed, else «sent» when any got it, else «pending». Written by markChannel(). */
    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $delivery = self::DELIVERY_PENDING;

    /** The errors of the failed messengers, one per line («Telegram: …»). Written by markChannel(). */
    #[ORM\Column(name: 'delivery_error', type: Types::TEXT, nullable: true)]
    public ?string $deliveryError = null;

    /** @var array<string, array{state: string, error: ?string}> the delivery to each messenger that is not «pending»: channel => state and error */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $channels = [];

    #[ORM\Column(name: 'source_url', type: Types::STRING, length: 512, nullable: true)]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale = 'ru';

    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    public ?string $ipHash = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $form)
    {
        $this->form = $form;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** «pending», «sent» or «failed» of one messenger. */
    public function channelState(string $channel): string
    {
        return $this->channelStates()[$channel]['state'] ?? self::DELIVERY_PENDING;
    }

    public function channelError(string $channel): ?string
    {
        return $this->channelStates()[$channel]['error'] ?? null;
    }

    /**
     * Records the outcome for one messenger and brings `delivery` and `deliveryError` in line with all of them.
     *
     * @param self::DELIVERY_* $state
     */
    public function markChannel(string $channel, string $state, ?string $error = null): void
    {
        if (!isset(self::CHANNELS[$channel])) {
            throw new \InvalidArgumentException(\sprintf('Unknown delivery channel "%s".', $channel));
        }
        $states = $this->channelStates();
        if (self::DELIVERY_PENDING === $state) {
            unset($states[$channel]);
        } else {
            $states[$channel] = ['state' => $state, 'error' => self::DELIVERY_FAILED === $state ? $error : null];
        }
        $this->channels = [];
        $errors = [];
        $sent = false;
        foreach (self::CHANNELS as $key => $name) {
            if (!isset($states[$key])) {
                continue;
            }
            $this->channels[$key] = $states[$key];
            $sent = $sent || self::DELIVERY_SENT === $states[$key]['state'];
            if (self::DELIVERY_FAILED === $states[$key]['state']) {
                $errors[] = $name . ': ' . $states[$key]['error'];
            }
        }
        $this->delivery = [] !== $errors ? self::DELIVERY_FAILED : ($sent ? self::DELIVERY_SENT : self::DELIVERY_PENDING);
        $this->deliveryError = [] !== $errors ? \implode("\n", $errors) : null;
    }

    /**
     * What is known per messenger. A request whose channels were never written (it is older than they are, or code set `delivery` itself)
     * has one delivery, and it was Telegram's.
     *
     * @return array<string, array{state: string, error: ?string}>
     */
    public function channelStates(): array
    {
        if ([] === $this->channels && self::DELIVERY_PENDING !== $this->delivery) {
            return [self::CHANNEL_TELEGRAM => ['state' => $this->delivery, 'error' => $this->deliveryError]];
        }

        $ordered = [];
        foreach (\array_keys(self::CHANNELS) as $key) {
            if (isset($this->channels[$key])) {
                $ordered[$key] = ['state' => $this->channels[$key]['state'], 'error' => $this->channels[$key]['error'] ?? null];
            }
        }

        return $ordered;
    }
}
