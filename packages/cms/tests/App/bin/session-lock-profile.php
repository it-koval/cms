<?php

declare(strict_types=1);

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

use Itk\Cms\Security\SessionWork;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

[$script, $mode, $dir, $id] = $argv;
$session = new Session(new NativeSessionStorage(['use_cookies' => false, 'cache_limiter' => '', 'gc_probability' => 0], new NativeFileSessionHandler($dir)));
$session->setId($id);
if ('peer' === $mode) {
    \fwrite(\STDOUT, "READY\n");
    $start = \hrtime(true);
    $session->start();
    $ms = (\hrtime(true) - $start) / 1e6;
    $session->save();
    \fwrite(\STDOUT, "DONE:$ms\n");
    exit;
}
$session->start();
$session->set('_security_itk_admin', 'signed-in');
$request = new Request();
$request->setSession($session);
$request->attributes->set('_security_firewall_run', '_security_itk_admin');
$peer = new Process([\PHP_BINARY, __FILE__, 'peer', $dir, $id], timeout: 5);
$peer->start();
$peer->waitUntil(static fn (string $type, string $chunk): bool => \str_contains($chunk, 'READY'));
$peerDoneDuringIo = false;
$io = static function () use ($peer, &$peerDoneDuringIo): void {
    \usleep(400000);
    $peerDoneDuringIo = !$peer->isRunning();
};
if ('unlocked' === $mode) {
    (new SessionWork(new TokenStorage()))->run($request, $io);
} else {
    $io();
}
$session->save();
$peer->wait();
\preg_match('/DONE:([0-9.]+)/', $peer->getOutput(), $match);
echo \json_encode(['peerMs' => (float) ($match[1] ?? -1), 'duringIo' => $peerDoneDuringIo]);
