<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SiteAddress;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Itk\Cms\YandexWebmaster\Model\WebmasterApiException;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\Model\WebmasterFailure;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Itk\Cms\YandexWebmaster\Model\WebmasterState;
use Itk\Cms\YandexWebmaster\Model\WebmasterStatus;
use Itk\Cms\YandexWebmaster\Model\WebmasterVerification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How the site does in Yandex, for the screen «Сегодня». The owner pastes an OAuth token once (connect); the CMS then adds the site to Webmaster, asks Yandex to check the meta tag it puts on the
 * pages itself (Analytics\InjectAnalyticsListener), registers sitemap.xml, and keeps the verification, the summary and the popular queries as a snapshot.
 *
 *   view()          the status from what is stored; no request to Yandex (the health line uses it)
 *   status()        the same, after a refresh when the snapshot is older than 6 hours (15 minutes after a failed one)
 *   refresh()       the same, after a refresh now; it also starts again an ownership check that failed
 *   connect($token) 422 ContentException when the token is empty or refused or the site has no public address, 502 when Yandex does not answer; nothing is stored then
 *   disconnect()    forgets the token, the host and the snapshot; the site stays in Webmaster
 *
 * Two requests that refresh at once both call Yandex and both save: the calls repeat harmlessly, the last write wins, so there is no lock. A refresh writes nothing when
 * the connection it started from is gone or has another token or host by the time Yandex has answered: a disconnect or a new token in the meantime wins.
 *
 * @internal
 */
final class WebmasterService
{
    private const STALE = '6 hours';
    private const RETRY = '15 minutes';
    private const AUTHORIZE = 'https://oauth.yandex.ru/authorize?response_type=token&client_id=';
    private const DASHBOARD = 'https://webmaster.yandex.ru/site/';

    public function __construct(
        private readonly WebmasterClient $client,
        private readonly WebmasterStore $store,
        private readonly SitemapBuilder $sitemap,
        private readonly SecretBox $box,
        #[Autowire(param: 'itk_cms.yandex_webmaster.client_id')]
        private readonly string $clientId,
    ) {
    }

    public function view(): WebmasterStatus
    {
        $connection = $this->store->connection();
        $snapshot = $this->store->snapshot();
        $now = new \DateTimeImmutable();
        $error = null === $snapshot->errorKey ? null : I18n::t($snapshot->errorKey, ['code' => (string) $snapshot->errorCode]);

        return new WebmasterStatus(
            state: $this->stateOf($connection, $snapshot),
            tokenLink: '' === $this->clientId ? null : self::AUTHORIZE . \rawurlencode($this->clientId),
            connectedAt: $connection?->connectedAt,
            tokenExpiresAt: $connection?->tokenExpiresAt(),
            tokenExpiresSoon: null !== $connection && $connection->tokenExpiresSoon($now),
            host: null === $connection ? null : ['id' => $connection->hostId, 'url' => $connection->hostUrl],
            webmasterUrl: null === $connection ? null : self::DASHBOARD . $connection->hostId . '/dashboard/',
            verification: $snapshot->verification,
            summary: $snapshot->summary,
            queries: $snapshot->queries,
            updatedAt: $snapshot->updatedAt,
            error: $error,
        );
    }

    public function status(): WebmasterStatus
    {
        $this->update(false);

        return $this->view();
    }

    public function refresh(): WebmasterStatus
    {
        $this->update(true);

        return $this->view();
    }

    public function connect(string $token): WebmasterStatus
    {
        $token = \trim($token);
        if ('' === $token) {
            throw ContentException::invalid(I18n::t('ops.webmaster_token_empty'));
        }
        $origin = $this->origin();
        $blocked = $this->blocked();
        if (null !== $blocked || null === $origin) {
            throw ContentException::invalid(I18n::t(match ($blocked) {
                WebmasterState::NoHttpClient => 'ops.webmaster_no_client',
                default => 'ops.webmaster_no_address',
            }));
        }
        try {
            $userId = $this->client->userId($token);
            $hostId = $this->hostIdOf($token, $userId, $origin) ?? $this->client->addHost($token, $userId, $origin->origin());
        } catch (WebmasterFailure $e) {
            throw new ContentException(I18n::t($e->messageKey(), ['code' => (string) $e->messageCode()]), $e->isTemporary() ? 502 : 422);
        }
        $connection = new WebmasterConnection($this->box->seal($token), new \DateTimeImmutable(), $userId, $hostId, $origin->unicodeOrigin(), null, false);
        $this->store->saveConnection($connection);
        $this->fetch($connection, new WebmasterSnapshot(), $origin, true);

        return $this->view();
    }

    public function disconnect(): WebmasterStatus
    {
        $this->store->forget();

        return $this->view();
    }

