<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\I18n\I18n;
use Twig\Environment;

/**
 * The check behind itk:schema:lint and the schema line of itk:doctor: Schema::validate() and the Twig templates the schema asks for.
 * A problem of the first kind or a section without its template is an error; a page template without its own file only a warning (the page is drawn by the template «default»).
 */
final class SchemaLint
{
    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly Environment $twig,
    ) {
    }

    /** @return array{errors: list<string>, warnings: list<string>} */
    public function run(): array
    {
        try {
            $schema = $this->loader->build();
        } catch (\Throwable $e) { // a key described twice, a file that does not return a callable …
            return ['errors' => [$e->getMessage()], 'warnings' => []];
        }
        $errors = $schema->validate();
        $warnings = [];
        $loader = $this->twig->getLoader();
        foreach ($schema->sections() as $key => $section) {
            if (!$loader->exists($section->templatePath())) {
                $errors[] = I18n::t('model.lint_section_template', ['key' => $key, 'path' => $section->templatePath()]);
            }
        }
        foreach ($schema->templates() as $key => $_) {
            if (!$loader->exists('pages/' . $key . '.html.twig') && !$loader->exists('@ItkCms/pages/' . $key . '.html.twig')) {
                $warnings[] = I18n::t('model.lint_page_template', ['key' => $key]);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
