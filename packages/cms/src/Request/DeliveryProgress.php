<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\I18n\I18n;

/**
 * Checkpoints one provider call at a time. Checkpoint failures propagate outside the provider exception handler.
 *
 * @internal
 */
final class DeliveryProgress
{
    /**
     * @param array<string, array{hash: string, state: string}> $parts
     * @param \Closure(string, array{hash: string, state: string}|null): void $save
     */
    public function __construct(private readonly array $parts, private readonly \Closure $save)
    {
    }

    public static function send(?self $progress, string $recipient, int $index, string $text, \Closure $pause, \Closure $send): void
    {
        if (null !== $progress) {
            $progress->checkpoint($recipient, $index, $text, $pause, $send);

            return;
        }
        $pause();
        try {
            $send();
        } catch (DeliveryUncertainException|DeliveryRefusedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            throw new DeliveryRefusedException($failure->getMessage(), previous: $failure);
        }
    }

    private function checkpoint(string $recipient, int $index, string $text, \Closure $pause, \Closure $send): void
    {
        $key = \hash('sha256', $recipient . ':' . $index);
        $hash = \hash('sha256', $text);
        $previous = $this->parts[$key] ?? null;
        if (null !== $previous) {
            if ('sent' === $previous['state'] && $hash === $previous['hash']) {
                return;
            }

            throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
        }

        $pause();
        ($this->save)($key, ['hash' => $hash, 'state' => 'sending']);
        try {
            $send();
        } catch (DeliveryRefusedException $failure) {
            ($this->save)($key, null);
            throw $failure;
        } catch (DeliveryUncertainException $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
        }
        ($this->save)($key, ['hash' => $hash, 'state' => 'sent']);
    }
}
