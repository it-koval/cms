<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;

/**
 * Templates every site has: home, default, and the blog ones (blog, blog_category, blog_article) unless the site has no blog.
 * The texts are kept as catalogue keys and looked up when the schema is read, so each person sees them in the language of the interface he chose.
 */
final class CoreSchema
{
    /** Keys of the blog templates. */
    public const BLOG = ['blog', 'blog_category', 'blog_article'];

    public static function register(SchemaBuilder $s, bool $blog = true): void
    {
        $s->template('home', 'home')->markCore()
            ->texts(name: 'model.core_home_name', about: 'model.core_home_about')
            ->root()->sections('all');

        $s->template('default', 'default')->markCore()
            ->texts(name: 'model.core_default_name', about: 'model.core_default_about')
            ->anyParent()->sections('all');

        if (!$blog) {
            return;
        }

        $s->template('blog', 'blog')->markCore()
            ->texts(name: 'model.core_blog_name', about: 'model.core_blog_about')
            ->single()->topLevel()->sections('all')
            ->listing(of: ['blog_article'])->texts(listing: 'model.core_blog_articles');

        $s->template('blog_category', 'blog_category')->markCore()
            ->texts(name: 'model.core_category_name', about: 'model.core_category_about')
            ->parents('blog')->sections('all')
            ->fields(Field::description('description', 'description')->texts(label: 'model.core_category_description', help: 'model.core_category_description_help')->max(200)->rows(3))
            ->listing(of: ['blog_article'])->texts(listing: 'model.core_category_articles');

        $s->template('blog_article', 'blog_article')->markCore()->forAuthors()
            ->texts(name: 'model.core_article_name', about: 'model.core_article_about', shortcut: 'model.core_blog_articles')
            ->parents('blog', 'blog_category')->sections('all')
            ->fields(...self::articleFields())
            ->h1('title')
            ->card(image: 'cover', title: 'title', text: 'excerpt', date: 'date')
            ->leaf();
    }

    /** @return list<FieldInterface> the fields of an article (blog, knowledge base) */
    public static function articleFields(): array
    {
        return [
            Field::text('title', 'title')->texts(label: 'model.core_article_title')->max(90)->required(),
            Field::date('date', 'date')->texts(label: 'model.core_article_date'),
            Field::image('cover', 'cover')->texts(label: 'model.core_article_cover'),
            Field::description('excerpt', 'excerpt')->texts(label: 'model.core_article_excerpt', help: 'model.core_article_excerpt_help')->max(200)->rows(3),
            Field::article('body', 'body')->texts(label: 'model.core_article_body')->max(12000)->rows(14)->required(),
        ];
    }
}
