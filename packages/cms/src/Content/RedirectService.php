<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Redirect;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RedirectRepository;
use Itk\Cms\Support\Url;

/** Redirects: the manual list (Настройки → Переадресации) and the automatic ones written when a published address changes. */
final class RedirectService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /** @return list<Redirect> */
    public function all(): array
    {
        return $this->redirects->findBy([], ['createdAt' => 'DESC']);
    }

    public function lookup(string $path): ?Redirect
    {
        return $this->redirects->findByFrom(Url::key($path));
    }

    /** The address a redirect answers to is stored as Url::key(): a request matches it whatever its case, and two spellings cannot both be saved. */
    public function create(string $from, string $to, int $code = 301): Redirect
    {
        $from = Url::key($from);
        $this->check($from, $to, $code);
        if (null !== $this->redirects->findByFrom($from)) {
            throw ContentException::conflict(I18n::t('content.redirect_exists'));
        }
        $r = new Redirect($from, $to, $code);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    public function update(string $id, array $patch): Redirect
    {
        $r = $this->redirects->find($id) ?? throw ContentException::notFound(I18n::t('content.redirect_not_found'));
        $from = \array_key_exists('from', $patch) ? Url::key((string) $patch['from']) : $r->fromPath;
        $to = \array_key_exists('to', $patch) ? \trim((string) $patch['to']) : $r->toPath;
        $code = \array_key_exists('code', $patch) ? (int) $patch['code'] : $r->code;
        $this->check($from, $to, $code);
        $other = $this->redirects->findByFrom($from);
        if (null !== $other && $other->id !== $r->id) {
            throw ContentException::conflict(I18n::t('content.redirect_exists'));
        }
        $r->fromPath = $from;
        $r->toPath = $to;
        $r->code = $code;
        $this->em->flush();

        return $r;
    }

    public function remove(string $id): void
    {
        $r = $this->redirects->find($id) ?? throw ContentException::notFound(I18n::t('content.redirect_not_found'));
        $this->em->remove($r);
        $this->em->flush();
    }

    /**
     * The page that lived at `from` now lives at `to`: make `from` open `to`.
     * No chains (an older redirect that pointed at `from` is repointed at `to`), no loops (a redirect from `to` is dropped),
     * no second redirect for the same address. Does not flush: the caller flushes with its own change.
     */
    public function moved(?string $from, ?string $to): ?Redirect
    {
        if (!$from || !$to || Url::key($from) === Url::key($to)) {
            return null;
        }
        $made = null;
        $mine = null;
        foreach ($this->redirects->findAll() as $r) {
            if (Url::key($r->fromPath) === Url::key($to)) {
                $this->em->remove($r); // the page is back at an old address
                continue;
            }
            if (Url::key($r->toPath) === Url::key($from)) {
                $r->toPath = $to; // A -> B and now B -> C: make it A -> C
            }
            if (Url::key($r->fromPath) === Url::key($from)) {
                $mine = $r;
            }
        }
        if (null !== $mine) {
            $mine->toPath = $to;
            $made = $mine;
        } else {
            $made = new Redirect(Url::key($from), $to, 301);
            $this->em->persist($made);
        }

        return $made;
    }

    private function check(string $from, string $to, int $code): void
    {
        $details = [];
        if (!\str_starts_with($from, '/')) {
            $details['from'] = I18n::t('content.redirect_from_slash');
        }
        if ('' === $to) {
            $details['to'] = I18n::t('content.redirect_to_required');
        }
        if (!\in_array($code, [301, 302], true)) {
            $details['code'] = I18n::t('content.redirect_code');
        }
        if ([] === $details && Url::key($from) === Url::key($to)) {
            $details['to'] = I18n::t('content.redirect_same');
        }
        if ([] !== $details) {
            throw ContentException::invalid((string) \reset($details), $details);
        }
    }
}
