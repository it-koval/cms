<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\QueryBuilder;
use Itk\Cms\Doctrine\JsonPath;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Key;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What a site reads from the CMS to draw a page: the PUBLISHED pages and records, the same for every visitor and every signed-in person (Twig: itk_pages(), itk_page(), itk_collection()).
 * A draft, an unpublished page and the draft text of a published one never get here. Each call is one query that takes only the columns it needs
 * (no page, version or record is loaded as an object); pages by id come from one query per language.
 *
 *   pages({template: 'blog_category'|[…], parent: <page id>, limit: 10, sort: 'position'|'-publishedAt'|'title'|'-fields.date'|['title', '-fields.date']})
 *     → list of { id, parentId, template, locale, title, url, seo, fields, position, publishedAt }; without sort: the order of the site tree (position)
 *   page($id) → the same row or null
 *   collection('tours', {where: {state: 'open', hot: true, seats: {from: 5}, level: ['easy', 'mid']}, sort: '-start', limit: 10, offset: 20})
 *     → list of { id, …published fields } in site order; where compares a field to a value (a list = any of them, true/false for a toggle, {from, to} for a range of numbers or dates)
 *
 * A wrong option or a field that does not exist is an \InvalidArgumentException that lists what is allowed (the Twig functions turn it into the strict-mode rule of itk_cms.strict).
 * CollectionQuery is not used on purpose: it filters the draft and hides fields from the person who looks.
 */
final class ContentReader implements ResetInterface
{
    private const PAGE_OPTIONS = ['template', 'parent', 'limit', 'sort'];
    private const COLLECTION_OPTIONS = ['where', 'sort', 'limit', 'offset'];

    /** @var array<string, array<string, array<string, mixed>>> language => page id => row (every live page, for page()) */
    private array $byId = [];

    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly CollectionItemRepository $items,
        private readonly Schema $schema,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * @param array<string, mixed> $options template, parent, limit, sort
     *
     * @throws \InvalidArgumentException
     *
     * @return list<array<string, mixed>>
     */
    public function pages(array $options = [], ?string $locale = null): array
    {
        $this->allowOnly($options, self::PAGE_OPTIONS);
        $locale = $this->language($locale);
        $qb = $this->pageQuery($locale);

        $templates = $this->templates($options['template'] ?? null);
        if ([] !== $templates) {
            $qb->andWhere('p.template IN (:templates)')->setParameter('templates', $templates);
        }
        if (\array_key_exists('parent', $options)) {
            if (!\is_string($options['parent']) || '' === $options['parent']) {
                throw new \InvalidArgumentException(I18n::t('content.reader_parent_param'));
            }
            $qb->andWhere('p.parent = :parent')->setParameter('parent', $options['parent']);
        }
        $first = true;
        foreach ($this->pageSorts($options['sort'] ?? null, $templates) as [$expr, $dir]) {
            $first ? $qb->orderBy($expr, $dir) : $qb->addOrderBy($expr, $dir);
            $first = false;
        }
        $first ? $qb->orderBy('p.position', 'ASC') : $qb->addOrderBy('p.position', 'ASC');
        $qb->addOrderBy('p.createdAt', 'ASC')->addOrderBy('p.id', 'ASC');
        if (isset($options['limit'])) {
            $qb->setMaxResults($this->count($options['limit'], 'limit', 1));
        }

        return \array_map(fn (array $r): array => $this->pageRow($r, $locale), $qb->getQuery()->getArrayResult());
    }

    /** The published page with this id, or null (no such page, a draft, an unpublished one, a page without this language). @return null|array<string, mixed> */
    public function page(string $id, ?string $locale = null): ?array
    {
        $locale = $this->language($locale);
        if (!isset($this->byId[$locale])) {
            $this->byId[$locale] = [];
            foreach ($this->pageQuery($locale)->orderBy('p.position', 'ASC')->addOrderBy('p.createdAt', 'ASC')->getQuery()->getArrayResult() as $r) {
                $row = $this->pageRow($r, $locale);
                $this->byId[$locale][$row['id']] = $row;
            }
        }

        return $this->byId[$locale][$id] ?? null;
    }

