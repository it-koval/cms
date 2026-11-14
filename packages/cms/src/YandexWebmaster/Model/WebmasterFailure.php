<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * A call to Yandex Webmaster that did not work, in the words the owner sees: the catalog key and the code it names.
 *
 * @internal
 */
interface WebmasterFailure extends \Throwable
{
    /** Worth trying again later: a connect answers 502, not 422. */
    public function isTemporary(): bool;

    /** The catalog key (ops.webmaster_*) of the words for the owner; it may name {code}. */
    public function messageKey(): string;

    public function messageCode(): ?string;
}
