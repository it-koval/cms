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

    /** @var array<string, array{state: string, error: ?string, job?: string, phase?: string, lease?: int, resend?: bool, parts?: array<string, array{hash: string, state: string}>, texts?: non-empty-list<string>, announcements?: list<array{delivered: bool, cancelled: bool, error: ?string}>, announcementRetryAt?: int}> */
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
        $jobs = $this->channels;
        if (self::DELIVERY_PENDING === $state) {
            unset($states[$channel]);
        } else {
            $states[$channel] = ['state' => $state, 'error' => self::DELIVERY_FAILED === $state ? $error : null];
        }
        $this->channels = [];
        $errors = [];
        $sent = false;
        foreach (self::CHANNELS as $key => $name) {
            $metadata = \array_diff_key($jobs[$key] ?? [], ['state' => true, 'error' => true]);
            if (!isset($states[$key])) {
                if ([] !== $metadata) {
                    $this->channels[$key] = ['state' => self::DELIVERY_PENDING, 'error' => null] + $metadata;
                }
                continue;
            }
            $this->channels[$key] = $states[$key] + $metadata;
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

    /** Durable job identity lives beside the historical state/error; old rows need no conversion. */
    public function channelJob(string $channel): ?string
    {
        return $this->channels[$channel]['job'] ?? null;
    }

    public function channelJobPhase(string $channel): ?string
    {
        return $this->channels[$channel]['phase'] ?? null;
    }

    public function channelJobLease(string $channel): int
    {
        return $this->channels[$channel]['lease'] ?? 0;
    }

    public function markChannelJob(string $channel, string $job, string $phase, int $lease = 0, bool $preserveProgress = false): void
    {
        // Materialize the legacy aggregate before adding job metadata for another channel.
        $states = $this->channelStates();
        foreach ($states as $key => $state) {
            $this->channels[$key] = ($this->channels[$key] ?? []) + $state;
        }
        if (!$preserveProgress && $job !== $this->channelJob($channel) && !$this->hasUnresolvedChannelParts($channel)) {
            unset($this->channels[$channel]['parts'], $this->channels[$channel]['resend'], $this->channels[$channel]['texts']);
        }
        $this->channels[$channel] = \array_replace($this->channels[$channel] ?? ['state' => self::DELIVERY_PENDING, 'error' => null], ['job' => $job, 'phase' => $phase, 'lease' => $lease]);
    }

    /** @return array<string, array{hash: string, state: string}> */
    public function channelParts(string $channel): array
    {
        return $this->channels[$channel]['parts'] ?? [];
    }

    public function hasUnresolvedChannelParts(string $channel): bool
    {
        foreach ($this->channelParts($channel) as $part) {
            if ('sending' === $part['state']) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-list<string>|null */
    public function channelTexts(string $channel): ?array
    {
        return $this->channels[$channel]['texts'] ?? null;
    }

    /** @param non-empty-list<string> $texts */
    public function markChannelTexts(string $channel, array $texts): void
    {
        $this->channels[$channel]['texts'] = $texts;
    }

    public function channelResend(string $channel): ?bool
    {
        return $this->channels[$channel]['resend'] ?? null;
    }

    public function markChannelResend(string $channel, bool $resend): void
    {
        $this->channels[$channel]['resend'] = $resend;
    }

    /** @param array{hash: string, state: string}|null $part */
    public function markChannelPart(string $channel, string $key, ?array $part): void
    {
        if (null === $part) {
            unset($this->channels[$channel]['parts'][$key]);

            return;
        }
        $this->channels[$channel]['parts'][$key] = $part;
    }

    /** @return array{delivered: bool, cancelled: bool, error: ?string}|null */
    public function channelAnnouncement(string $channel): ?array
    {
        return $this->channels[$channel]['announcements'][0] ?? null;
    }

    /** @param array{delivered: bool, cancelled: bool, error: ?string} $announcement */
    public function enqueueChannelAnnouncement(string $channel, array $announcement): void
    {
        $this->channels[$channel] ??= ['state' => self::DELIVERY_PENDING, 'error' => null];
        $this->channels[$channel]['announcements'][] = $announcement;
    }

    public function acknowledgeChannelAnnouncement(string $channel): void
    {
        $pending = $this->channels[$channel]['announcements'] ?? [];
        \array_shift($pending);
        unset($this->channels[$channel]['announcementRetryAt']);
        if ([] === $pending) {
            unset($this->channels[$channel]['announcements']);
        } else {
            $this->channels[$channel]['announcements'] = $pending;
        }
    }

    public function deferChannelAnnouncement(string $channel, int $retryAt): void
    {
        if (null !== $this->channelAnnouncement($channel)) {
            $this->channels[$channel]['announcementRetryAt'] = $retryAt;
        }
    }

    /** @return list<string> */
    public function queuedChannels(): array
    {
        return \array_values(\array_filter(\array_keys(self::CHANNELS), fn (string $channel): bool => \in_array($this->channelJobPhase($channel), ['queued', 'processing', 'retry'], true) && $this->channelJobLease($channel) > \time()));
    }
}