    /** Asks Yandex when the site is connected and can be, the token has not been refused, and the snapshot is due or $force says so. */
    private function update(bool $force): void
    {
        $connection = $this->store->connection();
        $origin = $this->origin();
        if (null === $connection || null === $origin || !$this->client->isAvailable()) {
            return;
        }
        $snapshot = $this->store->snapshot();
        if (!$snapshot->tokenInvalid && ($force || $this->isDue($snapshot))) {
            $this->fetch($connection, $snapshot, $origin, $force);
        }
    }

    private function fetch(WebmasterConnection $connection, WebmasterSnapshot $snapshot, SiteAddress $origin, bool $force): void
    {
        $now = new \DateTimeImmutable();
        $token = $this->box->open($connection->sealedToken);
        if (null === $token) { // APP_SECRET changed: the token cannot be read any more, the owner pastes it again
            $this->store->saveSnapshot(new WebmasterSnapshot($snapshot->verification, $snapshot->summary, $snapshot->queries, $snapshot->updatedAt, $now, null, null, true));

            return;
        }
        $verification = $snapshot->verification;
        $summary = $snapshot->summary;
        $queries = $snapshot->queries;
        $updatedAt = $snapshot->updatedAt;
        $errorKey = $errorCode = null;
        $tokenInvalid = false;
        try {
            $verification = $this->client->verification($token, $connection->userId, $connection->hostId);
            if (null !== $verification->uin && $verification->uin !== $connection->uin) {
                if (!$this->isCurrent($connection)) {
                    return;
                }
                $connection = $connection->withUin($verification->uin);
                $this->store->saveConnection($connection); // the tag is on the pages before Yandex looks for it
            }
            if (WebmasterVerification::NONE === $verification->state || ($force && $verification->isFailed())) {
                $started = $this->client->startVerification($token, $connection->userId, $connection->hostId);
                $verification = new WebmasterVerification($started->state, $started->uin ?? $verification->uin, null);
            }
            if (!$verification->isVerified()) {
                $summary = $queries = null;
            } else {
                if (!$connection->sitemapAdded) {
                    $this->client->addSitemap($token, $connection->userId, $connection->hostId, $origin->origin() . '/sitemap.xml');
                    if (!$this->isCurrent($connection)) {
                        return;
                    }
                    $connection = $connection->withSitemapAdded();
                    $this->store->saveConnection($connection);
                }
                $summary = $this->client->summary($token, $connection->userId, $connection->hostId);
                $queries = $this->client->popularQueries($token, $connection->userId, $connection->hostId);
            }
            $updatedAt = $now;
        } catch (WebmasterFailure $e) {
            $tokenInvalid = $e instanceof WebmasterApiException && $e->isInvalidToken();
            [$errorKey, $errorCode] = $tokenInvalid ? [null, null] : [$e->messageKey(), $e->messageCode()];
        }
        if ($this->isCurrent($connection)) {
            $this->store->saveSnapshot(new WebmasterSnapshot($verification, $summary, $queries, $updatedAt, $now, $errorKey, $errorCode, $tokenInvalid));
        }
    }

    private function isCurrent(WebmasterConnection $connection): bool
    {
        $stored = $this->store->connectionNow();

        return null !== $stored && $stored->sealedToken === $connection->sealedToken && $stored->hostId === $connection->hostId;
    }

    private function isDue(WebmasterSnapshot $snapshot): bool
    {
        return null === $snapshot->checkedAt || $snapshot->checkedAt->modify('+' . (null === $snapshot->errorKey ? self::STALE : self::RETRY)) <= new \DateTimeImmutable();
    }

    private function stateOf(?WebmasterConnection $connection, WebmasterSnapshot $snapshot): WebmasterState
    {
        $blocked = $this->blocked();
        if (null !== $blocked) {
            return $blocked;
        }
        if (null === $connection) {
            return WebmasterState::NotConnected;
        }
        $verification = $snapshot->verification;

        return match (true) {
            $snapshot->tokenInvalid => WebmasterState::TokenInvalid,
            null === $verification => WebmasterState::Verifying,
            $verification->isVerified() => WebmasterState::Ready,
            $verification->isFailed() => WebmasterState::VerificationFailed,
            default => WebmasterState::Verifying,
        };
    }

    private function blocked(): ?WebmasterState
    {
        if (!$this->client->isAvailable()) {
            return WebmasterState::NoHttpClient;
        }

        return null === $this->origin() ? WebmasterState::NoAddress : null;
    }

    /** The address of the site, which is the way Webmaster names a host too (SiteAddress::origin()). Null when the site has none or it is not public. */
    private function origin(): ?SiteAddress
    {
        $address = $this->sitemap->address();

        return null !== $address && $address->isPublic() ? $address : null;
    }

    private function hostIdOf(string $token, int $userId, SiteAddress $origin): ?string
    {
        foreach ($this->client->hosts($token, $userId) as $host) {
            if (SiteAddress::fromUrl($host['url'])?->origin() === $origin->origin()) {
                return $host['id'];
            }
        }

        return null;
    }
}
