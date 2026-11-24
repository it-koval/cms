<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Requests (заявки). Item: see RequestService::present() (id, dt, locale, form, place, phone, name, city, note, st 'delivered'|'failed', work 'new'|'progress'|'closed'|'spam', comment, log).
 *   GET requests?status=&form=&q=&period=&from=&to=&locale=&page=&perPage=   { items, total, page, perPage }. status: a work status or 'failed'. form: the form key or its title
 *   GET requests/counts { total, new, failed }     GET requests/forms ['Заявка на звонок', …]     GET requests/{id}
 *   PATCH requests/{id} { work?, comment? } item   POST requests/{id}/resend { channel? } item (delivery tried again right now: to the messenger 'telegram' | 'max' asked for, else to each one that failed). Item: channels [{channel, state 'pending'|'sent'|'failed', error}].
 *
 * @internal
 */
#[AsController]
#[Route('/api/requests', name: 'itk_api_requests_')]
final class RequestsController extends AbstractApiController
{
    public function __construct(
        private readonly RequestService $requests,
        private readonly Schema $schema,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->access->require('requests', 'view');
        $q = $request->query;
        $form = (string) $q->get('form', '');
        foreach ($this->schema->forms() as $def) { // the filter of the screen sends the title of the form
            if ('' !== $form && $def->formTitle() === $form) {
                $form = $def->key();
            }
        }
        [$page, $perPage, $offset] = $this->listPage($request);
        $rows = $this->requests->page([
            'status' => (string) $q->get('status', ''), 'form' => $form, 'q' => (string) $q->get('q', ''), 'period' => (string) $q->get('period', ''),
            'from' => (string) $q->get('from', ''), 'to' => (string) $q->get('to', ''), 'locale' => (string) $q->get('locale', ''),
        ], $offset, $perPage, (string) $q->get('cursor', ''));

        return $this->json(['items' => \array_map($this->requests->present(...), $rows['items']), 'page' => $page, 'perPage' => $perPage] + $rows);
    }

    #[Route('/counts', name: 'counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $this->access->require('requests', 'view');

        return $this->json($this->requests->counts());
    }

    #[Route('/forms', name: 'forms', methods: ['GET'])]
    public function forms(): JsonResponse
    {
        $this->access->require('requests', 'view');

        return $this->json($this->requests->forms());
    }

    #[Route('/{id}', name: 'get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $this->access->require('requests', 'view');

        return $this->json($this->requests->card($this->requests->find($id)));
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require('requests', 'edit');
        $body = $this->body($request);
        $work = isset($body['work']) ? (string) $body['work'] : null;
        $comment = \array_key_exists('comment', $body) ? (string) $body['comment'] : null;

        return $this->json($this->requests->card($this->requests->update($id, $work, $comment)));
    }

    #[Route('/{id}/resend', name: 'resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resend(Request $request, string $id): JsonResponse
    {
        $this->access->require('requests', 'edit');

        return $this->json($this->requests->card($this->requests->resend($id, $this->deliveryChannel($request))));
    }
}
