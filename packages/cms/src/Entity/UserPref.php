<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\UserPrefRepository;

/** A setting of one person that follows him between devices: the filters and sorting of a list, the chosen view. */
#[ORM\Entity(repositoryClass: UserPrefRepository::class)]
#[ORM\Table(name: 'itk_user_pref')]
class UserPref
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Id]
    #[ORM\Column(name: 'pref_key', type: Types::STRING, length: 100)]
    public string $key;

    #[ORM\Column(name: 'pref_value', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public mixed $value = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $key, mixed $value)
    {
        $this->user = $user;
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
