<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\SettingRepository;

/**
 * One typed setting. locale '*' = shared by all languages.
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'itk_setting')]
class Setting
{
    public const ALL = '*';

    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', type: Types::STRING, length: 100)]
    public string $key;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale = self::ALL;

    #[ORM\Column(name: 'setting_value', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public mixed $value = null;

    public function __construct(string $key, string $locale, mixed $value)
    {
        $this->key = $key;
        $this->locale = $locale;
        $this->value = $value;
    }
}
