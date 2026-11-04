<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Resource\ActionRunner;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Действия коллекции: свои и встроенные, окно с вопросами, права (canSee, право на коллекцию, строка матрицы). */
final class ActionsTest extends ResourceKernelTestCase
{
    private ActionRunner $runner;

    protected function prepare(): void
    {
        parent::prepare();
        $this->runner = self::getContainer()->get(ActionRunner::class);
    }

    #[Test]
    public function aCustomActionGetsTheRecordsAndTheCheckedInput(): void
    {
        $a = $this->trip('Алтай');
        $b = $this->trip('Байкал');

        $r = $this->runner->run('test_trips', 'tag', [$a['id'], $b['id']], ['label' => 'Хит', 'times' => '3', 'junk' => 'x']);
        self::assertTrue($r->ok);
        self::assertSame('2: Хит x3', $r->message);
        self::assertTrue($r->reload);
    }

    #[Test]
    public function theModalIsCheckedAgainstItsFields(): void
    {
        $a = $this->trip('Алтай');
        try {
            $this->runner->run('test_trips', 'tag', [$a['id']], ['label' => '']);
            self::fail('a required value of the modal is missing');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
            self::assertSame('label', $e->details()[0]['path']);
        }
    }

    #[Test]
    public function anActionOnRowsNeedsRowsButOneAboveTheListDoesNot(): void
    {
        try {
            $this->runner->run('test_trips', 'tag', [], ['label' => 'x']);
            self::fail('rows are needed');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame('ok', $this->runner->run('test_trips', 'secretive', [])->message);
    }

    #[Test]
    public function anUnknownActionOrRecordIsNotFound(): void
    {
        foreach ([['test_trips', 'nope', []], ['test_nope', 'tag', []], ['test_trips', 'tag', ['no-such-id']]] as [$resource, $action, $ids]) {
            try {
                $this->runner->run($resource, $action, $ids, ['label' => 'x']);
                self::fail('not found');
            } catch (ContentException $e) {
                self::assertSame(404, $e->status());
            }
        }
    }

    #[Test]
    public function anActionHiddenByCanSeeCannotBeRunEvenByAnAdministrator(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->runner->run('test_trips', 'hidden', []);
    }

    #[Test]
    public function anActionWithAPermissionLineNeedsTheLine(): void
    {
        self::assertSame('ok', $this->runner->run('test_trips', 'secretive', [])->message, 'the administrator has every line');

        $this->signIn('editor');
        try {
            $this->runner->run('test_trips', 'secretive', []);
            self::fail('the editor has no «Только для админа» line');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $a = $this->trip('А');
        $b = $this->trip('Б');
        self::assertSame('2: Хит x-', $this->runner->run('test_trips', 'tag', [$a['id'], $b['id']], ['label' => 'Хит'])->message, 'the editor runs the others');
    }

    #[Test]
    public function aPersonWithoutTheRightOnTheCollectionCannotRunAnything(): void
    {
        $this->signIn('requests');
        $this->expectException(AccessDeniedException::class);
        $this->runner->run('test_trips', 'fails', []);
    }

    #[Test]
    public function anErrorResultIsReturnedNotThrown(): void
    {
        $r = $this->runner->run('test_trips', 'fails', []);
        self::assertFalse($r->ok);
        self::assertSame('Нечего делать', $r->message);
    }

    #[Test]
    public function theBuiltInActionsPublishUnpublishAndDelete(): void
    {
        $a = $this->trip('Алтай');
        $b = $this->trip('Байкал');
        $ids = [$a['id'], $b['id']];

        $r = $this->runner->run('test_trips', 'publish-selected', $ids);
        self::assertSame('Опубликовано записей: 2', $r->message);
        self::assertTrue($r->reload);
        self::assertCount(2, $this->collections->published('test_trips'));

        $r = $this->runner->run('test_trips', 'unpublish-selected', $ids);
        self::assertSame('Снято с публикации записей: 2', $r->message);
        self::assertSame([], $this->collections->published('test_trips'));

        $r = $this->runner->run('test_trips', 'delete-selected', $ids);
        self::assertSame('Удалено записей: 2', $r->message);
        self::assertSame([], $this->collections->list('test_trips'));
    }

    #[Test]
    public function theBuiltInActionsNeedTheRightTheyAreNamedAfter(): void
    {
        // a custom role that may only edit: it publishes nothing and deletes nothing
        $user = new \Itk\Cms\Entity\User('limited@example.com', 'Limited', 'test-limited');
        $role = new \Itk\Cms\Entity\Role('test-limited', 'Только правка');
        $role->perms = ['col:test_trips' => ['view', 'edit']];
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($role);
        $em->flush();
        try {
            self::getContainer()->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'itk_admin', []));
            $a = $this->trip('Алтай');
            foreach (['publish-selected', 'delete-selected'] as $action) {
                try {
                    $this->runner->run('test_trips', $action, [$a['id']]);
                    self::fail($action . ' needs a right he does not have');
                } catch (AccessDeniedException) {
                    self::addToAssertionCount(1);
                }
            }
            self::assertSame('1: Хит x-', $this->runner->run('test_trips', 'tag', [$a['id']], ['label' => 'Хит'])->message);
        } finally {
            $this->signIn('admin');
            $em->remove($em->getRepository(\Itk\Cms\Entity\Role::class)->find('test-limited'));
            $em->flush();
        }
    }

    #[Test]
    public function tooManyRecordsAreRefused(): void
    {
        $this->expectException(ContentException::class);
        $this->runner->run('test_trips', 'delete-selected', \array_map(static fn (int $i): string => 'id' . $i, \range(1, ActionRunner::MAX_RECORDS + 1)));
    }
}
