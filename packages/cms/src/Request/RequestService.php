<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Repository\RequestRepository;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Requests (заявки): accepting them from the site forms, the staff's work on them, delivery to the messengers (Telegram, MAX).
 *
 * @internal
 */
final class RequestService
{
    /** The effective character limit for a field without max(). */
    public const MAX_LENGTH = 1000;
    public const MAX_FIELDS = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestRepository $requests,
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly TelegramNotifier $telegram,
        private readonly MaxNotifier $max,
        private readonly RequestDeliverer $deliverer,
        private readonly NotifierInterface $notifier,
        private readonly MessageBusInterface $bus,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ?RequestQueue $queue = null,
    ) {
    }

    /**
     * Validates the input against the form of the schema, saves the request and queues the messages to the messengers.
     *
     * @param array<string, mixed> $input what the visitor sent
     * @param array{ip?: ?string, url?: ?string, locale?: ?string} $meta
     */
    public function submit(string $form, array $input, array $meta = []): Request
    {
        $def = $this->schema->form($form) ?? throw ContentException::notFound(I18n::t('http.request_no_form'));
        self::validateInput(\array_intersect_key($input, \array_fill_keys(\array_map(static fn (FieldInterface $field): string => $field->key(), $def->fieldList()), true)));
        if (!$this->settings->acceptsRequests()) {
            throw ContentException::forbidden(I18n::t('http.request_intake_off'));
        }
        $data = [];
        $errors = [];
        foreach ($def->fieldList() as $f) {
            $raw = $input[$f->key()] ?? null;
            $value = $this->clean($f, $raw);
            $empty = null === $value || '' === $value || false === $value;
            if ($f->isRequired() && $empty) {
                $errors[$f->key()] = I18n::t('http.request_field_required', ['label' => $f->label()]);
                continue;
            }
            if ($empty) {
                continue;
            }
            if (\is_string($value) && null === $f->maxLength() && \mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$f->key()] = I18n::t('http.request_field_too_long', ['label' => $f->label(), 'count' => self::MAX_LENGTH]);
                continue;
            }
            $problems = \Itk\Cms\Content\FieldValueRules::problems($f, $value, plainText: true);
            if ([] !== $problems) {
                $errors[$f->key()] = 'max' === $problems[0]['code']
                    ? I18n::t('http.request_field_too_long', ['label' => $f->label(), 'count' => $f->maxLength()])
                    : $problems[0]['message'];
                continue;
            }
            $data[$f->key()] = $value;
        }
        if ([] !== $errors) {
            throw ContentException::invalid((string) \reset($errors), $errors);
        }

        $r = new Request($form);
        $r->data = $data;
        $r->phone = isset($data['phone']) ? \mb_substr((string) $data['phone'], 0, 32) : null;
        $r->name = isset($data['name']) ? \mb_substr((string) $data['name'], 0, 120) : null;
        $r->city = isset($data['city']) ? \mb_substr((string) $data['city'], 0, 120) : null;
        $r->sourceUrl = isset($meta['url']) ? \mb_substr((string) $meta['url'], 0, 512) : null;
        $r->locale = $meta['locale'] ?? $this->mainLocale;
        $r->ipHash = !empty($meta['ip']) && '' !== \trim($this->secret) ? \hash_hmac('sha256', (string) $meta['ip'], $this->secret) : null; // no key, no hash: with an empty one every address could be guessed
        $this->em->wrapInTransaction(function () use ($r): void {
            $this->em->persist($r);
            $this->em->flush();

            // the bell of the admin and the messenger deliveries (TelegramChannel and MaxChannel queue them; a queue that is down marks the request «не доставлена» for that messenger)
            $this->notifier->notify(Notification::translated(
                type: Notification::REQUEST_NEW,
                titleKey: 'i18n.notification_request_new',
                titleParams: ['id' => $r->id],
                body: $this->summary($r),
                link: '/requests/' . $r->id,
                audience: 'requests',
                payload: ['requestId' => $r->id, 'form' => $r->form],
            ));
        });
        // An ambient transaction defers publication to the worker; an owned transaction is already committed.
        $this->queue?->recover(500, (int) $r->id);

        return $r;
    }

    /** One line for the bell: the form, who and where from. */
    private function summary(Request $r): string
    {
        $parts = [$this->schema->form($r->form)?->formTitle() ?? $r->form];
        foreach ([$r->name, $r->phone, $r->city] as $v) {
            if (null !== $v && '' !== $v) {
                $parts[] = $v;
            }
        }

        return \implode(' · ', $parts);
    }

    public function find(int|string $id): Request
    {
        return $this->requests->find((int) $id) ?? throw ContentException::notFound(I18n::t('http.request_not_found'));
    }

    /**
     * @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter
     *
     * @return list<Request>
     */
    public function search(array $filter = [], ?int $limit = null): array
    {
        return $this->requests->search($this->searchFilter($filter), $limit);
    }

    /**
     * @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter
     *
     * @return array{items: list<Request>, total: int, nextCursor: ?string, previousCursor: ?string}
     */
    public function page(array $filter, int $offset, int $limit, string $cursor = ''): array
    {
        return $this->requests->page($this->searchFilter($filter), $offset, $limit, $cursor);
    }

    /**
     * @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter
     *
     * @return array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string}
     */
    private function searchFilter(array $filter): array
    {
        if (isset($filter['status'])) {
            $filter['status'] = $this->work((string) $filter['status']);
        }

        return $filter;
    }

    /** @return array{total: int, new: int, failed: int} */
    public function counts(): array
    {
        return $this->requests->counts();
    }

    /** @return list<string> titles of the forms for the filter */
    public function forms(): array
    {
        return \array_values(\array_map(static fn ($f): string => $f->formTitle(), $this->schema->forms()));
    }

    /** Work status and the staff's note. 'progress' is accepted as 'in_work'. */
    public function update(int|string $id, ?string $work = null, ?string $comment = null): Request
    {
        $r = $this->find($id);
        if (null !== $work) {
            $w = $this->work($work);
            if (!\in_array($w, Request::WORK_STATUSES, true)) {
                throw ContentException::invalid(I18n::t('http.request_bad_status'));
            }
            $r->work = $w;
        }
        if (null !== $comment) {
            if (\mb_strlen($comment) > 500) {
                throw ContentException::invalid(I18n::t('http.request_note_too_long'));
            }
            $r->comment = '' === \trim($comment) ? null : \trim($comment);
        }
        $this->em->flush();

        return $r;
    }

    /**
     * Sends the request again (up to 20 parts now; longer messages are queued): to the messenger asked for, else to every messenger that failed,
     * else (none failed) to every messenger that is on.
     * A messenger asked for by name is sent «again» only after a failure. One that never got the request (the «Отправить в MAX» button)
     * gets a first send, with no «delivered again» line, and is skipped if a worker delivered it after the drawer was opened.
     *
     * @param string|null $channel Request::CHANNEL_TELEGRAM or Request::CHANNEL_MAX
     */
    public function resend(int|string $id, ?string $channel = null): Request
    {
        return $this->resendResult($id, $channel)['request'];
    }

    /** @return array{request: Request, queuedChannels: list<string>, outcomes: array<string, array{state: string, error: ?string}>, status: int} */
    public function resendResult(int|string $id, ?string $channel = null): array
    {
        $r = $this->find($id);
        if (null !== $channel && !isset(Request::CHANNELS[$channel])) {
            throw ContentException::invalid(I18n::t('http.request_bad_channel'));
        }
        $outcomes = [];
        foreach (null !== $channel ? [$channel] : $this->channelsToResend($r) as $one) {
            $wasFailed = Request::DELIVERY_FAILED === $r->channelState($one);
            $again = null === $channel || $wasFailed;
            if ($this->deliverer->partCount($r, $one, $again) <= 20) {
                $this->deliverer->deliverSynchronously((int) $r->id, $one, $again, static function (EntityManagerInterface $manager, string $job) use ($r, $one, $again): void {
                    // A process dying after the committed reservation leaves a delayed, replayable intent.
                    RequestQueue::stageJob($manager, new SendRequestNotification((int) $r->id, $again, $one, $job), 30);
                }, onlyIfFailed: $wasFailed);
            } else {
                $entry = null;
                $this->deliverer->reserveForDispatch((int) $r->id, $one, $again, static function (EntityManagerInterface $manager, string $job) use ($r, $one, $again, &$entry): void {
                    $entry = RequestQueue::stageJob($manager, new SendRequestNotification((int) $r->id, $again, $one, $job));
                }, onlyIfFailed: $wasFailed);
                if (null !== $entry) {
                    ($this->queue ?? new RequestQueue($this->em, $this->bus, new \Psr\Log\NullLogger()))->publish($entry);
                }
            }
            $this->em->refresh($r);
            $outcomes[$one] = ['state' => \in_array($one, $r->queuedChannels(), true) ? 'queued' : $r->channelState($one), 'error' => $r->channelError($one)];
        }
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->refresh($r); // delivery committed on its own connection
        }

        $selectedQueued = \in_array('queued', \array_column($outcomes, 'state'), true);
        $status = $selectedQueued ? 202 : 200;

        return ['request' => $r, 'queuedChannels' => $r->queuedChannels(), 'outcomes' => $outcomes, 'status' => $status];
    }

    /** @return list<string> */
    private function channelsToResend(Request $r): array
    {
        $failed = $this->failedChannels($r);
        if ([] !== $failed) {
            return $failed;
        }
        $active = \array_keys(\array_filter(Request::CHANNELS, fn (string $name, string $key): bool => $this->isActive($key), \ARRAY_FILTER_USE_BOTH));

        return [] !== $active ? $active : \array_keys(Request::CHANNELS); // nothing is on: each one settles to «pending» as it always did
    }

    /** @return list<string> the messengers the request was not delivered to */
    private function failedChannels(Request $r): array
    {
        return \array_keys(\array_filter($r->channelStates(), static fn (array $c): bool => Request::DELIVERY_FAILED === $c['state']));
    }

    private function isActive(string $channel): bool
    {
        return Request::CHANNEL_MAX === $channel ? $this->max->isActive() : $this->telegram->isActive();
    }

    /** Queues every request whose delivery failed, to the messengers it failed for. @return int number of requests still waiting */
    public function requeueFailed(): int
    {
        return $this->requeueFailedResult()['queued'];
    }

    /**
     * Queue counts requests; outcomes and skipped uncertain channels count channel deliveries. A synchronous bus can finish before publication returns.
     *
     * @return array{queued: int, sent: int, failed: int, pending: int, uncertain: int}
     */
    public function requeueFailedResult(): array
    {
        $result = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'uncertain' => 0];
        foreach ($this->requests->findBy(['delivery' => Request::DELIVERY_FAILED]) as $r) {
            $waiting = false;
            foreach ($this->failedChannels($r) as $channel) {
                if ('uncertain' === $r->channelJobPhase($channel)) {
                    ++$result['uncertain'];
                    continue;
                }
                $entry = null;
                $this->deliverer->reserveForDispatch((int) $r->id, $channel, true, static function (EntityManagerInterface $manager, string $job) use ($r, $channel, &$entry): void {
                    $entry = RequestQueue::stageJob($manager, new SendRequestNotification((int) $r->id, true, $channel, $job));
                }, onlyIfFailed: true);
                if (null !== $entry) {
                    ($this->queue ?? new RequestQueue($this->em, $this->bus, new \Psr\Log\NullLogger()))->publish($entry);
                    $this->em->refresh($r);
                    if (\in_array($channel, $r->queuedChannels(), true)) {
                        $waiting = true;
                    } else {
                        $state = 'uncertain' === $r->channelJobPhase($channel) ? 'uncertain' : $r->channelState($channel);
                        ++$result[$state];
                    }
                } else {
                    $this->em->refresh($r);
                    if ('uncertain' === $r->channelJobPhase($channel)) {
                        ++$result['uncertain'];
                    }
                }
            }
            $result['queued'] += (int) $waiting;
        }

        return $result;
    }

    /**
     * The delivery to each messenger: the ones that are on for the site and the ones that have an outcome on this request, Telegram first.
     *
     * @return list<array{channel: string, state: string, error: ?string}>
     */
    public function channels(Request $r): array
    {
        $out = [];
        foreach (Request::CHANNELS as $key => $name) {
            $state = $r->channelState($key);
            if (Request::DELIVERY_PENDING === $state && !$this->isActive($key)) {
                continue;
            }
            $out[] = ['channel' => $key, 'state' => $state, 'error' => $r->channelError($key)];
        }

        return $out;
    }

    /**
     * The messengers that never got this request (no state, so pending) and can take it now: the admin offers «Отправить в …» for them. Failed ones keep «Отправить ещё раз».
     *
     * @return list<string>
     */
    public function sendable(Request $r): array
    {
        $states = $r->channelStates();
        $out = [];
        foreach (Request::CHANNELS as $key => $name) {
            $notifier = Request::CHANNEL_MAX === $key ? $this->max : $this->telegram;
            if (Request::DELIVERY_PENDING === ($states[$key]['state'] ?? Request::DELIVERY_PENDING) && !\in_array($key, $r->queuedChannels(), true) && $notifier->reaches($r->form)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * A request as the admin's «Заявки» screen reads it.
     *
     * @return array<string, mixed>
     */
    public function present(Request $r): array
    {
        $form = $this->schema->form($r->form);
        $noteKeys = ['comment', 'message', 'note', 'text'];
        $note = '';
        foreach ($noteKeys as $k) {
            if (!empty($r->data[$k]) && \is_scalar($r->data[$k])) {
                $note = (string) $r->data[$k];
                break;
            }
        }
        $at = $r->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'); // the screen shows it in the time of the person
        $log = [];
        foreach ($r->channelStates() as $key => $c) {
            if (Request::DELIVERY_SENT === $c['state']) {
                $log[] = ['ok', I18n::t(Request::CHANNEL_MAX === $key ? 'http.request_delivered_max' : 'http.request_delivered'), $at];
            } elseif (Request::DELIVERY_FAILED === $c['state']) {
                $log[] = ['fail', Request::CHANNELS[$key] . ': ' . $c['error'], $at];
            }
        }
        if ([] === $log && !$this->telegram->isConnected() && !$this->max->isConnected()) {
            $log[] = ['ok', I18n::t('http.request_saved_here'), $at];
        }

        return [
            'id' => $r->id,
            'dt' => $r->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'locale' => $r->locale,
            'form' => $r->form,
            'place' => $form?->formTitle() ?? $r->form,
            'phone' => (string) $r->phone,
            'name' => (string) $r->name,
            'city' => (string) $r->city,
            'note' => $note,
            'data' => $r->data,
            'st' => Request::DELIVERY_FAILED === $r->delivery ? 'failed' : 'delivered',
            'delivery' => $r->delivery,
            'deliveryError' => $r->deliveryError,
            'channels' => $this->channels($r),
            'queuedChannels' => $r->queuedChannels(),
            'sendable' => $this->sendable($r),
            'work' => Request::WORK_IN_WORK === $r->work ? 'progress' : $r->work,
            'comment' => (string) $r->comment,
            'sourceUrl' => $r->sourceUrl,
            'log' => $log,
        ];
    }

    /**
     * One request as its card reads it: present() with the phone, the name and the city whole.
     * A list keeps the short columns: one long value would push its other columns off the screen.
     *
     * @return array<string, mixed>
     */
    public function card(Request $r): array
    {
        return \array_replace($this->present($r), [
            'phone' => self::whole($r->phone, $r->data['phone'] ?? null),
            'name' => self::whole($r->name, $r->data['name'] ?? null),
            'city' => self::whole($r->city, $r->data['city'] ?? null),
        ]);
    }

    /** A column holds the start of a long value (phone 32, name and city 120 characters), `data` holds all of it. */
    private static function whole(?string $column, mixed $sent): string
    {
        return null !== $column && \is_string($sent) && \str_starts_with($sent, $column) ? $sent : (string) $column;
    }

    private function work(string $w): string
    {
        return 'progress' === $w ? Request::WORK_IN_WORK : $w;
    }

    /** JSON validates every member; ordinary POST and direct callers validate their declared form fields.
     * @param array<string, mixed> $input
     */
    public static function validateInput(array $input): void
    {
        if (\count($input) > self::MAX_FIELDS) {
            throw ContentException::invalid(I18n::t('http.request_bad_input'));
        }
        foreach ($input as $value) {
            if (null !== $value && !\is_scalar($value)) {
                throw ContentException::invalid(I18n::t('http.request_bad_input'));
            }
        }
    }

    private function clean(FieldInterface $f, mixed $raw): string|bool|null
    {
        if ('toggle' === $f->type()) {
            return \in_array($raw, [true, 1, '1', 'on', 'true', 'yes'], true);
        }
        if (!\is_scalar($raw)) {
            return null;
        }

        // Explicit max() is checked on the whole cleaned text; an accepted value is never silently shortened.
        return PlainText::from((string) $raw, null === $f->maxLength() ? self::MAX_LENGTH + 1 : \PHP_INT_MAX);
    }
}
