<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last critical-CSS attempt of one language version of a page: one row per PageTranslation, gone with it by the foreign key.
 *
 * The row records a fact, never a wish: "for basis B we got these styles" (ready) or "for basis B it did not work, because…" (failed).
 * Whether the row is still good is not stored: CriticalCss\Model\Freshness derives it each time from `basis` and the basis the page wants now,
 * so a republish, a new stylesheet, a deploy or new viewports make it stale without anybody touching the row.
 * A stale row with ready styles is still shown while the worker makes the new one; `stamp` lets the health check tell such rows from current ones.
 *
 * CriticalCss\CriticalCssGenerator is the one writer (worker and console only); web requests only read it.
 * `inputs` lets the worker skip Chrome when the rendered HTML and the source CSS are byte-identical to the last ready run.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_critical_css')]
class CriticalCss
{
    public const READY = 'ready';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: PageTranslation::class)]
    #[ORM\JoinColumn(name: 'translation_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    public PageTranslation $translation;

    /** sha1 of what the page looked like to the request side when the attempt started (CriticalCss\Model\Basis) */
    #[ORM\Column(type: Types::STRING, length: 40)]
    public string $basis = '';

    /** sha1 of the page-independent part of the basis (the container build and Stylesheets::stamp()) of a ready row, null when failed or made before the column existed */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    public ?string $stamp = null;

    /** sha1 of the exact inputs of the run: rendered HTML, prepared source CSS and the generator config */
    #[ORM\Column(type: Types::STRING, length: 40)]
    public string $inputs = '';

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $status = self::FAILED;

    /** @var list<array{media: string|null, css: string}>|null one merged block (media null) when ready, per media range in rows of the fallback and of older versions, null when failed */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $styles = null;

    /** a short reason when failed (for «Состояние сайта» and the command, never for visitors), null when ready */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    public ?string $error = null;

    #[ORM\Column(name: 'generated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $generatedAt;

    private function __construct(PageTranslation $translation)
    {
        $this->translation = $translation;
        $this->generatedAt = new \DateTimeImmutable();
    }

    public static function for(PageTranslation $translation): self
    {
        return new self($translation);
    }

    /** @param list<array{media: string|null, css: string}> $styles */
    public function markReady(string $basis, string $inputs, array $styles, \DateTimeImmutable $at, string $stamp): void
    {
        $this->status = self::READY;
        $this->basis = $basis;
        $this->inputs = $inputs;
        $this->styles = $styles;
        $this->stamp = $stamp;
        $this->error = null;
        $this->generatedAt = $at;
    }

    public function markFailed(string $basis, string $inputs, string $error, \DateTimeImmutable $at): void
    {
        $this->status = self::FAILED;
        $this->basis = $basis;
        $this->inputs = $inputs;
        $this->styles = null;
        $this->stamp = null;
        $this->error = \mb_substr($error, 0, 500);
        $this->generatedAt = $at;
    }
}
