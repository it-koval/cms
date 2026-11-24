<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\PageService;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /admin/preview/{id}?locale=ru: the DRAFT of a page rendered with the site's own Twig templates, with data-f / data-sec markers and
 * /bundles/itkcms/preview.js (the postMessage protocol is described in that file). It is the canvas of the editor (an iframe).
 * A person who may not edit the page gets window.ITK_PREVIEW = { readonly: true } (no section actions).
 *
 * The page is drawn in the language of the site and of ?locale=, as a visitor gets it: AdminLocaleListener does not switch this route. What the editor reads around it is his own:
 * preview.js has no catalogue, so its words come in window.ITK_PREVIEW.texts; a new word goes into texts() and translations/itk_preview.<locale>.yaml.
 *
 * @internal
 */
#[AsController]
final class PreviewController
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly PageService $pages,
        private readonly Access $access,
        private readonly AdminLocale $adminLocale,
        private readonly ContentLocales $contentLocales,
    ) {
    }

    #[Route('/preview/{id}', name: 'itk_admin_preview', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function __invoke(Request $request, string $id): Response
    {
        $locale = $request->query->get('locale');
        $user = $this->access->user();
        try {
            [$template, $locale] = $this->adminLocale->runFor($user, function () use ($id, $locale): array {
                try {
                    if (null !== $locale && !\is_string($locale)) {
                        throw ContentException::invalid(I18n::t('http.no_such_language'));
                    }
                    $locale = $this->contentLocales->assertUsable($locale);
                } catch (ContentException $e) {
                    if ($this->contentLocales->isConfigured()) {
                        throw $e;
                    }
                    $locale = $this->contentLocales->main();
                }
                $page = $this->pages->find($id);
                $template = $page->template;
                $this->access->require('pages', 'view', $template);
                // said here, in the language of the person: the renderer below draws in the language of the site
                if (null === $page->translation($locale)) {
                    throw new ContentException(I18n::t(PageService::NO_VERSION), 404);
                }

                return [$template, $locale];
            });
            $response = $this->renderer->renderPreview($id, $locale);
        } catch (ContentException $e) {
            return $this->problem($e->getMessage(), $e->status());
        }

        $flags = \json_encode(['readonly' => !$this->access->can('pages', 'edit', $template), 'page' => $id, 'texts' => $this->adminLocale->runFor($user, self::texts(...))], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        $script = '<script>window.ITK_PREVIEW=' . $flags . ';</script>';
        $html = (string) $response->getContent();
        $pos = \stripos($html, '</head>');
        $html = false === $pos ? $script . $html : \substr_replace($html, $script, $pos, 0);
        $response->setContent($html);
        // the draft may be framed only by the admin itself
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }

    /**
     * The words of preview.js. {label} stays in the text: the script puts the name of the section there.
     *
     * @return array<string, string>
     */
    private static function texts(): array
    {
        return [
            'edit' => I18n::t('preview.edit'),
            'view' => I18n::t('preview.view'),
            'edit_section' => I18n::t('preview.edit_section'),
            'view_section' => I18n::t('preview.view_section'),
            'section_actions' => I18n::t('preview.section_actions'),
            'move_up' => I18n::t('preview.move_up'),
            'move_down' => I18n::t('preview.move_down'),
            'duplicate' => I18n::t('preview.duplicate'),
            'hide' => I18n::t('preview.hide'),
            'delete' => I18n::t('preview.delete'),
            'unpublished' => I18n::t('preview.unpublished'),
        ];
    }

    private function problem(string $message, int $status): Response
    {
        [$lang, $title] = $this->adminLocale->runFor($this->access->user(), static fn (): array => [I18n::locale(), I18n::t('preview.unavailable')]);
        $html = '<!doctype html><html lang="' . $lang . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . \htmlspecialchars($title, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<body style="margin:0;display:grid;place-items:center;min-height:100vh;font:16px/1.5 system-ui,sans-serif;color:#14181c;background:#f2f4f5">'
            . '<p style="max-width:28em;padding:24px;text-align:center">' . \htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';

        return new Response($html, $status >= 400 && $status < 600 ? $status : 404, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
