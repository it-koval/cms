<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\ActionResult;
use Itk\Cms\Resource\ActionRunner;
use Itk\Cms\Security\Access;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Actions of a collection (Itk\Cms\Resource\Action).
 *   POST resources/{resource}/actions/{action}   { ids?: [], input?: {}, locale? } → { ok, message?, reload?, redirect?, download? }
 *        The server checks that the action is shown to the person and that he has the right it needs (col:<resource> + the line of the matrix).
 *        An error of the action answers 422 { ok: false, error, message }. A file made by the action is handed out once:
 *        `download` is its address (GET downloads/{token}); an action that streams a response answers with that response itself.
 *   GET  downloads/{token}                       the file, once, to the person who ran the action (5 minutes).
 *
 * @internal
 */
#[AsController]
#[Route('/api', name: 'itk_api_actions_')]
final class ActionsController extends AbstractApiController
{
    private const TTL = 300;

    public function __construct(
        private readonly ActionRunner $runner,
        private readonly Access $access,
        private readonly CacheItemPoolInterface $cache,
        private readonly AdminPath $admin,
    ) {
    }

    #[Route('/resources/{resource}/actions/{action}', name: 'run', requirements: ['resource' => '[A-Za-z0-9_\-]+', 'action' => '[a-z0-9][a-z0-9_\-]*'], methods: ['POST'])]
    public function run(Request $request, string $resource, string $action): Response
    {
        $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        $body = $this->body($request);
        $input = $body['input'] ?? [];
        if (!\is_array($input)) {
            throw ContentException::invalid(I18n::t('http.invalid_values'));
        }
        $result = $this->runner->run($resource, $action, [] === ($body['ids'] ?? []) || !isset($body['ids']) ? [] : $this->ids($body['ids']), $input, $this->locale($request, $body));

        if (!$result->ok) {
            return $this->json(['ok' => false, 'error' => $result->message, 'message' => $result->message], 422);
        }
        if ($result->download instanceof StreamedResponse) {
            return $result->download;
        }
        $out = ['ok' => true];
        if (null !== $result->message) {
            $out['message'] = $result->message;
        }
        if ($result->reload) {
            $out['reload'] = true;
        }
        if (null !== $result->redirect) {
            $out['redirect'] = $this->safeRedirect($result->redirect);
        }
        if (\is_string($result->download)) {
            $out['download'] = $this->offer($result);
        }

        return $this->json($out);
    }

    #[Route('/downloads/{token}', name: 'download', requirements: ['token' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function download(string $token): Response
    {
        $user = $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        $item = $this->cache->getItem('itk_download_' . $token);
        $offer = $item->get();
        $this->cache->deleteItem('itk_download_' . $token);
        if (!$item->isHit() || !\is_array($offer) || ($offer['user'] ?? null) !== $user->id || !\is_file((string) $offer['path'])) {
            throw ContentException::notFound(I18n::t('http.action_file_gone'));
        }
        $response = new BinaryFileResponse((string) $offer['path']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $offer['name']);
        $response->deleteFileAfterSend((bool) $offer['delete']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Remembers a file for one download by this person; returns the address. */
    private function offer(ActionResult $result): string
    {
        $path = (string) $result->download;
        if (!\is_file($path) || !\is_readable($path)) {
            throw ContentException::invalid(I18n::t('http.download_not_found'));
        }
        $user = $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        $token = \bin2hex(\random_bytes(20));
        $item = $this->cache->getItem('itk_download_' . $token);
        $item->set(['path' => $path, 'name' => $result->downloadName ?? \basename($path), 'delete' => $result->deleteAfter, 'user' => $user->id]);
        $item->expiresAfter(self::TTL);
        $this->cache->save($item);

        return $this->admin->to('/api/downloads/' . $token);
    }

    /** An address of the admin or a web address; nothing else (no javascript:). */
    private function safeRedirect(string $url): string
    {
        if (\preg_match('~^(/(?![/\\\\])|https?://)~i', $url)) {
            return $url;
        }
        throw new \LogicException(\sprintf('ActionResult::redirect() takes an address of the admin ("/pages/42") or a web address, got "%s".', $url));
    }
}
