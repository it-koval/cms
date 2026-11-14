<?php

declare(strict_types=1);

namespace Itk\Cms\SchemaOrg;

use Itk\Cms\Entity\PageTranslation;

/**
 * Puts the graph of a live page into its HTML as one `<script type="application/ld+json">` before the first `</head>`.
 * A node whose schema.org type the page already prints in an application/ld+json block of its own (the layout of the site) is left out.
 * The JSON is safe inside a script element: `<`, `>` and `&` are escaped, so no title can close the element.
 *
 * @internal
 */
final class SchemaOrgInjector
{
    /** One entity for a search engine: the node of the site stands for ours. */
    public const ORGANIZATION_GROUP = [
        'Organization', 'LocalBusiness', 'Corporation', 'OnlineStore', 'OnlineBusiness', 'Store', 'AutoPartsStore', 'AutomotiveBusiness', 'AutoRepair', 'AutoDealer', 'Restaurant',
        'FoodEstablishment', 'ProfessionalService', 'MedicalBusiness', 'MedicalOrganization', 'Dentist', 'HomeAndConstructionBusiness', 'LegalService', 'FinancialService',
        'EducationalOrganization', 'NGO', 'SportsOrganization',
    ];
    public const ARTICLE_GROUP = ['Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'Report'];

    private const FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR;

    public function __construct(private readonly GraphBuilder $graph)
    {
    }

    public function inject(string $html, PageTranslation $t): string
    {
        $nodes = $this->graph->build($t);
        if ([] === $nodes) {
            return $html;
        }
        $present = self::typesIn($html);
        $nodes = \array_values(\array_filter($nodes, static fn (array $n): bool => [] === \array_filter((array) ($n['@type'] ?? []), static fn (mixed $type): bool => \is_string($type) && self::isCovered($type, $present))));
        $nodes = self::withoutDanglingRefs($nodes);
        if ([] === $nodes) {
            return $html;
        }
        $json = \json_encode(['@context' => 'https://schema.org', '@graph' => $nodes], self::FLAGS);
        $tag = '<script type="application/ld+json">' . $json . '</script>';
        $at = \stripos($html, '</head>');

        return false === $at ? $html : \substr_replace($html, $tag, $at, 0);
    }

    /** Is a node of this type already described by one of the types the page prints (the same type, or one of its group)? @param list<string> $present */
    public static function isCovered(string $type, array $present): bool
    {
        foreach ([self::ORGANIZATION_GROUP, self::ARTICLE_GROUP] as $group) {
            if (\in_array($type, $group, true)) {
                return [] !== \array_intersect($group, $present);
            }
        }

        return \in_array($type, $present, true);
    }

    /**
     * A property that is only a reference `{"@id": X}` (or a list of them) is dropped when no node of the graph has that `@id`: the Organization may be the site's own or removed by a listener.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutDanglingRefs(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $n) {
            if (\is_string($n['@id'] ?? null)) {
                $ids[$n['@id']] = true;
            }
        }
        $isRef = static fn (mixed $v): bool => \is_array($v) && ['@id'] === \array_keys($v) && \is_string($v['@id']);
        foreach ($nodes as $i => $node) {
            foreach ($node as $key => $value) {
                if ('@id' === $key || !\is_array($value)) {
                    continue;
                }
                if ($isRef($value) && !isset($ids[$value['@id']])) {
                    unset($nodes[$i][$key]);
                } elseif (\array_is_list($value) && [] !== $value && [] === \array_filter($value, static fn (mixed $v): bool => !$isRef($v))) {
                    $kept = \array_values(\array_filter($value, static fn (array $v): bool => isset($ids[$v['@id']])));
                    if ([] === $kept) {
                        unset($nodes[$i][$key]);
                    } else {
                        $nodes[$i][$key] = $kept;
                    }
                }
            }
        }

        return $nodes;
    }

    /** @return list<string> the schema.org type of every node of every ld+json block in the HTML, nested nodes included */
    public static function typesIn(string $html): array
    {
        $types = [];
        if (false === \preg_match_all('~<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>~is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $body) {
            $data = \json_decode(\trim($body), true);
            if (\is_array($data)) {
                self::collect($data, $types);
            }
        }

        return \array_values(\array_unique($types));
    }

    /**
     * @param array<mixed> $data any part of a block: nodes, lists, `@graph`, a nested object
     * @param list<string> $types
     */
    private static function collect(array $data, array &$types): void
    {
        foreach ((array) ($data['@type'] ?? []) as $type) {
            if (\is_string($type)) {
                $types[] = $type;
            }
        }
        foreach ($data as $value) {
            if (\is_array($value)) {
                self::collect($value, $types);
            }
        }
    }
}
