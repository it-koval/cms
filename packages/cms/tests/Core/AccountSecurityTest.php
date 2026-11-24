<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\DsnBuilder;
use Itk\Cms\Mail\MailConfig;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\AdminUserProvider;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Security\UserChecker;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class AccountSecurityTest extends TestCase
{
    public function testAnonymousWebAccessNeverBecomesTheSystem(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $requests = new \Symfony\Component\HttpFoundation\RequestStack();
        $requests->push(\Symfony\Component\HttpFoundation\Request::create('/admin/api/resources/sample/actions/delete-selected'));
        $roles = new RoleResolver((new SchemaBuilder())->build(), $this->createStub(RoleRepository::class));
        $access = new Access($security, $roles, $requests);
        self::assertFalse($access->viewer()->isSystem());
        self::assertFalse($access->viewer()->can('col:sample', 'delete'));
        self::assertTrue(Viewer::system()->can('col:sample', 'delete'));
        $requests->pop();
        self::assertTrue($access->viewer()->isSystem(), 'Trusted PHP code without an HTTP request keeps explicit system semantics.');
    }

    public function testADateCardDoesNotGrantAuthorAccess(): void
    {
        $builder = new SchemaBuilder();
        $builder->template('event', 'Event')->card(date: 'date');
        self::assertSame(['blog_article'], $builder->build()->articleTemplates());
        $builder->template('article', 'Article')->forAuthors();
        self::assertSame(['blog_article', 'article'], $builder->build()->articleTemplates());
        $builder->template('article')->forAuthors(false);
        self::assertSame(['blog_article'], $builder->build()->articleTemplates());
    }

    public function testBlockedRefreshInvalidatesTheExistingSessionUser(): void
    {
        $user = new User('session@example.com', 'Session', 'editor');
        $blocked = clone $user;
        $blocked->status = User::BLOCKED;
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('find')->with($user->id)->willReturn($blocked);
        $provider = new AdminUserProvider($users, $this->createStub(EntityManagerInterface::class));
        $this->expectException(UserNotFoundException::class);
        $provider->refreshUser($user);
    }

    public function testBlockedLoginUsesTheGenericPublicFailure(): void
    {
        $user = new User('blocked@example.com', 'Blocked', 'editor');
        $user->status = User::BLOCKED;
        try {
            (new UserChecker())->checkPreAuth($user);
            self::fail('The blocked account cannot sign in.');
        } catch (AuthenticationException $error) {
            self::assertSame((new \Symfony\Component\Security\Core\Exception\BadCredentialsException())->getMessageKey(), $error->getMessageKey());
        }
    }

    public function testAnExistingUnsafeSmtpPasswordCannotBeSent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DsnBuilder::build(new MailConfig(true, 'localhost', 25, MailConfig::NONE, 'test', 'innocuous-fixture'));
    }

    public function testControlledPasswordFailureRedactsTheTraceArgument(): void
    {
        $previous = \ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                \Itk\Cms\Security\UserManager::checkPasswordPolicy('tiny');
                self::fail('The innocuous short password must be rejected.');
            } catch (\Itk\Cms\Content\ContentException $error) {
                $frames = \array_values(\array_filter($error->getTrace(), static fn (array $frame): bool => 'checkPasswordPolicy' === ($frame['function'] ?? '')));
                self::assertCount(1, $frames);
                self::assertInstanceOf(\SensitiveParameterValue::class, $frames[0]['args'][0]);
                self::assertStringNotContainsString("'tiny'", $error->getTraceAsString());
            }
        } finally {
            \ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    public function testSensitiveAccountArgumentsAreMarked(): void
    {
        $methods = [
            [\Itk\Cms\Security\UserManager::class, 'create', ['password']],
            [\Itk\Cms\Security\UserManager::class, 'checkPasswordPolicy', ['password']],
            [\Itk\Cms\Security\UserManager::class, 'setPassword', ['password']],
            [\Itk\Cms\Security\PasswordChanger::class, 'change', ['current', 'new', 'repeat']],
            [\Itk\Cms\Security\PasswordRecovery::class, 'complete', ['token', 'password', 'repeat']],
            [\Itk\Cms\Security\TwoFactor\TwoFactorService::class, 'verify', ['input']],
            [\Itk\Cms\Security\TwoFactor\TwoFactorService::class, 'enable', ['code']],
            [\Itk\Cms\Security\TwoFactor\TwoFactorService::class, 'disable', ['password']],
            [\Itk\Cms\Security\TwoFactor\TwoFactorService::class, 'regenerateRecoveryCodes', ['password']],
            [\Itk\Cms\Security\TwoFactor\SecretBox::class, '__construct', ['secret']],
            [\Itk\Cms\Security\TwoFactor\SecretBox::class, 'seal', ['plain']],
            [\Itk\Cms\Security\TwoFactor\SecretBox::class, 'open', ['sealed']],
            [\Itk\Cms\Security\TwoFactor\SecretBox::class, 'hash', ['value']],
            [\Itk\Cms\Mail\MailSettings::class, 'save', ['input']],
        ];
        foreach ($methods as [$class, $method, $names]) {
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                if (\in_array($parameter->getName(), $names, true)) {
                    self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class), $class . '::' . $method . '($' . $parameter->getName() . ')');
                }
            }
        }
    }
}
