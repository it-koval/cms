<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

/** A section type: a block a page is built from. */
abstract class SectionResource implements ResourceInterface
{
    /** Whether this section carries the page H1; null retains the legacy group convention. */
    public function firstScreen(): ?bool
    {
        return null;
    }

    /** Group in the block library; «Первый экран» sections carry the page's H1. Null = the default group of the bundle, named in the language of the reader. */
    public function group(): ?string
    {
        return null;
    }

    /** Twig template that draws the section; by default sections/<key>.html.twig. */
    public function template(): ?string
    {
        return null;
    }

    /** Name on the canvas toolbar and in «Структура». */
    public function short(): ?string
    {
        return null;
    }

    /** Tone of the thumbnail: white | gray | dark | photo. */
    public function tone(): ?string
    {
        return null;
    }

    public function silhouette(): ?string
    {
        return null;
    }

    public function about(): string
    {
        return '';
    }

    /** The headline field that is the page's H1 when this section carries it. */
    public function h1(): ?string
    {
        return null;
    }
}
