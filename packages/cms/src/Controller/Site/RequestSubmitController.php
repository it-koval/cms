<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Support\Url;
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
    public const DEFAULT_MAX_BODY = 262144;

    public function __construct(
        private readonly RequestService $requests,
        #[Autowire(service: 'limiter.itk_request_form')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ContentLocales $contentLocales,
        private readonly AdminLocale $adminLocale,
        #[Autowire(param: 'itk_cms.requests.max_body')]
        private readonly int $maxBody = self::DEFAULT_MAX_BODY,
    ) {
    }

    #[Route('/request/{form}', name: 'itk_request_submit', requirements: ['form' => '[A-Za-z0-9][A-Za-z0-9_\-]*'], methods: ['POST'])]
    public function __invoke(Request $request, string $form): JsonResponse
    {
        // An invalid explicit choice is remembered until after rate limiting and the honeypot.
        $locale = $this->contentLocales->isConfigured() ? $this->locale($request) : ($request->attributes->get('_locale') ?? $this->mainLocale);
        $invalidLocale = null === $locale;
        $locale ??= $this->contentLocales->main();
        $submit = fn () => $this->submit($request, $form, $locale, $invalidLocale);

        return \array_key_exists($locale, I18n::LOCALES)
            ? $this->adminLocale->run($locale, $submit)
            : $submit();
    }

    private function submit(Request $request, string $form, string $locale, bool $invalidLocale): JsonResponse
    {
        $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            return $this->failure(I18n::t('http.requests_too_many'), Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $input = $this->input($request);
            if (!\is_scalar($input[self::HONEYPOT] ?? '') || '' !== \trim((string) ($input[self::HONEYPOT] ?? ''))) {
                return new JsonResponse(['success' => true]); // a robot: pretend it worked, save nothing
            }
            if ($invalidLocale) {
                throw ContentException::invalid(I18n::t('http.no_such_language'));
            }
            $this->requests->submit($form, $input, [
                'ip' => $request->getClientIp(),
                'url' => $request->headers->get('referer'),
                'locale' => $locale,
            ]);
        } catch (ContentException $e) {
            return $this->failure($e->getMessage(), $e->status(), \is_array($e->details()) ? $e->details() : null);
        }

        return new JsonResponse(['success' => true]);
    }

    /** Read at most one byte beyond the limit, before JSON decoding or allocating its values.
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $length = $request->headers->get('Content-Length');
        if (null !== $length && \is_numeric($length) && (float) $length > $this->maxBody) {
            throw new ContentException(I18n::t('http.request_body_too_large'), 413);
        }
        $stream = $request->getContent(true);
        $body = \stream_get_contents($stream, $this->maxBody + 1);
        if (false === $body || \strlen($body) > $this->maxBody) {
            throw new ContentException(I18n::t('http.request_body_too_large'), 413);
        }
        if ('json' !== $request->getContentTypeFormat()) {
            $input = $request->request->all();
        } else {
            // Only a flat object of scalar fields is useful to a form. Check before decoding:
            // many tiny objects otherwise allocate far more memory than the wire body.
            $quoted = false;
            $escaped = false;
            $members = 0;
            $opened = false;
            for ($i = 0, $size = \strlen($body); $i < $size; ++$i) {
                $char = $body[$i];
                if ($quoted) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ('\\' === $char) {
                        $escaped = true;
                    } elseif ('"' === $char) {
                        $quoted = false;
                    }
                    continue;
                }
                if ('"' === $char) {
                    $quoted = true;
                } elseif ('[' === $char || ('{' === $char && $opened)) {
                    throw ContentException::invalid(I18n::t('http.request_bad_input'));
                } elseif ('{' === $char) {
                    $opened = true;
                } elseif (':' === $char && ++$members > RequestService::MAX_FIELDS) {
                    throw ContentException::invalid(I18n::t('http.request_bad_input'));
                }
            }
            try {
                $input = \json_decode($body, true, 2, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ContentException::invalid(I18n::t('http.request_bad_input'));
            }
            if (!\is_array($input) || !\str_starts_with(\ltrim($body), '{')) {
                throw ContentException::invalid(I18n::t('http.request_bad_input'));
            }
        }
        if ('json' === $request->getContentTypeFormat()) {
            RequestService::validateInput($input);
        }

        return $input;
    }

    private function locale(Request $request): ?string
    {
        if ($request->query->has('locale')) {
            $locale = $request->query->all()['locale'];
            if (!\is_string($locale) || !$this->contentLocales->has($locale)) {
                return null;
            }

            return $locale;
        }

        $referer = \parse_url($request->headers->get('referer') ?? '');
        if (\is_array($referer) && isset($referer['scheme'], $referer['host'])) {
            $requestUrl = \parse_url($request->getSchemeAndHttpHost());
            if (\is_array($requestUrl) && isset($requestUrl['scheme'], $requestUrl['host']) && $this->origin($referer) === $this->origin($requestUrl)) {
                return $this->contentLocales->fromPath(Url::fromPath(\rawurldecode($referer['path'] ?? '/')));
            }
        }

        return $this->contentLocales->main();
    }

    /** @param array<string, mixed> $url */
    private function origin(array $url): string
    {
        $scheme = \strtolower($url['scheme']);
        $host = \strtolower($url['host']);
        $port = $url['port'] ?? null;
        if (('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            $port = null;
        }

        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }

    /** @param array<string, mixed>|null $errors */
    private function failure(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message] + (null !== $errors ? ['errors' => $errors] : []), $status);
    }
}
