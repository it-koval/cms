<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\TelegramConnection;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/settings/bots', name: 'itk_api_bots_')]
final class BotsController extends AbstractApiController
{
    public function __construct(
        private readonly BotSettings $settings,
        private readonly TelegramConnection $telegram,
        private readonly MaxApiSender $max,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->access->require('set:bots', 'view');

        return $this->json($this->payload());
    }

    #[Route('', name: 'save', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $this->access->require('set:bots', 'edit');
        $this->settings->save($this->body($request));
        $this->telegram->reset();

        return $this->json($this->payload());
    }

    /** @return array<string, array<string, mixed>> */
    private function payload(): array
    {
        $result = [];
        foreach (['telegram' => $this->telegram, 'max' => $this->max] as $channel => $connection) {
            $source = $connection->source();
            $ready = 'telegram' === $channel ? $this->telegram->isConnected() : $this->max->isConfigured();
            $result[$channel] = ['tokenSet' => null !== $source, 'source' => $source, 'ready' => $ready];
            if (null !== $problem = $connection->problem(false)) {
                $result[$channel]['problem'] = $problem;
            }
        }

        return $result;
    }
}
