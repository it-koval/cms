<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What an action answers: a message (+ reload the list), a file to download, a page to go to, or an error.
 *
 *   ActionResult::message('Скопировано 3 записи')->reload()      ActionResult::redirect('/pages/42')
 *   ActionResult::download('/tmp/tours.csv', 'tours.csv', delete: true)      ActionResult::error('Нечего копировать')
 */
final class ActionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $message = null,
        public readonly bool $reload = false,
        public readonly ?string $redirect = null,
        public readonly string|StreamedResponse|null $download = null,
        public readonly ?string $downloadName = null,
        public readonly bool $deleteAfter = false,
    ) {
    }

    public static function message(string $message): self
    {
        return new self(true, $message);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    /** An address of the admin ('/pages/42') or a full http(s) address. */
    public static function redirect(string $url): self
    {
        return new self(true, null, false, $url);
    }

    /**
     * A file (path on the server) or a response that streams one. A path is handed out once, through a short-lived address;
     * a response is sent as the answer itself.
     */
    public static function download(string|StreamedResponse $file, ?string $name = null, bool $delete = false): self
    {
        return new self(true, null, false, null, $file, $name, $delete);
    }

    /** The list reloads after the action. */
    public function reload(bool $reload = true): self
    {
        return new self($this->ok, $this->message, $reload, $this->redirect, $this->download, $this->downloadName, $this->deleteAfter);
    }

    /** A message to show along with a redirect / download. */
    public function withMessage(string $message): self
    {
        return new self($this->ok, $message, $this->reload, $this->redirect, $this->download, $this->downloadName, $this->deleteAfter);
    }
}
