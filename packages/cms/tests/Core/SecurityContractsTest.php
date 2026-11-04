<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\User;
use Itk\Cms\Security\PermissionVoter;
use Itk\Cms\Security\UserChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Подписи, которые одинаково годятся для Symfony 7.4 и 8: необязательные параметры, которые 8-я версия делает частью интерфейса. */
final class SecurityContractsTest extends TestCase
{
    #[Test]
    public function theVoterTakesTheVoteOfSymfony8AsAnOptionalFourthArgument(): void
    {
        $method = new \ReflectionMethod(PermissionVoter::class, 'voteOnAttribute');

        self::assertSame(['attribute', 'subject', 'token', 'vote'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
        self::assertTrue($method->getParameters()[3]->isOptional());
        self::assertTrue($method->getParameters()[3]->allowsNull());
    }

    #[Test]
    public function theUserCheckerTakesTheTokenOfSymfony8AsAnOptionalSecondArgument(): void
    {
        $method = new \ReflectionMethod(UserChecker::class, 'checkPostAuth');

        self::assertSame(['user', 'token'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
        self::assertTrue($method->getParameters()[1]->isOptional());
        self::assertTrue($method->getParameters()[1]->allowsNull());

        $user = new User('a@example.com', 'A', 'admin');
        $checker = new UserChecker();
        $checker->checkPostAuth($user);
        $checker->checkPostAuth($user, new UsernamePasswordToken($user, 'itk_admin', []));
        $this->addToAssertionCount(1);
    }
}
