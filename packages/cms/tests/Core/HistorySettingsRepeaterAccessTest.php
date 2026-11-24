<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\Role;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\History\Snapshots;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HistorySettingsRepeaterAccessTest extends WebTestCase
{
    private const GROUP = 'test_history_nested';
    private const FIELD = 'testHistoryRows';

    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends SettingsResource {
            public function key(): string
            {
                return 'test_history_nested';
            }

            public function title(): string
            {
                return 'Nested history';
            }

            public function fields(): iterable
            {
                yield Repeater::make('Rows', 'testHistoryRows')->itemTitle('{secret}', '{shown}')->fields(
                    Text::make('Shown', 'shown'),
                    Text::make('Secret', 'secret')->canSee('set:users'),
                    Text::make('Locked', 'locked')->readonly(),
                    Repeater::make('Children', 'children')->fields(
                        Text::make('Child shown', 'shown'),
                        Text::make('Child secret', 'secret')->canSee('set:users'),
                        Text::make('Child locked', 'locked')->readonly(),
                    ),
                );
            }
        });
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $role = new Role(self::GROUP, 'Nested history editor');
        $role->perms = ['set:' . self::GROUP => ['view', 'edit']];
        $em->persist($role);
        $em->flush();
        $user = self::getContainer()->get(UserManager::class)->create('test-history-nested@example.com', 'Nested history editor', $role->key, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $match);
        $this->csrf = $match[1] ?? '';
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :key')->setParameter('key', self::FIELD)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey = :key')->setParameter('key', self::GROUP)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :email')->setParameter('email', 'test-history-nested%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :key')->setParameter('key', self::GROUP)->execute();
        parent::tearDown();
    }

    #[Test]
    public function listDetailAndComparisonDescribeOnlyVisibleNestedValues(): void
    {
        $before = $this->rows('before');
        $after = $this->rows('after');
        $this->revision($before);
        $revision = $this->revision($after, $before);
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $this->rows('current')[self::FIELD]);

        $list = $this->api('GET', 'history/settings/' . self::GROUP);
        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        foreach ([$list, $entry] as $response) {
            $json = (string) \json_encode($response);
            foreach (['before', 'after', 'current'] as $version) {
                self::assertStringNotContainsString('private-' . $version, $json);
                self::assertStringNotContainsString('nested-private-' . $version, $json);
                self::assertStringNotContainsString('locked-' . $version, $json);
            }
        }
        self::assertNotEmpty($list['items'][0]['summary']);
        self::assertStringContainsString('shown-after', (string) \json_encode($entry['summary']));
        self::assertStringContainsString('shown-current', (string) \json_encode($entry['against']));
    }

    #[Test]
    public function legacyDescriptionWithoutPreviousSnapshotCannotExposeHiddenNestedValues(): void
    {
        $revision = $this->revision($this->rows('legacy'), $this->rows('unrecorded'));
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $this->rows('now')[self::FIELD]);

        $list = $this->api('GET', 'history/settings/' . self::GROUP);
        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        foreach ([$list, $entry] as $response) {
            self::assertStringNotContainsString('private-', (string) \json_encode($response));
        }
        self::assertSame('meta', $entry['summary'][0]['kind']);
        self::assertNotSame('content.history_details_restricted', $entry['summary'][0]['label']);
    }

    #[Test]
    public function hiddenAndReadonlyNestedChangesAreHiddenFromAnEditorButReadableByTheFullAdmin(): void
    {
        $before = $this->rows('same');
        $after = $before;
        $after[self::FIELD][0]['children'][0]['secret'] = 'nested-private-changed';
        $this->revision($before);
        $revision = $this->revision($after, $before);
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $before[self::FIELD]);

        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        self::assertSame([], $entry['summary']);
        self::assertSame([], $entry['against']);
        self::assertTrue($entry['same']);

        $locked = $after;
        $locked[self::FIELD][0]['children'][0]['locked'] = 'readable-readonly-change';
        $revision = $this->revision($locked, $after);
        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        self::assertSame([], $entry['summary']);
        self::assertSame([], $entry['against']);
        self::assertTrue($entry['same']);
        self::assertStringNotContainsString('private-', (string) \json_encode($entry));
        $admin = self::getContainer()->get(UserManager::class)->create('test-history-nested-admin@example.com', 'History admin', 'admin', 'password-for-test');
        $this->client->loginUser($admin, 'itk_admin');
        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        self::assertStringContainsString('readable-readonly-change', (string) \json_encode($entry['summary']));
    }

    #[Test]
    public function legacyDescriptionCannotExposeAFieldRemovedFromTheCurrentRepeaterSchema(): void
    {
        $field = self::getContainer()->get(Schema::class)->settingsField(self::FIELD);
        self::assertInstanceOf(Repeater::class, $field);
        $field->fields(Text::make('Shown', 'shown'), Text::make('Removed', 'removed'))->itemTitle('{removed}');
        $before = [self::FIELD => [['_id' => 'row', 'shown' => 'before', 'removed' => 'obsolete-private-before']]];
        $after = [self::FIELD => [['_id' => 'row', 'shown' => 'after', 'removed' => 'obsolete-private-after']]];
        $revision = $this->revision($after, $before);
        $field->fields(Text::make('Shown', 'shown'));
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $before[self::FIELD]);

        foreach ([$this->api('GET', 'history/settings/' . self::GROUP), $this->api('GET', 'history/revision/' . $revision->id)] as $response) {
            self::assertStringNotContainsString('obsolete-private-', (string) \json_encode($response));
        }
    }

    #[Test]
    public function removedNestedValuesCannotCreateHistoryDifferences(): void
    {
        $field = self::getContainer()->get(Schema::class)->settingsField(self::FIELD);
        self::assertInstanceOf(Repeater::class, $field);
        $field->fields(Text::make('Shown', 'shown'));
        $before = [self::FIELD => [['_id' => 'row', 'shown' => 'same', 'removed' => 'obsolete-private-before']]];
        $after = [self::FIELD => [['_id' => 'row', 'shown' => 'same', 'removed' => 'obsolete-private-after']]];
        $this->revision($before);
        $revision = $this->revision($after, $before);
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $before[self::FIELD]);

        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        self::assertSame([], $entry['summary']);
        self::assertSame([], $entry['against']);
        self::assertTrue($entry['same']);
    }

    #[Test]
    public function prunedDescriptionCannotExposeOldChildrenWhenARepeaterBecomesText(): void
    {
        $revision = $this->revision($this->rows('old'), $this->rows('unrecorded'));
        self::getContainer()->get(Schema::class)->settingsGroups()[self::GROUP]->fields(Text::make('Plain value', self::FIELD));
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, 'current plain text');

        $list = $this->api('GET', 'history/settings/' . self::GROUP);
        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        foreach ([$list, $entry] as $response) {
            self::assertStringNotContainsString('private-', (string) \json_encode($response));
            self::assertStringNotContainsString('locked-', (string) \json_encode($response));
        }
        self::assertSame('meta', $entry['summary'][0]['kind']);
    }

    #[Test]
    public function textFieldsCannotRenderNonScalarHistoricDataAsLinksOrImages(): void
    {
        self::getContainer()->get(Schema::class)->settingsGroups()[self::GROUP]->fields(Text::make('Plain value', self::FIELD));
        $before = [self::FIELD => 'old plain text'];
        $this->revision($before);
        $revision = $this->revision([self::FIELD => ['kind' => 'url', 'url' => 'malformed-private-url']], $before);
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, 'current plain text');

        $entry = $this->api('GET', 'history/revision/' . $revision->id);
        self::assertStringNotContainsString('malformed-private-url', (string) \json_encode($entry));
        self::assertStringContainsString('current plain text', (string) \json_encode($entry['against']));
    }

    #[Test]
    public function restoringARepeaterKeepsCurrentProtectedLeavesByRowIdentity(): void
    {
        $old = $this->rows('old');
        $old[self::FIELD][] = ['_id' => 'other', 'shown' => 'other-old', 'secret' => 'other-old-secret', 'locked' => 'other-old-locked', 'children' => []];
        $revision = $this->revision($old);
        $current = $this->rows('current');
        \array_unshift($current[self::FIELD], ['_id' => 'other', 'shown' => 'other-current', 'secret' => 'other-current-secret', 'locked' => 'other-current-locked', 'children' => []]);
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $current[self::FIELD]);

        $this->api('POST', 'history/revision/' . $revision->id . '/restore');
        $rows = self::getContainer()->get(SettingsService::class)->get(self::FIELD);
        self::assertSame('shown-old', $rows[0]['shown']);
        self::assertSame('private-current', $rows[0]['secret']);
        self::assertSame('locked-current', $rows[0]['locked']);
        self::assertSame('nested-shown-old', $rows[0]['children'][0]['shown']);
        self::assertSame('nested-private-current', $rows[0]['children'][0]['secret']);
        self::assertSame('nested-locked-current', $rows[0]['children'][0]['locked']);
        self::assertSame('other-current-secret', $rows[1]['secret']);
        self::assertSame('other-current-locked', $rows[1]['locked']);
    }

    #[Test]
    public function restoreRetainsCurrentRowsWhoseRemovalWouldLoseProtectedValues(): void
    {
        $old = $this->rows('old');
        $old[self::FIELD][] = ['_id' => 'historic-only', 'shown' => 'old shown', 'secret' => 'historic-secret', 'locked' => 'historic-locked', 'children' => []];
        $revision = $this->revision($old);
        $current = $this->rows('current');
        $current[self::FIELD][0]['children'][] = ['_id' => 'new-child', 'shown' => 'new child', 'secret' => 'new child private', 'locked' => 'new child locked'];
        $current[self::FIELD][] = ['_id' => 'new-parent', 'shown' => 'new parent', 'secret' => 'new parent private', 'locked' => '', 'children' => []];
        $current[self::FIELD][] = ['shown' => 'legacy row without id', 'secret' => '', 'locked' => 'legacy locked', 'children' => []];
        $current[self::FIELD][] = ['_id' => 'unprotected', 'shown' => 'can remove', 'secret' => '', 'locked' => '', 'children' => []];
        self::getContainer()->get(SettingsService::class)->set(self::FIELD, $current[self::FIELD]);

        $this->api('POST', 'history/revision/' . $revision->id . '/restore');
        $rows = self::getContainer()->get(SettingsService::class)->get(self::FIELD);
        self::assertCount(4, $rows);
        self::assertSame('new child private', $rows[0]['children'][1]['secret']);
        self::assertSame('new child locked', $rows[0]['children'][1]['locked']);
        self::assertArrayNotHasKey('secret', $rows[1], 'a historical row cannot reintroduce an old hidden value');
        self::assertArrayNotHasKey('locked', $rows[1], 'a historical row cannot reintroduce an old readonly value');
        self::assertSame('new parent private', $rows[2]['secret']);
        self::assertSame('legacy locked', $rows[3]['locked']);
        self::assertNotContains('unprotected', \array_column($rows, '_id'));
    }

    /** @return array<string, mixed> */
    private function rows(string $version): array
    {
        return [self::FIELD => [[
            '_id' => 'row', 'shown' => 'shown-' . $version, 'secret' => 'private-' . $version, 'locked' => 'locked-' . $version,
            'children' => [['_id' => 'child', 'shown' => 'nested-shown-' . $version, 'secret' => 'nested-private-' . $version, 'locked' => 'nested-locked-' . $version]],
        ]]];
    }

    /** @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $previous
     */
    private function revision(array $snapshot, ?array $previous = null): Revision
    {
        $revision = new Revision(Revision::SETTINGS, self::GROUP, self::GROUP, self::getContainer()->get(Schema::class)->mainLocale(), Revision::EDIT);
        $revision->snapshot = $snapshot;
        $revision->summary = self::getContainer()->get(Snapshots::class)->summary(Revision::SETTINGS, self::GROUP, Revision::EDIT, $snapshot, $previous);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($revision);
        $em->flush();

        return $revision;
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
