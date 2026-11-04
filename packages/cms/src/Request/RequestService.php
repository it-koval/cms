<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Repository\RequestRepository;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/** Requests (заявки): accepting them from the site forms, the staff's work on them, delivery to Telegram. */
final class RequestService
{
    public const MAX_LENGTH = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestRepository $requests,
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly TelegramNotifier $telegram,
        private readonly NotifierInterface $notifier,
        private readonly AdminLocale $locale,
        #[Autowire(service: 'messenger.bus.default')]
        private readonly MessageBusInterface $bus,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * Validates the input against the form of the schema, saves the request and queues the Telegram message.
     *
     * @param array<string, mixed> $input what the visitor sent
     * @param array{ip?: ?string, url?: ?string, locale?: ?string} $meta
     */
    public function submit(string $form, array $input, array $meta = []): Request
    {
        $def = $this->schema->form($form) ?? throw ContentException::notFound(I18n::t('http.request_no_form'));
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
            if (!$empty && \is_string($value)) {
                if (null !== $f->maxLength() && \mb_strlen($value) > $f->maxLength()) {
                    $errors[$f->key()] = I18n::t('http.request_field_too_long', ['label' => $f->label(), 'count' => $f->maxLength()]);
                    continue;
                }
                if (null !== $f->regex() && !\preg_match($f->regex(), $value)) {
                    $errors[$f->key()] = $f->regexMessage();
                    continue;
                }
            }
            if (!$empty) {
                $data[$f->key()] = $value;
            }
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
        $this->em->persist($r);
        $this->em->flush();

        // the bell of the admin and the Telegram delivery (TelegramChannel queues it; a queue that is down marks the request «не доставлена»)
        $this->notifier->notify(Notification::translated(
            type: Notification::REQUEST_NEW,
            titleKey: 'i18n.notification_request_new',
            titleParams: ['id' => $r->id],
            body: $this->summary($r),
            link: '/requests/' . $r->id,
            audience: 'requests',
            payload: ['requestId' => $r->id, 'form' => $r->form],
        ));

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
    public function search(array $filter = []): array
    {
        if (isset($filter['status'])) {
            $filter['status'] = $this->work((string) $filter['status']);
        }

        return $this->requests->search($filter);
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

    /** Sends the request to Telegram again, right now (the admin shows the result). */
    public function resend(int|string $id): Request
    {
        $r = $this->find($id);
        $this->locale->runDefault(fn (): bool => $this->telegram->notify($r, true)); // the chat reads admin_locale, not the language of the person who pressed the button
        $this->em->flush();

        return $r;
    }

    /** Queues every request whose delivery failed. @return int number queued */
    public function requeueFailed(): int
    {
        $n = 0;
        foreach ($this->requests->findBy(['delivery' => Request::DELIVERY_FAILED]) as $r) {
            $this->bus->dispatch(new SendRequestNotification((int) $r->id, true));
            ++$n;
        }

        return $n;
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
        if (Request::DELIVERY_SENT === $r->delivery) {
            $log[] = ['ok', I18n::t('http.request_delivered'), $at];
        } elseif (Request::DELIVERY_FAILED === $r->delivery) {
            $log[] = ['fail', (string) $r->deliveryError, $at];
        } elseif (!$this->telegram->isConnected()) {
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
            'work' => Request::WORK_IN_WORK === $r->work ? 'progress' : $r->work,
            'comment' => (string) $r->comment,
            'sourceUrl' => $r->sourceUrl,
            'log' => $log,
        ];
    }

    private function work(string $w): string
    {
        return 'progress' === $w ? Request::WORK_IN_WORK : $w;
    }

    private function clean(FieldInterface $f, mixed $raw): string|bool|null
    {
        if ('toggle' === $f->type()) {
            return \in_array($raw, [true, 1, '1', 'on', 'true', 'yes'], true);
        }
        if (!\is_scalar($raw)) {
            return null;
        }

        return \mb_substr(\trim(\strip_tags((string) $raw)), 0, self::MAX_LENGTH + 1);
    }
}