    /**
     * @param array<string, mixed> $options where, sort, limit, offset
     *
     * @throws \InvalidArgumentException
     *
     * @return list<array<string, mixed>> published records, [{ id, …fields }]
     */
    public function collection(string $key, array $options = [], ?string $locale = null): array
    {
        $this->allowOnly($options, self::COLLECTION_OPTIONS);
        $c = $this->schema->collection($key) ?? throw new \InvalidArgumentException(I18n::t('content.reader_collection_unknown', ['key' => $key, 'names' => $this->names(\array_keys($this->schema->collections()))]));
        $locale = $this->language($locale);

        $qb = $this->items->createQueryBuilder('i')
            ->select('i.id AS id', "JSON_GET(i.translations, '" . $this->path($locale, 'published') . "') AS live")
            ->where('i.collection = :collection')->setParameter('collection', $c->key())
            ->andWhere("JSON_TEXT(i.translations, '" . $this->path($locale, 'status') . "') = 'published'");
        $n = 0;
        foreach ((array) ($options['where'] ?? []) as $field => $value) {
            $this->where($qb, $c, $locale, (string) $field, $value, 'w' . $n++);
        }
        $first = true;
        foreach ($this->collectionSorts($options['sort'] ?? null, $c, $locale) as [$expr, $dir]) {
            $first ? $qb->orderBy($expr, $dir) : $qb->addOrderBy($expr, $dir);
            $first = false;
        }
        $first ? $qb->orderBy('i.position', 'ASC') : $qb->addOrderBy('i.position', 'ASC');
        $qb->addOrderBy('i.createdAt', 'ASC')->addOrderBy('i.id', 'ASC');
        if (isset($options['limit'])) {
            $qb->setMaxResults($this->count($options['limit'], 'limit', 1));
        }
        if (isset($options['offset'])) {
            $qb->setFirstResult($this->count($options['offset'], 'offset', 0));
        }

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            $live = \is_string($r['live']) ? \json_decode($r['live'], true) : null;
            if (\is_array($live)) {
                $out[] = ['id' => (string) $r['id']] + $live;
            }
        }

