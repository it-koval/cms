<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Redirect;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RedirectRepository;
use Itk\Cms\Support\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirects: the manual list (Настройки → Переадресации) and the automatic ones written when a published address changes.
 *
 * @internal
 */
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

    public function lookup(string $path, ?string $query = null): ?Redirect
    {
        $path = Url::key($path);
        $general = $this->redirects->findByFrom($path);
        if (null !== $general || null === $query || '' === $query) {
            return $general;
        }

        return $this->redirects->findByFrom(self::key($path . '?' . $query));
    }

    /** Paths are compared case-insensitively. Query values keep their case. */
    public function create(string $from, string $to, int $code = 301): Redirect
    {
        $from = self::key($from);
        $this->check($from, $to, $code);
        if (null !== $this->redirects->findByFrom($from)) {
            throw ContentException::conflict(I18n::t('content.redirect_exists'));
        }
        $r = new Redirect($from, $to, $code);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    /** @param array<string, mixed> $patch */
    public function update(string $id, array $patch): Redirect
    {
        $r = $this->redirects->find($id) ?? throw ContentException::notFound(I18n::t('content.redirect_not_found'));
        $from = \array_key_exists('from', $patch) ? self::key((string) $patch['from']) : $r->fromPath;
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
        } elseif (\mb_strlen($from) > Redirect::FROM_MAX) {
            $details['from'] = I18n::t('content.redirect_address_too_long', ['max' => Redirect::FROM_MAX]);
        }
        if ('' === $to) {
            $details['to'] = I18n::t('content.redirect_to_required');
        } elseif (\mb_strlen($to) > Redirect::TO_MAX) {
            $details['to'] = I18n::t('content.redirect_address_too_long', ['max' => Redirect::TO_MAX]);
        }
        if (!\in_array($code, [301, 302], true)) {
            $details['code'] = I18n::t('content.redirect_code');
        }
        if ([] === $details) {
            $target = self::key($to);
            if ($from === $target || (!\str_contains($from, '?') && \str_starts_with($target, $from . '?'))) {
                $details['to'] = I18n::t('content.redirect_same');
            }
        }
        if ([] !== $details) {
            throw ContentException::invalid((string) \reset($details), $details);
        }
    }

    private static function key(string $address): string
    {
        [$path, $query] = \array_pad(\explode('?', \trim($address), 2), 2, '');
        $query = Request::normalizeQueryString($query);

        return Url::key($path) . ('' === $query ? '' : '?' . $query);
    }
}
