<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Controller\Admin\SettingsController;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

final class NotificationRouteJsonTest extends ResourceKernelTestCase
{
    #[Test]
    public function emptyAndNumericFormRoutesAreJsonObjectsOnGetAndPatch(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();
        try {
            $settings = self::getContainer()->get(SettingsService::class);
            $controller = self::getContainer()->get(SettingsController::class);
            foreach ([[], ['0' => '-10012345']] as $telegramRoutes) {
                $routes = ['tgRoutes' => $telegramRoutes, 'maxRoutes' => [] === $telegramRoutes ? [] : ['0' => '-23'], 'mailRoutes' => [] === $telegramRoutes ? [] : ['0' => 'numeric@example.test']];
                foreach ($routes as $key => $value) {
                    $settings->set('itk:' . $key, $value);
                }
                foreach (['', '?scope=notifications'] as $scope) {
                    $get = $controller->get(Request::create('/admin/api/settings' . $scope));
                    $patch = $controller->update(Request::create('/admin/api/settings' . $scope, 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: \json_encode($routes, \JSON_THROW_ON_ERROR)));
                    foreach ([$get, $patch] as $response) {
                        $body = \json_decode($response->getContent(), false, flags: \JSON_THROW_ON_ERROR);
                        foreach ($routes as $key => $value) {
                            self::assertInstanceOf(\stdClass::class, $body->{$key}, $key . ' must be a JSON object, even for an empty map or the form key "0"');
                            self::assertSame($value, (array) $body->{$key});
                        }
                    }
                }
            }
        } finally {
            $em->getConnection()->rollBack();
            $em->clear();
        }
    }
}
