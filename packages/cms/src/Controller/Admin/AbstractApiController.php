<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Helpers shared by the controllers of the admin JSON API (/admin/api/*). Errors are ContentException / AccessDeniedException: ApiErrorListener turns them into { error, details? }.
 *
 * @internal
 */
abstract class AbstractApiController
{
    protected ContentLocales $contentLocales;

    #[Required]
    public function setContentLocales(ContentLocales $contentLocales): void
    {
        $this->contentLocales = $contentLocales;
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The request body as an array: JSON, or form fields.
     *
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
        if ('json' === $request->getContentTypeFormat() || \str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            $raw = \trim($request->getContent());
            if ('' === $raw) {
                return [];
            }
            $data = \json_decode($raw, true);
            if (!\is_array($data)) {
                throw new ContentException(I18n::t('http.request_unreadable'), 400);
            }

            return $data;
        }

        return $request->request->all();
    }

    /**
     * The content language of the call: ?locale=, else "locale" of the body, else null (the main language).
     *
     * @param array<string, mixed> $body
     */
    protected function locale(Request $request, array $body = []): ?string
    {
        $locale = $request->query->get('locale', $body['locale'] ?? null);
        if (null === $locale || '' === $locale) {
            return null;
        }
        if (!\is_string($locale)) {
            throw ContentException::invalid(I18n::t('http.no_such_language'));
        }

        return $this->contentLocales->assertUsable($locale);
    }

    /** @return array{string, ?string} the target and optional source of a new translation */
    protected function translationInput(Request $request): array
    {
        if (!\is_string($request->query->all()['locale'] ?? null) || null === ($locale = $this->locale($request))) {
            throw ContentException::invalid(I18n::t('http.no_such_language'));
        }
        $from = $this->body($request)['from'] ?? null;
        if (null !== $from && !\is_string($from)) {
            throw ContentException::invalid(I18n::t('content.translation_source_invalid'));
        }

        return [$locale, $from];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the body without the call-level keys that are not content
     */
    protected function without(array $body, string ...$keys): array
    {
        foreach ($keys as $k) {
            unset($body[$k]);
        }

        return $body;
    }

    /**
     * The version of the draft the editor loaded (`baseVersion` of the body), or null: a call without it is not checked.
     *
     * @param array<string, mixed> $body
     */
    protected function baseVersion(array $body): ?string
    {
        $version = $body['baseVersion'] ?? null;
        if (null === $version || '' === $version) {
            return null;
        }
        if (!\is_string($version)) {
            throw ContentException::invalid(I18n::t('http.version_unreadable'));
        }

        return $version;
    }

    /** An optional channel for a resend. Only omitting it selects the default recipients. */
    protected function deliveryChannel(Request $request): ?string
    {
        $body = $this->body($request);
        if (!\array_key_exists('channel', $body)) {
            return null;
        }
        if (!\is_string($body['channel'])) {
            throw ContentException::invalid(I18n::t('http.request_bad_channel'));
        }

        return $body['channel'];
    }

    /** @return list<string> */
    protected function ids(mixed $value): array
    {
        if (!\is_array($value)) {
            throw ContentException::invalid(I18n::t('http.records_list_needed'));
        }

        return \array_values(\array_map('strval', \array_filter($value, 'is_scalar')));
    }
}
