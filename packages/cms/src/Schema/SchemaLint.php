<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\AbstractField;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;
use Twig\Environment;

/**
 * The check behind itk:schema:lint and the schema line of itk:doctor: Schema::validate() and the Twig templates the schema asks for.
 * A problem of the first kind or a section without its template is an error; a page template without its own file only a warning (the page is drawn by the template «default»).
 *
 * @internal
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
            $warnings = [...$warnings, ...$this->nestedAccessWarnings(I18n::t('model.problem_at_section', ['key' => $key]), $section->allFields())];
            if (!$loader->exists($section->templatePath())) {
                $errors[] = I18n::t('model.lint_section_template', ['key' => $key, 'path' => $section->templatePath()]);
            }
        }
        foreach ($schema->templates() as $key => $template) {
            $warnings = [...$warnings, ...$this->nestedAccessWarnings(I18n::t('model.problem_at_template', ['key' => $key]), $template->fieldList())];
            if (!$loader->exists('pages/' . $key . '.html.twig') && !$loader->exists('@ItkCms/pages/' . $key . '.html.twig')) {
                $warnings[] = I18n::t('model.lint_page_template', ['key' => $key]);
            }
        }

        foreach ($schema->collections() as $key => $collection) {
            foreach ($collection->tabList() as $tab) {
                $warnings = [...$warnings, ...$this->nestedAccessWarnings(I18n::t('model.problem_at_collection', ['key' => $key]), $tab['fields'])];
            }
        }
        foreach ($schema->settingsGroups() as $key => $group) {
            $warnings = [...$warnings, ...$this->nestedAccessWarnings(I18n::t('model.problem_at_settings', ['key' => $key]), $group->fieldList())];
        }

        foreach ($schema->forms() as $key => $form) {
            foreach ($form->fieldList() as $field) {
                if ('toggle' !== $field->type() && null === $field->maxLength()) {
                    $warnings[] = I18n::t('model.lint_request_field_limit', ['form' => $key, 'field' => $field->key(), 'count' => \Itk\Cms\Request\RequestService::MAX_LENGTH]);
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<string>
     */
    private function nestedAccessWarnings(string $at, array $fields, string $parent = '', string $topLevel = ''): array
    {
        $warnings = [];
        foreach ($fields as $field) {
            $path = '' === $parent ? $field->key() : $parent . '.' . $field->key();
            $root = '' === $parent ? $field->key() : $topLevel;
            if ('' !== $parent) {
                $flags = [];
                // isPublic() reads the declaration; visibleTo() would execute a site's closure and depend on a viewer.
                if ($field instanceof AbstractField && !$field->isPublic()) {
                    $flags[] = 'canSee()';
                }
                if ($field->isLocked()) {
                    $flags[] = 'readonly()';
                }
                if ([] !== $flags) {
                    $warnings[] = I18n::t('model.lint_nested_access', ['at' => $at, 'path' => $path, 'flags' => \implode(', ', $flags), 'parent' => $root]);
                }
            }
            $warnings = [...$warnings, ...$this->nestedAccessWarnings($at, $field->subFields(), $path, $root)];
        }

        return $warnings;
    }
}
