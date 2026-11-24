<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** The lock of the sign-in: when the limiter holds the sign-in, the page says so; it does not say the password is wrong (it may be right). */
final class LoginLockMessageTest extends WebTestCase
{
    private const ATTEMPTS = 2;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], [], self::ATTEMPTS);
    }

    private function escaped(string $key): string
    {
        return \htmlspecialchars(I18n::t($key), \ENT_QUOTES | \ENT_SUBSTITUTE);
    }

    /** One try to sign in with a wrong password; the page it ends on (the sign-in page again). */
    private function tryToSignIn(KernelBrowser $client, string $email): string
    {
        $client->request('GET', '/admin/login');
        self::assertSame(1, \preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $client->getResponse()->getContent(), $m));
        $client->request('POST', '/admin/login', ['_username' => $email, '_password' => 'definitely-wrong', '_csrf_token' => $m[1]]);
        self::assertTrue($client->getResponse()->isRedirect(), 'a failed sign-in goes back to the form');
        $client->followRedirect();

        return (string) $client->getResponse()->getContent();
    }

    #[Test]
    public function afterTheLockThePageSaysTooManyAttemptsNotAWrongPassword(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // the counters of the limiter live in the cache between runs (by the address of the visitor and by the e-mail): every run has its own of both
        $client->setServerParameter('REMOTE_ADDR', '10.' . \random_int(1, 254) . '.' . \random_int(1, 254) . '.' . \random_int(1, 254));
        $email = 'test-lock-' . \bin2hex(\random_bytes(6)) . '@example.com';

        for ($try = 1; $try <= self::ATTEMPTS; ++$try) {
            $page = $this->tryToSignIn($client, $email);
            self::assertStringContainsString($this->escaped('http.login_failed'), $page, 'try ' . $try . ': the limiter has not locked yet');
            self::assertStringNotContainsString($this->escaped('http.too_many_attempts'), $page);
        }

        $page = $this->tryToSignIn($client, $email);
        self::assertStringContainsString($this->escaped('http.too_many_attempts'), $page);
        self::assertStringNotContainsString($this->escaped('http.login_failed'), $page);
    }
}
