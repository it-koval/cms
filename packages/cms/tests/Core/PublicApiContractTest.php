<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\Rendering\PageCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublicApiContractTest extends TestCase
{
    #[Test]
    public function everyNamedSourceTypeHasExactlyOneClassification(): void
    {
        $public = $this->documentedTypes();
        self::assertNotEmpty($public, 'API.md must contain explicit fully qualified type entries.');
        self::assertSame(\array_values(\array_unique($public)), $public, 'API.md lists a public type more than once.');

        $types = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                foreach ($this->typesIn((string) \file_get_contents($file->getPathname())) as $name => $internal) {
                    self::assertArrayNotHasKey($name, $types, 'A named source type is declared more than once.');
                    $types[$name] = $internal;
                }
            }
        }

        self::assertNotEmpty($types);
        foreach ($public as $name) {
            self::assertArrayHasKey($name, $types, $name . ' is listed in API.md but has no source declaration.');
        }
        self::assertSame([], $this->classificationProblems($types, $public), 'Every source type must be public in API.md or have its own @internal docblock.');
    }

    #[Test]
    public function russianListsMatchTheCanonicalEnglishContract(): void
    {
        $russian = (string) \file_get_contents(__DIR__ . '/../../API.ru.md');
        self::assertSame($this->documentedTypes(), $this->documentedTypes($russian));
        \preg_match_all('/^- `PageService::([A-Za-z][A-Za-z0-9]*)\(\)`$/m', $this->contract(), $englishMethods);
        \preg_match_all('/^- `PageService::([A-Za-z][A-Za-z0-9]*)\(\)`$/m', $russian, $russianMethods);
        self::assertSame($englishMethods[1], $russianMethods[1]);
    }

    #[Test]
    public function inventoryDistinguishesPublicInternalAndUnclassifiedTypes(): void
    {
        $prefix = 'Itk\\Cms\\Tests\\Fixture\\PublicApi\\';
        $types = $this->typesIn((string) \file_get_contents(__DIR__ . '/../Fixtures/public-api/types.php'));

        self::assertSame([
            $prefix . 'PublicType' => false,
            $prefix . 'InternalType' => true,
            $prefix . 'UnclassifiedType' => false,
            $prefix . 'InternalEnum' => true,
        ], $types, 'Inventory covers classes, interfaces, traits and enums, excluding anonymous classes and ::class references.');
        self::assertSame([
            $prefix . 'UnclassifiedType: missing public API entry or @internal',
        ], $this->classificationProblems($types, [$prefix . 'PublicType']));
        self::assertContains($prefix . 'InternalType: public and @internal', $this->classificationProblems($types, [$prefix . 'PublicType', $prefix . 'InternalType']));
    }

    #[Test]
    public function pageServicePublicOperationsMatchTheCanonicalContract(): void
    {
        \preg_match_all('/^- `PageService::([A-Za-z][A-Za-z0-9]*)\(\)`$/m', $this->contract(), $matches);
        $expected = $matches[1];
        self::assertNotEmpty($expected);
        self::assertCount(\count(\array_unique($expected)), $expected, 'Each operation has one canonical entry.');

        $actual = [];
        foreach ((new \ReflectionClass(PageService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            if (!$this->isInternal($method->getDocComment() ?: '')) {
                $actual[] = $method->getName();
            }
        }
        \sort($actual);
        \sort($expected);
        self::assertSame($expected, $actual, 'A public PageService method must be documented or explicitly @internal.');
    }

    #[Test]
    public function translationOperationsKeepTheirDocumentedSignatures(): void
    {
        foreach (['addTranslation' => 'array', 'removeTranslation' => 'void'] as $name => $return) {
            $method = new \ReflectionMethod(PageService::class, $name);
            self::assertTrue($method->isPublic());
            self::assertFalse($method->isStatic());
            self::assertSame($return, (string) $method->getReturnType());
            $parameters = $method->getParameters();
            self::assertSame('addTranslation' === $name ? ['id', 'locale', 'from', 'guard'] : ['id', 'locale', 'guard'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters));
            self::assertSame('string', (string) $parameters[0]->getType());
            self::assertSame('string', (string) $parameters[1]->getType());
            self::assertSame('addTranslation' === $name ? 3 : 2, $method->getNumberOfRequiredParameters());
            if ('addTranslation' === $name) {
                self::assertSame('?string', (string) $parameters[2]->getType());
            }
        }
    }

    #[Test]
    public function siteCallsCanOmitEveryInternalPageAuthorizationCallback(): void
    {
        $checked = 0;
        foreach ((new \ReflectionClass(PageService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->isInternal($method->getDocComment() ?: '')) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                if ('guard' !== $parameter->getName()) {
                    continue;
                }
                ++$checked;
                self::assertSame('?callable', (string) $parameter->getType(), $method->getName());
                self::assertTrue($parameter->isOptional(), $method->getName());
                self::assertNull($parameter->getDefaultValue(), $method->getName());
                $docblock = $method->getDocComment() ?: '';
                self::assertMatchesRegularExpression('/@param [^\n]+\$guard internal bundle authorization callback/i', $docblock, $method->getName());
                self::assertMatchesRegularExpression('/site code must omit it or pass null/i', $docblock, $method->getName());
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    #[Test]
    public function criticalCssMessageExposesPoliciesWithoutPublishingEvaluationHelpers(): void
    {
        $message = new GenerateCriticalCss('translation-id');
        self::assertSame('translation-id', $message->translationId);
        self::assertSame(When::Outdated, $message->when);
        self::assertSame(['Outdated', 'NotReady', 'Always'], \array_column(When::cases(), 'name'));
        foreach (When::cases() as $when) {
            self::assertSame($when, (new GenerateCriticalCss('translation-id', $when))->when);
        }
        foreach (['admits', 'reusesIdenticalInputs'] as $name) {
            self::assertTrue($this->isInternal((new \ReflectionMethod(When::class, $name))->getDocComment() ?: ''), $name . ' is a worker implementation detail.');
        }
    }

    #[Test]
    public function pageCacheOnlyGuaranteesManualClearing(): void
    {
        $public = [];
        foreach ((new \ReflectionClass(PageCache::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor() && !$this->isInternal($method->getDocComment() ?: '')) {
                $public[] = $method->getName();
            }
        }
        self::assertSame(['clear'], $public);
        self::assertSame('bool', (string) (new \ReflectionMethod(PageCache::class, 'clear'))->getReturnType());
    }

    private function contract(): string
    {
        return (string) \file_get_contents(__DIR__ . '/../../API.md');
    }

    /** @return list<string> */
    private function documentedTypes(?string $document = null): array
    {
        \preg_match_all('/^- `(Itk\\\\Cms\\\\[A-Za-z0-9_\\\\]+)`$/m', $document ?? $this->contract(), $matches);

        return $matches[1];
    }

    /**
     * @param array<string, bool> $types name => class-level @internal
     * @param list<string> $public
     *
     * @return list<string>
     */
    private function classificationProblems(array $types, array $public): array
    {
        $problems = [];
        foreach ($types as $name => $internal) {
            $listed = \in_array($name, $public, true);
            if ($listed && $internal) {
                $problems[] = $name . ': public and @internal';
            } elseif (!$listed && !$internal) {
                $problems[] = $name . ': missing public API entry or @internal';
            }
        }
        \sort($problems);

        return $problems;
    }

    /** @return array<string, bool> name => class-level @internal; source is tokenized without loading optional dependencies */
    private function typesIn(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $namespace = '';
        $types = [];
        foreach ($tokens as $index => $token) {
            if (\T_NAMESPACE === $token->id) {
                $namespace = '';
                for ($i = $index + 1; isset($tokens[$i]) && !\in_array($tokens[$i]->text, [';', '{'], true); ++$i) {
                    if (!$tokens[$i]->isIgnorable()) {
                        $namespace .= $tokens[$i]->text;
                    }
                }
            }
            if (!\in_array($token->id, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                ++$next;
            }
            if (!isset($tokens[$next]) || \T_STRING !== $tokens[$next]->id) {
                continue; // anonymous class or a ::class reference
            }
            $types[('' === $namespace ? '' : $namespace . '\\') . $tokens[$next]->text] = $this->isInternal($this->typeDocblock($tokens, $index));
        }

        return $types;
    }

    private function isInternal(string $docblock): bool
    {
        return 1 === \preg_match('~^(?:/\*\*|\h*\*)\h*@internal(?=\s|\*/)~m', $docblock);
    }

    /** @param list<\PhpToken> $tokens */
    private function typeDocblock(array $tokens, int $index): string
    {
        $attributes = 0;
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (']' === $token->text) {
                ++$attributes;
            } elseif ($attributes > 0 && ('[' === $token->text || \T_ATTRIBUTE === $token->id)) {
                --$attributes;
            } elseif ($attributes > 0) {
                continue;
            } elseif (\T_DOC_COMMENT === $token->id) {
                return $token->text;
            } elseif (!$token->isIgnorable() && !\in_array($token->id, [\T_FINAL, \T_ABSTRACT, \T_READONLY], true)) {
                break;
            }
        }

        return '';
    }
}
