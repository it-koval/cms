<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Twig\Environment;

/**
 * Writes the letters of the core: a Twig template pair (HTML and plain text) per letter, @ItkCms/email/<letter>.html.twig and .txt.twig.
 * A site changes a letter by putting a file of the same name into its own templates/email/ (the site's template wins, the bundle's one is the fallback);
 * email/layout.html.twig does the same for the frame of all letters.
 * The letters are written here, in the request, and travel to the queue finished; the worker only connects to the mail server.
 * A letter is written in the language current at the call: PasswordRecovery runs it in the language of the person, the letter about a request is always in admin_locale, the test letter is
 * in the language of the one who pressed the button. The frame takes <html lang> from there (itk_locale()).
 *
 * @internal
 */
final class MailComposer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SettingsService $settings,
        private readonly Schema $schema,
        private readonly PublicUrl $urls,
        private readonly AdminPath $admin,
        private readonly AdminLocale $locale,
    ) {
    }

    /**
     * A link to set a password: «reset» = the person forgot it, «invite» = he is new.
     *
     * @param string $valid how long it works, in words («1 час»)
     * @param ?string $loginUrl the address of the sign-in page (null = PublicUrl now)
     */
    public function passwordLink(User $user, string $kind, string $url, string $valid, ?string $loginUrl = null): SendMail
    {
        $invite = SendMail::INVITE === $kind;
        $vars = ['site_name' => $this->siteName(), 'name' => $user->name, 'email' => $user->email, 'url' => $url, 'valid' => $valid, 'login_url' => $loginUrl ?? $this->urls->to($this->admin->to())];
        $site = $vars['site_name'];
        $subject = $invite
            ? ('' !== $site ? I18n::t('http.mail_subject_invite_site', ['site' => $site]) : I18n::t('http.mail_subject_invite'))
            : ('' !== $site ? I18n::t('http.mail_subject_reset_site', ['site' => $site]) : I18n::t('http.mail_subject_reset'));

        return $this->make($user->email, $subject, $invite ? 'invite' : 'reset_password', $vars, $invite ? SendMail::INVITE : SendMail::RESET);
    }

    /** The letter about a new request: for the people of the site, in admin_locale, wherever the request came from. */
    public function newRequest(Request $r, string $to): SendMail
    {
        return $this->locale->runDefault(fn (): SendMail => $this->writeNewRequest($r, $to));
    }

    private function writeNewRequest(Request $r, string $to): SendMail
    {
        $form = $this->schema->form($r->form);
        $title = $form?->formTitle() ?? $r->form;
        $rows = [];
        $shown = [];
        foreach ($form?->fieldList() ?? [] as $f) {
            $v = $r->data[$f->key()] ?? null;
            if (null === $v || '' === $v) {
                continue;
            }
            $shown[$f->key()] = true;
            $rows[] = ['label' => $f->label(), 'value' => \is_bool($v) ? I18n::t($v ? 'http.yes' : 'http.no') : (\is_scalar($v) ? (string) $v : '')];
        }
        foreach ($r->data as $k => $v) {
            if (!isset($shown[$k]) && \is_scalar($v) && '' !== $v) {
                $rows[] = ['label' => (string) $k, 'value' => (string) $v];
            }
        }
        // the date is in the language of the letter and in the time zone of the site (php.ini), not the UTC of the server
        $created = $r->createdAt->setTimezone(new \DateTimeZone(\date_default_timezone_get()))->format(I18n::t('http.mail_date_format'));
        $vars = [
            'site_name' => $this->siteName(), 'id' => $r->id, 'form' => $title, 'rows' => $rows, 'source' => $r->sourceUrl,
            'created' => $created, 'url' => $this->urls->to($this->admin->to('#/requests/' . $r->id)),
        ];

        return $this->make($to, I18n::t('http.mail_subject_request', ['id' => $r->id, 'form' => $title]), 'request', $vars, SendMail::REQUEST, (string) $r->id);
    }

    /** The letter of the «Отправить тестовое письмо» button. */
    public function test(string $to): SendMail
    {
        $name = $this->siteName();
        $vars = ['site_name' => $name, 'host' => $this->urls->to('/')];

        return $this->make($to, '' !== $name ? I18n::t('http.mail_subject_test_site', ['site' => $name]) : I18n::t('http.mail_subject_test'), 'test', $vars, SendMail::TEST);
    }

    /** @param array<string, mixed> $vars */
    private function make(string $to, string $subject, string $template, array $vars, string $kind, ?string $ref = null): SendMail
    {
        $vars += ['subject' => $subject];

        return new SendMail(
            $to,
            $subject,
            \trim($this->render($template . '.txt.twig', $vars)) . "\n",
            $this->render($template . '.html.twig', $vars),
            $kind,
            $ref,
        );
    }

    /** @param array<string, mixed> $vars the site's templates/email/<file> first, then the bundle's @ItkCms/email/<file> */
    private function render(string $file, array $vars): string
    {
        return $this->twig->resolveTemplate(['email/' . $file, '@ItkCms/email/' . $file])->render($vars);
    }

    private function siteName(): string
    {
        return \trim((string) $this->settings->get('siteName', null, ''));
    }
}
