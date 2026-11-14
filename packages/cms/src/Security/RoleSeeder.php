<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Role;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RoleRepository;

/**
 * Creates the built-in roles (idempotent). Their rights are never stored: RoleResolver works them out from the schema.
 * Their names and descriptions come from the catalogues, in the language of the console; on the screen RoleLabel shows them in the language of the reader.
 *
 * @internal
 */
final class RoleSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository $roles,
    ) {
    }

    /** @return int number of roles created */
    public function seed(): int
    {
        $n = 0;
        foreach (RoleLabel::BUILTIN as $key) {
            $role = $this->roles->find($key);
            if (null === $role) {
                $role = new Role($key, I18n::t(RoleLabel::nameKey($key)));
                $this->em->persist($role);
                ++$n;
            }
            $role->builtin = true;
            $role->description = I18n::t(RoleLabel::descriptionKey($key));
        }
        $this->em->flush();

        return $n;
    }
}
