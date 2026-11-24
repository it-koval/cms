<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\CriticalCss\StylesheetsRuntime;
use Itk\Cms\Rendering\RenderState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StylesheetsNonceTest extends TestCase
{
    #[Test]
    public function strictNonceCspNeedsNoInlineStylesheetHandler(): void
    {
        $state = new RenderState();
        $state->critical = CriticalStyles::fromStored([['media' => null, 'css' => 'body{margin:0}']]);
        $runtime = new StylesheetsRuntime($state, new Stylesheets(false, ['/full.css'], [], [], [], 32, __DIR__));
        $html = $runtime->stylesheets(['nonce' => 'test-nonce']);
        self::assertStringContainsString('<style nonce="test-nonce">body{margin:0}</style>', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/full.css" nonce="test-nonce">', $html);
        self::assertStringNotContainsString('onload=', $html);
        self::assertStringNotContainsString('media="print"', $html);
        self::assertStringContainsString('media="print" onload=', $runtime->stylesheets(), 'the default async loading stays available without a nonce');
        $state->reset();
        self::assertSame('<link rel="stylesheet" href="/full.css" nonce="test-nonce">', $runtime->stylesheets(['nonce' => 'test-nonce']));
    }
}
