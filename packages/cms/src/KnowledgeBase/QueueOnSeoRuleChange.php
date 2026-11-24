<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Event\SeoRulesChanged;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Itk\Cms\Repository\PageTranslationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * SEO rules take effect immediately; read all live versions, including those currently excluded by noindex.
 *
 * @internal
 */
#[AsEventListener]
final class QueueOnSeoRuleChange
{
    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly KnowledgeBaseOptions $options,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SeoRulesChanged $event): void
    {
        if (!$this->options->enabled) {
            return;
        }
        try {
            foreach ($this->translations->findAllLive() as $translation) {
                if (!$this->options->indexes(SourceKind::Page, $translation->page->template, $translation->locale)) {
                    continue;
                }
                $url = (string) ($translation->published['url'] ?? $translation->publishedUrl);
                foreach ($event->rules as $rule) {
                    if (SeoResolver::covers($rule, $url, [], $translation->locale)) {
                        $this->bus->dispatch(new SyncKnowledge('page', $translation->page->id, $translation->locale));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Knowledge base sync after an SEO rule change was not queued: {error}', ['error' => $e->getMessage()]);
        }
    }
}
