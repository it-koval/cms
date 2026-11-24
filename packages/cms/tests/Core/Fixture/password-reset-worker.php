<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Itk\Cms\Content\ContentException;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Tests\App\TestKernel;

if (!isset($argv[1], $argv[2])) {
    throw new \InvalidArgumentException('Expected barrier directory and worker ID.');
}
[, $dir, $workerId] = $argv;
$kernel = new TestKernel('test', true);
$kernel->boot();
$recovery = $kernel->getContainer()->get('test.service_container')->get(PasswordRecovery::class);
$token = (string) \file_get_contents($dir . '/token');
if (null === $recovery->find($token)) {
    throw new \RuntimeException('Synthetic link must exist before the barrier.');
}
\touch($dir . '/ready-' . $workerId);
$deadline = \microtime(true) + 15;
while (!\is_file($dir . '/start')) {
    if (\microtime(true) > $deadline) {
        throw new \RuntimeException('The concurrency barrier timed out.');
    }
    \usleep(10000);
}
try {
    $password = 'innocuous-worker-password-' . $workerId;
    $recovery->complete($token, $password, $password);
    echo '200';
} catch (ContentException $error) {
    echo $error->status();
}
$kernel->shutdown();