        return $out;
    }

    /** kernel.reset: a worker reads the database again for the next message. */
    public function reset(): void
    {
        $this->byId = [];
    }

    /* ---------- pages ---------- */

    /** The live versions of a language without their sections (they are the weight of a page): the title, the address, the SEO and the fields come out of the JSON by the database. */
    private function pageQuery(string $locale): QueryBuilder
    {
        return $this->translations->createQueryBuilder('t')->join('t.page', 'p')
            ->select(
                'IDENTITY(t.page) AS id',
                'IDENTITY(p.parent) AS parentId',
                'p.template AS template',
                'p.position AS position',
                't.publishedAt AS publishedAt',
                "JSON_TEXT(t.published, '{\"title\"}') AS title",
                "JSON_TEXT(t.published, '{\"url\"}') AS url",
                "JSON_GET(t.published, '{\"seo\"}') AS seo",
                "JSON_GET(t.published, '{\"fields\"}') AS fields",
            )
            ->where('t.locale = :locale AND t.status = :status AND t.published IS NOT NULL')
            ->setParameter('locale', $locale)->setParameter('status', PageTranslation::PUBLISHED);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function pageRow(array $r, string $locale): array
    {
        $json = static fn (mixed $v): array => \is_string($v) && \is_array($d = \json_decode($v, true)) ? $d : [];
        $at = $r['publishedAt'] ?? null;

        return [
            'id' => (string) $r['id'],
            'parentId' => null === $r['parentId'] ? null : (string) $r['parentId'],
            'template' => (string) $r['template'],
            'locale' => $locale,
            'title' => (string) ($r['title'] ?? ''),
            'url' => (string) ($r['url'] ?? ''),
            'seo' => $json($r['seo'] ?? null),
            'fields' => $json($r['fields'] ?? null),
            'position' => (int) $r['position'],
            'publishedAt' => $at instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z') : null,
        ];
    }

    /**
     * @throws \InvalidArgumentException a template the schema does not have
     *
     * @return list<string>
     */
    private function templates(mixed $option): array
    {
        $list = null === $option ? [] : \array_values(\array_map('strval', (array) $option));
        foreach ($list as $key) {
            if (null === $this->schema->template($key)) {
                throw new \InvalidArgumentException(I18n::t('content.reader_template_unknown', ['key' => $key, 'names' => $this->names(\array_keys($this->schema->templates()))]));
            }
        }

        return $list;
    }

    /**
     * @param list<string> $templates
     *
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private function pageSorts(mixed $sort, array $templates): array
    {
        $out = [];
        foreach ($this->sortKeys($sort) as [$key, $dir]) {
            if (\str_starts_with($key, 'fields.')) {
                $field = \substr($key, 7);
                $this->assertKey($field, 'sort');
                $defs = \array_map(fn (string $t) => $this->schema->template($t)?->field($field), $templates);
                if ([] !== $templates && [] === \array_filter($defs)) {
                    throw new \InvalidArgumentException(I18n::t('content.reader_sort_no_template_field', ['field' => $field]));
                }
                $number = [] !== $defs && [] === \array_filter($defs, static fn (mixed $d): bool => 'number' !== $d?->type());
                $path = JsonPath::of('fields', $field);
                $out[] = [$number ? "JSON_NUMBER(t.published, '$path')" : "JSON_TEXT(t.published, '$path')", $dir];

                continue;
            }
            $expr = match ($key) {
                'position' => 'p.position',
                'createdAt' => 'p.createdAt',
                'publishedAt' => 't.publishedAt',
                'url' => 't.publishedUrl',
                'title' => "JSON_TEXT(t.published, '{\"title\"}')",
                default => throw new \InvalidArgumentException(I18n::t('content.reader_sort_key_unknown', ['key' => $key])),
            };
            $out[] = [$expr, $dir];
        }

        return $out;
    }

    /* ---------- collections ---------- */

    /** The JSON path to a part of the version of a language: «$."ru".published», «$."ru".published."title"». */
    private function path(string $locale, string $part, ?string $key = null): string
    {
        $this->assertKey($locale, 'language');
        if (null !== $key) {
            $this->assertKey($key, 'key');
        }

        return null === $key ? JsonPath::of($locale, $part) : JsonPath::of($locale, $part, $key);
    }

    private function where(QueryBuilder $qb, Collection $c, string $locale, string $key, mixed $value, string $p): void
    {
        $field = $c->field($key) ?? throw new \InvalidArgumentException(I18n::t('content.reader_where_no_field', ['collection' => $c->key(), 'field' => $key, 'names' => $this->names($this->fieldKeys($c))]));
        $text = "JSON_TEXT(i.translations, '" . $this->path($locale, 'published', $key) . "')";
        $number = "JSON_NUMBER(i.translations, '" . $this->path($locale, 'published', $key) . "')";
        if (null === $value) {
            $qb->andWhere("($text IS NULL OR $text IN ('', 'null'))");
        } elseif (\is_bool($value)) {
            $qb->andWhere($value ? "$text = 'true'" : "($text = 'false' OR $text IS NULL)");
        } elseif (\is_array($value) && !\array_is_list($value) && [] !== \array_intersect(['from', 'to'], \array_keys($value))) {
            foreach (['from' => '>=', 'to' => '<='] as $edge => $op) {
                if (!isset($value[$edge]) || '' === $value[$edge]) {
                    continue;
                }
                $bound = $this->scalar($value[$edge], $key);
                if ('number' === $field->type()) {
                    if (!\is_numeric($bound)) {
                        throw new \InvalidArgumentException(I18n::t('content.reader_where_bound', ['field' => $key, 'edge' => $edge, 'bound' => $bound]));
                    }
                    $qb->andWhere("$number $op :{$p}_$edge")->setParameter("{$p}_$edge", $bound + 0);
                } elseif (1 === \preg_match('/^\d{4}-\d{2}-\d{2}/', $bound)) {
                    $qb->andWhere("SUBSTRING($text, 1, 10) $op :{$p}_$edge")->setParameter("{$p}_$edge", \substr($bound, 0, 10));
                } else {
                    $qb->andWhere("$text $op :{$p}_$edge")->setParameter("{$p}_$edge", $bound);
                }
            }
        } elseif (\is_array($value)) {
            $qb->andWhere("$text IN (:$p)")->setParameter($p, \array_map(fn (mixed $v): string => $this->scalar($v, $key), \array_values($value)));
        } else {
            $qb->andWhere("$text = :$p")->setParameter($p, $this->scalar($value, $key));
        }
    }

    /**
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private function collectionSorts(mixed $sort, Collection $c, string $locale): array
    {
        $out = [];
        foreach ($this->sortKeys($sort) as [$key, $dir]) {
            if ('position' === $key || 'createdAt' === $key) {
                $out[] = ['i.' . $key, $dir];

                continue;
            }
            $field = $c->field($key) ?? throw new \InvalidArgumentException(I18n::t('content.reader_sort_no_collection_field', ['collection' => $c->key(), 'field' => $key, 'names' => $this->names($this->fieldKeys($c))]));
            $path = $this->path($locale, 'published', $key);
            $out[] = ['number' === $field->type() ? "JSON_NUMBER(i.translations, '$path')" : "JSON_TEXT(i.translations, '$path')", $dir];
        }

        return $out;
    }

    /** @return list<string> */
    private function fieldKeys(Collection $c): array
    {
        return \array_map(static fn ($f): string => $f->key(), $c->fieldList());
    }

    /* ---------- options ---------- */

    /**
     * @param array<string, mixed> $options
     * @param list<string> $allowed
     */
    private function allowOnly(array $options, array $allowed): void
    {
        foreach (\array_keys($options) as $name) {
            if (!\in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException(I18n::t('content.reader_param_unknown', ['name' => $name, 'allowed' => \implode(', ', $allowed)]));
            }
        }
    }

    /** @return list<array{0: string, 1: 'ASC'|'DESC'}> */
    private function sortKeys(mixed $sort): array
    {
        $parts = null === $sort ? [] : (\is_array($sort) ? $sort : \explode(',', (string) $sort));
        $out = [];
        foreach ($parts as $part) {
            $part = \trim((string) $part);
            if ('' !== $part) {
                $out[] = [\ltrim($part, '-+ '), \str_starts_with($part, '-') ? 'DESC' : 'ASC'];
            }
        }

        return $out;
    }

    private function count(mixed $value, string $name, int $min): int
    {
        if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value)) || (int) $value < $min) {
            throw new \InvalidArgumentException(I18n::t('content.reader_param_int', ['name' => $name, 'min' => $min, 'value' => \is_scalar($value) ? (string) $value : \get_debug_type($value)]));
        }

        return (int) $value;
    }

    private function scalar(mixed $value, string $key): string
    {
        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException(I18n::t('content.reader_where_value', ['key' => $key]));
        }

        return \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param string $what language | key | sort: the text is content.reader_bad_<what> */
    private function assertKey(string $key, string $what): void
    {
        if (!Key::isValid($key)) {
            $text = 'content.reader_bad_' . $what; // i18n-dynamic: content.reader_bad_
            throw new \InvalidArgumentException(I18n::t($text, ['key' => $key, 'rule' => Key::rule()]));
        }
    }

    private function language(?string $locale): string
    {
        $locale ??= $this->mainLocale;
        $this->assertKey($locale, 'language');

        return $locale;
    }

    /** @param list<int|string> $names */
    private function names(array $names): string
    {
        return [] === $names ? I18n::t('content.reader_nothing') : \implode(', ', $names);
    }
}
