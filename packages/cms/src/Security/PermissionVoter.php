<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Rights are checked on the server; the admin UI only mirrors them.
 *
 *   $this->isGranted('itk', new Permission('pages', 'edit', template: 'blog_article'))
 *   #[IsGranted('itk:requests:view')]      (attribute form 'itk:<area>:<action>', areas like 'col:ambassadors' contain a colon: the LAST colon splits the action)
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public const ATTRIBUTE = 'itk';

    public function __construct(private readonly RoleResolver $resolver)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::ATTRIBUTE === $attribute && $subject instanceof Permission) || \str_starts_with($attribute, self::ATTRIBUTE . ':');
    }

    /** The fourth argument is the `Vote` that Symfony 7.3+ passes (and Symfony 8 requires in the signature); the voter has nothing to say to it. */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || User::BLOCKED === $user->status) {
            return false;
        }
        if ($subject instanceof Permission) {
            $area = $subject->area;
            $action = $subject->action;
            $template = $subject->template;
        } else {
            $rest = \substr($attribute, \strlen(self::ATTRIBUTE) + 1);
            $cut = \strrpos($rest, ':');
            if (false === $cut) {
                return false;
            }
            $area = \substr($rest, 0, $cut);
            $action = \substr($rest, $cut + 1);
            $template = null;
        }

        return RoleResolver::allows($this->resolver->rightsOf($user), $area, $action, $template);
    }
}
