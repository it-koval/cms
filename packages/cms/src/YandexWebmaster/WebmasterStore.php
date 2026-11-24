<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Where the connection to Yandex Webmaster is kept. The connection is a shared row of the settings table outside the schema, so the settings API, the history of settings and the exports
 * never list it (the token inside is sealed on top of that); it holds the code of the meta tag, which shows on every page, so it is in the fingerprint of the pages (Rendering\PageValidators).
 * The snapshot is replaced every few hours and shows on no page, so it lives in the cache pool: a lost entry only means the next status() asks Yandex again.
 *
 * @internal
 */
final class WebmasterStore
{
    public const CONNECTION = 'itk:yandexWebmaster';
    private const SNAPSHOT = 'itk_yandex_webmaster_snapshot';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CacheItemPoolInterface $cache,
    ) {
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
        $data = $this->cache->getItem(self::SNAPSHOT)->get();

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
        $this->cache->save($this->cache->getItem(self::SNAPSHOT)->set($snapshot->toArray()));
    }

    public function forget(): void
    {
        $this->settings->remove(self::CONNECTION);
        $this->cache->deleteItem(self::SNAPSHOT);
    }
}
