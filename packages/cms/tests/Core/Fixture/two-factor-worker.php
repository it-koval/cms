<?php

declare(strict_types=1);

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\Repository\UserTwoFactorRepository;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Tests\App\TestKernel;

$kernel = new TestKernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$user = $container->get('doctrine.orm.entity_manager')->find(User::class, $argv[1]);
$container->get(UserTwoFactorRepository::class)->ofUser($user); // both requests have already read the initial count
\touch($argv[2] . '/ready-' . $argv[3]);
$deadline = \microtime(true) + 15;
while (!\is_file($argv[2] . '/start')) {
    if (\microtime(true) > $deadline) {
        throw new \RuntimeException('The concurrency test barrier timed out.');
    }
    \usleep(10000);
}
$status = [];
for ($i = 0; $i < 3; ++$i) {
    try {
        $status[] = $container->get(TwoFactorService::class)->verify($user, 'wrong-fixture-code') ? 200 : 422;
    } catch (ContentException $error) {
        $status[] = $error->status();
    }
}
echo \json_encode($status);
$kernel->shutdown();
