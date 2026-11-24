<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\MailDeliveryException;
use Itk\Cms\Mail\MailerFactory;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\SessionWork;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Настройки → Почта» (rights: area `set:mail`; by default the administrator only). Answer of GET and PUT:
 *   { enabled, host, port, encryption: 'none'|'ssl'|'starttls', username, passwordSet, fromAddress, fromName,
 *     configured, source: 'settings'|'env'|'none', envFallback, problem?, last: { okAt, error, errorAt, kind } }
 * The password is NEVER in an answer: only `passwordSet`.
 *   GET  settings/mail
 *   PUT  settings/mail { enabled?, host?, port?, encryption?, username?, password?, clearPassword?, fromAddress?, fromName? }   saved at once;
 *        an empty or missing `password` keeps the saved one, `clearPassword: true` removes it. 422 { details: { field: message } };
 *        when `host` or `username` changes, a new `password` (or `clearPassword`) must come with it: 422 { details: { password: 'Сервер изменился — введите пароль заново' } }
 *   POST settings/mail-test { to? }   sends a test letter NOW (not through the queue), to `to` or to the person at the screen → { ok, to }; 422 when mail is not configured, 502 with the cleaned reason when the server refuses, 429 after ten tests an hour for one person
 *
 * @internal
 */
#[AsController]
#[Route('/api/settings', name: 'itk_api_mail_')]
final class MailController extends AbstractApiController
{
    private const AREA = 'set:mail';

    public function __construct(
        private readonly MailSettings $settings,
        private readonly MailerFactory $factory,
        private readonly MailStatus $status,
        private readonly SiteMailer $mailer,
        private readonly MailComposer $composer,
        private readonly Access $access,
        #[Autowire(service: 'limiter.itk_mail_test')]
        private readonly RateLimiterFactoryInterface $testLimit,
        private readonly SessionWork $sessionWork,
    ) {
    }

    #[Route('/mail', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->payload());
    }

    #[Route('/mail', name: 'save', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        $this->settings->save($this->body($request));

        return $this->json($this->payload());
    }

    #[Route('/mail-test', name: 'test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        if (!$this->testLimit->create((string) $this->access->user()?->id)->consume()->isAccepted()) {
            throw new ContentException(I18n::t('http.mail_test_limit'), 429, ['code' => 'mail_test_limit']);
        }
        $to = \mb_strtolower(\trim((string) ($this->body($request)['to'] ?? '')));
        if ('' === $to) {
            $to = (string) $this->access->user()?->email;
        }
        if ('' === $to || \strlen($to) > 180 || false === \filter_var($to, \FILTER_VALIDATE_EMAIL)) {
            throw ContentException::invalid(I18n::t('http.email_typo'), ['to' => I18n::t('http.email_typo')]);
        }
        if (!$this->mailer->isConfigured()) {
            throw ContentException::invalid($this->mailer->problem() ?? I18n::t('http.mail_not_configured'), ['code' => 'mail_not_configured']);
        }
        try {
            $mail = $this->composer->test($to);
            $this->sessionWork->run($request, fn () => $this->mailer->deliver($mail));
        } catch (MailDeliveryException $e) {
            throw new ContentException(I18n::t('http.mail_not_sent', ['reason' => $e->getMessage()]), 502, ['code' => 'mail_failed']);
        }

        return $this->json(['ok' => true, 'to' => $to]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $route = $this->factory->route();
        $payload = $this->settings->view() + [
            'configured' => null !== $route,
            'source' => null === $route ? 'none' : $route->source,
            'envFallback' => $this->factory->hasEnvFallback(),
            'last' => $this->status->get(),
        ];
        if (null === $route) {
            $payload['problem'] = $this->factory->problem();
        }

        return $payload;
    }
}
