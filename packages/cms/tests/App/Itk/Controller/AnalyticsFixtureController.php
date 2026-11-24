<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Controller;

use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Security\CspNonce;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A site's own routed controller and layout, including counters installed before the CMS loader. */
final class AnalyticsFixtureController extends AbstractController
{
    #[Route('/test-analytics/controller/{embed}', name: 'app_analytics_fixture', defaults: ['embed' => 'none'])]
    #[Route('/kk/test-analytics/controller/{embed}', name: 'app_analytics_kk_fixture', defaults: ['embed' => 'none'])]
    #[Route('/kk/test-analytics/explicit-locale', name: 'app_analytics_explicit_locale_fixture', defaults: ['embed' => 'none', '_locale' => 'ru'])]
    #[Route('/kk/кафе', name: 'app_analytics_cyrillic_fixture', defaults: ['embed' => 'none'])]
    public function page(string $embed): Response
    {
        return $this->render('analytics/controller.html.twig', ['embed' => $embed]);
    }

    #[Route('/test-analytics/nonce', name: 'app_analytics_nonce_fixture')]
    public function nonce(Request $request, RenderState $state): Response
    {
        $nonce = \base64_encode(\random_bytes(18));
        $request->attributes->set(CspNonce::ATTRIBUTE, $nonce);
        $state->critical = CriticalStyles::fromStored([['media' => null, 'css' => 'body{color:#222}']]);
        try {
            $response = $this->render('analytics/nonce.html.twig');
        } finally {
            $state->reset();
        }
        $response->headers->set('Content-Security-Policy', "script-src 'nonce-" . $nonce . "' 'strict-dynamic'; style-src 'nonce-" . $nonce . "'");

        return $response;
    }
}
