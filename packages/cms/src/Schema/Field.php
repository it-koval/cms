<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\Article;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\DateTime;
use Itk\Cms\Field\Description;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Image;
use Itk\Cms\Field\Link;
use Itk\Cms\Field\Note;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Photos;
use Itk\Cms\Field\Relation;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\Field\Toggle;

/**
 * The short way to write fields in config/itk/schema.php: key first, label second.
 *
 *   Field::headline('headline', 'Заголовок')->max(48)->required()      is   Headline::make('Заголовок', 'headline')->max(48)->required()
 *
 * Every method returns the field object of Itk\Cms\Field\ (the same fluent API); resource classes use those classes directly.
 */
final class Field
{
    private function __construct()
    {
    }

    /** One plain line. */
    public static function text(string $key, string $label): Text
    {
        return Text::make($label, $key);
    }

    /** Plain multi-line text. */
    public static function textarea(string $key, string $label): Textarea
    {
        return Textarea::make($label, $key);
    }

    /** DESCRIPTION: prose with paragraphs, bold, italic, links, lists (HTML string). */
    public static function description(string $key, string $label): Description
    {
        return Description::make($label, $key);
    }

    /** A heading: HTML with line breaks (+ gradient words when the design has them). */
    public static function headline(string $key, string $label): Headline
    {
        return Headline::make($label, $key);
    }

    /** The long text of an article (+ subheadings, quote, divider, photos). */
    public static function article(string $key, string $label): Article
    {
        return Article::make($label, $key);
    }

    public static function number(string $key, string $label): Number
    {
        return Number::make($label, $key);
    }

    public static function toggle(string $key, string $label): Toggle
    {
        return Toggle::make($label, $key);
    }

    /**
     * @param array<int|string, mixed> $options list of [value, label] pairs, or value => label
     */
    public static function select(string $key, string $label, array $options): Select
    {
        return Select::make($label, $key)->options($options);
    }

    /** A date as `YYYY-MM-DD` text. */
    public static function date(string $key, string $label): Date
    {
        return Date::make($label, $key);
    }

    /** A date and time as `YYYY-MM-DDTHH:MM` text. */
    public static function dateTime(string $key, string $label): DateTime
    {
        return DateTime::make($label, $key);
    }

    /** Value: { src, alt }. */
    public static function image(string $key, string $label): Image
    {
        return Image::make($label, $key);
    }

    /** Value: { kind: 'page'|'url'|'modal', page, url }. */
    public static function link(string $key, string $label): Link
    {
        return Link::make($label, $key);
    }

    /**
     * A list of items; every item has its own _id and the given sub-fields.
     *
     * @param FieldInterface|array<FieldInterface> ...$sub
     */
    public static function repeater(string $key, string $label, FieldInterface|array ...$sub): Repeater
    {
        return Repeater::make($label, $key)->fields(...$sub);
    }

    /** A list of photos: each item is an image address (what the admin adds) or {src, alt} (the MCP tools may send it); the shape of an item is kept. */
    public static function photos(string $key, string $label): Photos
    {
        return Photos::make($label, $key);
    }

    /** A note for the editor, not data. */
    public static function note(string $key, string $label, string $text = ''): Note
    {
        return Note::make($label, $key)->text($text);
    }

    /** A link to a record of another collection. */
    public static function relation(string $key, string $label, string $collection): Relation
    {
        return Relation::make($label, $key)->to($collection);
    }
}
