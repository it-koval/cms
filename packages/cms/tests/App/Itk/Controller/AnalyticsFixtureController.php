<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A site's own routed controller and layout, including counters installed before the CMS loader. */
final class AnalyticsFixtureController extends AbstractController
{
    #[Route('/test-analytics/controller/{embed}', name: 'app_analytics_fixture', defaults: ['embed' => 'none'])]
    public function page(string $embed): Response
    {
        return $this->render('analytics/controller.html.twig', ['embed' => $embed]);
    }
}
