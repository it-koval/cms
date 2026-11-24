<?php

declare(strict_types=1);

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Controller\Admin\SettingsController;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Request\Max\MaxSender;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\SessionWork;
use Itk\Cms\Tests\App\TestKernel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\SessionListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Firewall\ContextListener;

if (!isset($argv[1], $argv[2], $argv[3], $argv[4])) {
    throw new \InvalidArgumentException('Expected session mode, directory, ID and action.');
}
[, $mode, $dir, $id, $action] = $argv;
$session = new Session(new NativeSessionStorage(['use_cookies' => false, 'cache_limiter' => '', 'gc_probability' => 0], new NativeFileSessionHandler($dir)));
$session->setId($id);
if ('peer' === $mode) {
    \fwrite(\STDOUT, "READY\n");
    $session->start();
    $token = \unserialize($session->get('_security_itk_admin'));
    $user = $token->getUser();
    $user->passwordHash = 'rotated-password-hash';
    if ('login' === $action) {
        $session->invalidate();
        $token = new UsernamePasswordToken($user, 'itk_admin');
    }
    $session->set('_security_itk_admin', \serialize($token));
    $peerId = $session->getId();
    $session->save();
    \fwrite(\STDOUT, "ID:$peerId\n");
    exit;
}
$kernel = new TestKernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$user = new User('session-race@example.test', 'Race', 'admin');
$user->passwordHash = 'old-password-hash';
$token = new UsernamePasswordToken($user, 'itk_admin');
$tokens = $c->get('security.token_storage');
$tokens->setToken($token);
$session->start();
$session->set('_security_itk_admin', \serialize($token));
$request = Request::create('/test', 'POST', [], [$session->getName() => $id], [], ['CONTENT_TYPE' => 'application/json'], '{"chatId":"123"}');
$request->setSession($session);
$request->attributes->set('_security_firewall_run', '_security_itk_admin');
$peer = new Process([\PHP_BINARY, __FILE__, 'peer', $dir, $id, $action], timeout: 10);
$peer->start();
$peer->waitUntil(static fn (string $type, string $chunk): bool => \str_contains($chunk, 'READY'));
$finishedDuringIo = false;
$sender = new class(static function () use ($peer, &$finishedDuringIo): void {
    \usleep(250000);
    $finishedDuringIo = !$peer->isRunning();
}) implements MaxSender {
    public function __construct(private readonly \Closure $send)
    {
    }
    public function isConnected(): bool
    {
        return true;
    }
    public function send(int|string $chatId, string $html): void
    {
        ($this->send)();
    }
};
$max = new MaxNotifier($c->get(Schema::class), $c->get(SettingsService::class), $c->get('logger'), $sender, new EventDispatcher(), true);
$controller = new SettingsController($c->get(SettingsService::class), $c->get(Schema::class), $c->get(TelegramNotifier::class), $max, $c->get(Access::class), $c->get(FieldAccess::class), $c->get(SiteMailer::class), $c->get(AdminLocale::class));
try {
    // Characterise the reverted unlock path without putting it back in a production controller.
    $response = 'unsafe' === $mode
        ? (new SessionWork($tokens))->run($request, static fn () => $controller->maxTest($request))
        : $controller->maxTest($request);
} catch (AccessDeniedException) {
    $response = new Response('', 401);
}
$event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
(new ContextListener($tokens, [], 'itk_admin'))->onKernelResponse($event);
(new SessionListener(sessionOptions: ['use_cookies' => true]))->onKernelResponse($event);
$session->save();
$peer->wait();
\preg_match('/ID:([a-zA-Z0-9,-]+)/', $peer->getOutput(), $match);
$peerId = $match[1] ?? '';
$session->setId($peerId);
$session->start();
$latest = \unserialize($session->get('_security_itk_admin'));
$result = ['hash' => $latest->getUser()->passwordHash, 'duringIo' => $finishedDuringIo, 'cookies' => \count($response->headers->getCookies()), 'response' => $response->getStatusCode()];
$session->save();
$kernel->shutdown();
echo \json_encode($result);
