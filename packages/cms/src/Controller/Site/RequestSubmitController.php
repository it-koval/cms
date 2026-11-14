<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\RequestService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /request/{form}: a form of the site (declared with $s->form() in the schema).
 * Fields as application/x-www-form-urlencoded, multipart or a JSON body. The hidden field `itk_hp` (honeypot) must stay empty.
 * Answer: { "success": true } or { "success": false, "message": "…", "errors": { field: message } }.
 *
 * @internal
 */
#[AsController]
final class RequestSubmitController
{
    public const HONEYPOT = 'itk_hp';

    public function __construct(
        private readonly RequestService $requests,
        #[Autowire(service: 'limiter.itk_request_form')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    #[Route('/request/{form}', name: 'itk_request_submit', requirements: ['form' => '[a-z0-9_\-]+'], methods: ['POST'])]
    public function __invoke(Request $request, string $form): JsonResponse
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            return $this->failure(I18n::t('http.requests_too_many'), Response::HTTP_TOO_MANY_REQUESTS);
        }

        $input = 'json' === $request->getContentTypeFormat() ? (array) \json_decode($request->getContent(), true) : $request->request->all();
        if ('' !== \trim((string) ($input[self::HONEYPOT] ?? ''))) {
            return new JsonResponse(['success' => true]); // a robot: pretend it worked, save nothing
        }

        try {
            $this->requests->submit($form, $input, [
                'ip' => $request->getClientIp(),
                'url' => $request->headers->get('referer'),
                'locale' => $request->attributes->get('_locale', $this->mainLocale),
            ]);
        } catch (ContentException $e) {
            return $this->failure($e->getMessage(), $e->status(), \is_array($e->details()) ? $e->details() : null);
        }

        return new JsonResponse(['success' => true]);
    }

    /** @param array<string, mixed>|null $errors */
    private function failure(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message] + (null !== $errors ? ['errors' => $errors] : []), $status);
    }
}
