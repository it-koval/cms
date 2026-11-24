<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\MenuAreaRepository;

/**
 * A menu area. The areas declared in the schema are created by itk:install; the client can add more in Настройки → Меню.
 *
 * items: { ru: [{ _id, label, link: { kind: 'page'|'url'|'modal', page?, url? }, children?: [] }] }
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: MenuAreaRepository::class)]
#[ORM\Table(name: 'itk_menu_area')]
class MenuArea
{
    #[ORM\Id]
    #[ORM\Column(name: 'area_key', type: Types::STRING, length: 64)]
    public string $key;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $name;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $depth = 1;

    /** @var array<string, list<array<string, mixed>>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $items = [];

    public function __construct(string $key, string $name, int $depth = 1)
    {
        $this->key = $key;
        $this->name = $name;
        $this->depth = $depth;
    }
}
