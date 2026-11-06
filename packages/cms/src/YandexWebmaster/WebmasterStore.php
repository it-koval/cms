<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;

/**
 * Where the connection to Yandex Webmaster is kept: two shared rows of the settings table, outside the schema, so the settings API, the history of settings and the exports never list them
 * (the token inside is sealed on top of that). The snapshot is a row of its own because it is replaced every few hours: it stays out of the fingerprint of the pages (Rendering\PageValidators),
 * the connection (with the code of the meta tag, which shows on every page) is in it.
 *
 * @internal
 */
final class WebmasterStore
{
    public const CONNECTION = 'yandexWebmaster';
    public const SNAPSHOT = 'yandexWebmasterSnapshot';

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function connection(): ?WebmasterConnection
    {
        $data = $this->settings->stored(self::CONNECTION);

        return \is_array($data) ? WebmasterConnection::fromArray($data) : null;
    }

    /** connection() as the database has it at this moment: another request may have disconnected or connected a new token meanwhile. */
    public function connectionNow(): ?WebmasterConnection
    {
        $data = $this->settings->storedNow(self::CONNECTION);

        return \is_array($data) ? WebmasterConnection::fromArray($data) : null;
    }

    public function snapshot(): WebmasterSnapshot
    {
        $data = $this->settings->stored(self::SNAPSHOT);

        return \is_array($data) ? WebmasterSnapshot::fromArray($data) : new WebmasterSnapshot();
    }

    /** The code Yandex gave for the meta tag, or null: read on every public page, so it costs no query of its own. */
    public function uin(): ?string
    {
        return $this->connection()?->uin;
    }

    public function saveConnection(WebmasterConnection $connection): void
    {
        $this->settings->set(self::CONNECTION, $connection->toArray());
    }

    public function saveSnapshot(WebmasterSnapshot $snapshot): void
    {
        $this->settings->set(self::SNAPSHOT, $snapshot->toArray());
    }

    public function forget(): void
    {
        $this->settings->remove(self::CONNECTION);
        $this->settings->remove(self::SNAPSHOT);
    }
}
