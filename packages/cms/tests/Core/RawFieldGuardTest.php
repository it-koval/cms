<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\RawFieldGuard;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Field\Textarea;
use Itk\Cms\History\ChangeSource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Tests\Core\Fixture\RawPlacesSection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Сырой HTML (поле ->raw(), секция «html») могут добавлять и менять только администраторы. */
final class RawFieldGuardTest extends KernelTestCase
{
    private RawFieldGuard $guard;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new RawPlacesSection()); // before the schema is built
        $this->guard = self::getContainer()->get(RawFieldGuard::class);
    }

    private function signIn(string $role): void
    {
        $user = new User($role . '@example.test', $role, $role);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'itk_admin', []));
    }

    /** @return list<array<string, mixed>> */
    private function sections(string $html): array
    {
        return [['id' => 's1', 'type' => 'html', 'hidden' => false, 'data' => ['html' => $html]]];
    }

    #[Test]
    public function anEditorCannotAddOrChangeRawHtml(): void
    {
        $this->signIn(Role::EDITOR);
        $this->expectException(ContentException::class);
        $this->guard->assertSections($this->sections('<p>old</p>'), $this->sections('<p>old</p><script>alert(1)</script>'));
    }

    #[Test]
    public function anEditorCannotAddARawSection(): void
    {
        $this->signIn(Role::EDITOR);
        $this->expectException(ContentException::class);
        $this->guard->assertSections([], $this->sections('<script>alert(1)</script>'));
    }

    #[Test]
    public function anEditorMayKeepOrRemoveExistingRawHtml(): void
    {
        $this->signIn(Role::EDITOR);
        $this->guard->assertSections($this->sections('<p>old</p>'), $this->sections('<p>old</p>'));
        $this->guard->assertSections($this->sections('<p>old</p>'), []);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anAdministratorMayChangeRawHtml(): void
    {
        $this->signIn(Role::ADMIN);
        $this->guard->assertSections([], $this->sections('<script>1</script>'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theConsoleIsNotChecked(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $this->guard->assertSections([], $this->sections('<script>1</script>'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function throughAnAssistantNobodyAddsOrChangesRawHtmlAdministratorsIncluded(): void
    {
        $this->signIn(Role::ADMIN);
        $source = self::getContainer()->get(ChangeSource::class);
        $source->begin(ChangeSource::MCP, 'Claude');
        try {
            $this->guard->assertSections($this->sections('<p>old</p>'), $this->sections('<p>old</p>'));
            $this->guard->assertSections($this->sections('<p>old</p>'), []);
            try {
                $this->guard->assertSections($this->sections('<p>old</p>'), $this->sections('<script>1</script>'));
                self::fail('a key may not write raw HTML');
            } catch (ContentException $e) {
                self::assertSame(403, $e->status());
                self::assertStringContainsString('ИИ-ассистент', $e->getMessage());
            }
            $this->expectException(ContentException::class);
            $this->guard->assertSections([], $this->sections('<div>new</div>'));
        } finally {
            $source->clear();
        }
    }

    /** Runs what is given as a call of an assistant under the key of an administrator. */
    private function throughAnAssistant(\Closure $call): void
    {
        $this->signIn(Role::ADMIN);
        $source = self::getContainer()->get(ChangeSource::class);
        $source->begin(ChangeSource::MCP, 'Claude');
        try {
            $call();
        } finally {
            $source->clear();
        }
    }

    /**
     * @param array<string, string> $html section id => code
     *
     * @return list<array<string, mixed>>
     */
    private function htmlSections(array $html): array
    {
        return \array_map(static fn (string $id, string $code): array => ['id' => $id, 'type' => 'html', 'hidden' => false, 'data' => ['html' => $code]], \array_keys($html), $html);
    }

    /**
     * @param list<array<string, string>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function places(array $items): array
    {
        return [['id' => 's1', 'type' => 'test_raw_places', 'hidden' => false, 'data' => ['places' => $items]]];
    }

    /** @param list<array<string, mixed>> $before @param list<array<string, mixed>> $after */
    private function assertRefused(array $before, array $after, string $what): void
    {
        try {
            $this->guard->assertSections($before, $after);
            self::fail($what . ' is a change of the code');
        } catch (ContentException $e) {
            self::assertSame(403, $e->status(), $what);
            self::assertStringContainsString('ИИ-ассистент', $e->getMessage(), $what);
        }
    }

    #[Test]
    public function throughAnAssistantACodeStaysInItsPlaceNotOnlyOnThePage(): void
    {
        $this->throughAnAssistant(function (): void {
            $two = $this->htmlSections(['s1' => '<p>a</p>', 's2' => '<p>b</p>']);
            $this->assertRefused($two, $this->htmlSections(['s1' => '', 's2' => '<p>b</p>']), 'clearing a field');
            $this->assertRefused($two, $this->htmlSections(['s1' => "  \n", 's2' => '<p>b</p>']), 'blanking a field');
            $this->assertRefused($two, $this->htmlSections(['s1' => '<p>a</p>', 's2' => '<p>a</p>']), 'copying a code over another');
            $this->assertRefused($two, $this->htmlSections(['s1' => '<p>b</p>', 's2' => '<p>a</p>']), 'swapping two codes');
            $this->assertRefused($two, $this->htmlSections(['s1' => '<p>a</p>', 's2' => '<p>b</p>', 's3' => '<p>a</p>']), 'a new section with an existing code');

            $this->guard->assertSections($two, $two);
            $this->guard->assertSections($two, $this->htmlSections(['s2' => '<p>b</p>', 's1' => '<p>a</p>'])); // moved
            $this->guard->assertSections($two, $this->htmlSections(['s2' => '<p>b</p>']));                     // a section taken away
            $this->guard->assertSections($two, $this->htmlSections(['s1' => '<p>a</p>', 's2' => '<p>b</p>', 's3' => ''])); // a new empty one
            $this->addToAssertionCount(1);
        });
    }

    #[Test]
    public function throughAnAssistantAnItemOfAListKeepsItsCode(): void
    {
        $this->throughAnAssistant(function (): void {
            $a = ['_id' => 'p1', 'city' => 'Рим', 'marker' => '<b>a</b>'];
            $b = ['_id' => 'p2', 'city' => 'Милан', 'marker' => '<b>b</b>'];
            $two = $this->places([$a, $b]);
            $this->assertRefused($two, $this->places([['marker' => ''] + $a, $b]), 'clearing the code of an item');
            $this->assertRefused($two, $this->places([$a, ['marker' => '<b>a</b>'] + $b]), 'copying the code to another item');
            $this->assertRefused($two, $this->places([$a, $b, ['_id' => 'p3', 'marker' => '<b>a</b>']]), 'a new item with an existing code');
            $this->assertRefused($two, $this->places([$a, ['marker' => '<b>b</b>'] + $a, $b]), 'a second item that claims the _id of the first');
            $this->assertRefused($two, $this->places([$a, $a, $b]), 'the same item twice, its code included');

            $this->guard->assertSections($two, $this->places([['city' => 'Турин'] + $a, $b]));
            $this->guard->assertSections($two, $this->places([$b, $a]));                       // moved
            $this->guard->assertSections($two, $this->places([$b]));                           // an item taken away
            $this->guard->assertSections($two, $this->places([$a, $b, ['_id' => 'p3', 'city' => 'Генуя']]));
            $this->guard->assertSections($this->places([$a, $a, $b]), $this->places([$a, $a, $b])); // a twin that is already there does not block other edits
            $this->addToAssertionCount(1);
        });
    }

    /** A save gives an old item (or section) without an id a new one: the code stays where it was, so it is no change. */
    #[Test]
    public function throughAnAssistantASaveThatNamesAnOldItemChangesNothing(): void
    {
        $this->throughAnAssistant(function (): void {
            $old = $this->places([['city' => 'Рим', 'marker' => '<b>old</b>']]);
            $this->guard->assertSections($old, $this->places([['_id' => 'pnew', 'city' => 'Рим', 'marker' => '<b>old</b>']]));
            $this->assertRefused($old, $this->places([['_id' => 'pnew', 'city' => 'Рим', 'marker' => '<script>x</script>']]), 'a new code under the new id');
            $this->assertRefused($old, $this->places([['_id' => 'p1', 'marker' => '<b>old</b>'], ['_id' => 'p2', 'marker' => '<b>old</b>']]), 'the old code handed out twice');
            $this->assertRefused($old, $this->places([['city' => 'Рим', 'marker' => '<b>old</b>'], ['_id' => 'p2', 'marker' => '<b>old</b>']]), 'the code stayed in its place and was copied too');

            $nameless = [['type' => 'html', 'hidden' => false, 'data' => ['html' => '<p>a</p>']]];
            $this->guard->assertSections($nameless, $this->htmlSections(['snew' => '<p>a</p>']));
            $this->assertRefused($nameless, $this->htmlSections(['snew' => '<p>b</p>']), 'a new code in the section');
        });
    }

    #[Test]
    public function throughAnAssistantAFieldOfATemplateIsComparedTheSameWay(): void
    {
        $code = [Textarea::make('Код', 'code')->raw()];
        $this->throughAnAssistant(function () use ($code): void {
            $this->guard->assertFields($code, ['code' => '<p>a</p>'], ['code' => '<p>a</p>']);
            foreach ([['code' => ''], []] as $after) {
                try {
                    $this->guard->assertFields($code, ['code' => '<p>a</p>'], $after);
                    self::fail('the field was cleared');
                } catch (ContentException $e) {
                    self::assertSame(403, $e->status());
                }
            }
        });
    }

    /** The stricter comparison is for keys only: people in the admin work as before. */
    #[Test]
    public function peopleMayStillClearOrMoveWhatIsThere(): void
    {
        $two = $this->htmlSections(['s1' => '<p>a</p>', 's2' => '<p>b</p>']);
        $this->signIn(Role::EDITOR);
        $this->guard->assertSections($two, $this->htmlSections(['s1' => '', 's2' => '<p>b</p>']));
        $this->guard->assertSections($two, $this->htmlSections(['s1' => '<p>b</p>', 's2' => '<p>a</p>']));
        $this->addToAssertionCount(1);
    }
}
