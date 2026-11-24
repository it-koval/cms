<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Repository\SettingRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The switch «Настройки → ИИ-ассистенты → Включить». Off by default: while it is off /mcp answers 404, `itk:mcp` refuses to start, keys cannot be
 * made, and no tool can be called. The value is the setting `mcpEnabled` (shared by all languages).
 *
 * @internal
 */
final class McpSettings implements ResetInterface
{
    public const KEY = 'itk:mcpEnabled';

    private ?bool $enabled = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingRepository $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        if (null !== $this->enabled) {
            return $this->enabled;
        }
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);

        return $this->enabled = true === ($row->value ?? false);
    }

    /** A long-running process (itk:mcp) asks the database again instead of trusting what it read at the start. */
    public function forget(): void
    {
        $this->enabled = null;
    }

    /** kernel.reset: the same, between two messages of a worker. */
    public function reset(): void
    {
        $this->forget();
    }

    public function setEnabled(bool $enabled): void
    {
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);
        if (null === $row) {
            $this->em->persist(new Setting(self::KEY, Setting::ALL, $enabled));
        } else {
            $row->value = $enabled;
        }
        $this->em->flush();
        $this->enabled = $enabled;
    }
}
