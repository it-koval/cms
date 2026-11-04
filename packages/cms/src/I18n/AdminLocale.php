<?php

declare(strict_types=1);

namespace Itk\Cms\I18n;

use Itk\Cms\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which language the interface speaks to whom. It has nothing to do with the languages of the content (main_locale, the language versions of pages).
 *
 *   a person of the admin or of MCP        his own language (User::$locale), else itk_cms.admin_locale; a language that left admin_locales counts as none
 *   sign-in, reset, invitation pages       admin_locale (nobody is known yet)
 *   public routes (the forms of the site)  main_locale when it is one of admin_locales, else admin_locale
 *   console, queue worker                  admin_locale; a letter to one person runs in his language (runFor), letters about requests and Telegram in admin_locale (runDefault)
 *
 * AdminLocaleListener applies this to requests and queue messages. run*() put the language back in any case, so a message never leaves its language to the next one.
 */
final class AdminLocale
{
    /** @param list<string> $locales */
    public function __construct(
        #[Autowire(param: 'itk_cms.admin_locale')]
        private readonly string $default,
        #[Autowire(param: 'itk_cms.admin_locales')]
        private readonly array $locales,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly LoggerInterface $logger,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?LocaleSwitcher $switcher = null,
    ) {
    }

    public function default(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function available(): array
    {
        return $this->locales;
    }

    public function isAvailable(string $locale): bool
    {
        return \in_array($locale, $this->locales, true);
    }

    /**
     * What the person chooses from, for the shell of the admin: each language named in itself.
     *
     * @return list<array{code: string, name: string}>
     */
    public function choices(): array
    {
        return \array_map(static fn (string $code): array => ['code' => $code, 'name' => I18n::LOCALES[$code] ?? $code], $this->locales);
    }

    public function forUser(?User $user): string
    {
        return null !== $user && null !== $user->locale && $this->isAvailable($user->locale) ? $user->locale : $this->default;
    }

    public function forPublic(): string
    {
        return $this->isAvailable($this->mainLocale) ? $this->mainLocale : $this->default;
    }

    /** The language of what the bundle writes into the data of the site (a new home page, a new item): main_locale when the interface has it, else admin_locale. */
    public function content(): string
    {
        return \array_key_exists($this->mainLocale, I18n::LOCALES) ? $this->mainLocale : $this->default;
    }

    /**
     * Calls $work in the language of the content (see content()): for a text that is saved with the data of the site, not shown on a screen.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runContent(callable $work): mixed
    {
        return I18n::locale() === $this->content() ? $work() : $this->run($this->content(), $work);
    }

    /** Switches the language for what follows. */
    public function apply(string $locale): void
    {
        if (null !== $this->switcher) {
            $this->switcher->setLocale($locale);
        } else {
            I18n::locale(); // the facade takes the language of the site when it first wakes: first that, then this
            I18n::useLocale($locale);
        }
    }

    /**
     * Calls $work in the language and puts the previous one back, whatever happens.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(string $locale, callable $work): mixed
    {
        if (null !== $this->switcher) {
            return $this->switcher->runWithLocale($locale, static fn (): mixed => $work());
        }
        $previous = I18n::locale();
        I18n::useLocale($locale);
        try {
            return $work();
        } finally {
            I18n::useLocale($previous);
        }
    }

    /**
     * For what one person reads: a letter to him.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runFor(?User $user, callable $work): mixed
    {
        return $this->run($this->forUser($user), $work);
    }

    /**
     * For what the people of the site read together: a letter about a request, a message in Telegram.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function runDefault(callable $work): mixed
    {
        return $this->run($this->default, $work);
    }

    /** @internal for the facade */
    public function translator(): ?TranslatorInterface
    {
        return $this->translator;
    }

    /** @internal for the facade */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }
}
