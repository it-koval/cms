English | [Русский](SITE-AGENTS.ru.md)

# Rules for an AI agent that builds a site on ITK CMS

This site is built on ITK CMS, the Symfony bundle `itk/cms`. The bundle core is shared by every site on this CMS. Its documentation is the package [README.md](README.md), next to this file. In a site it is `vendor/itk/cms/README.md`.

## Propose core features upstream

If the site needs a feature that the core does not have, and other sites could use it too, propose it to the core as a pull request. Do not build it only for this site. The ITK CMS maintainers review the pull request. If they accept it, every site on the CMS gets the feature.

Open the pull request in [it-koval/cms](https://github.com/it-koval/cms). The bundle code is in `packages/cms` there. The `it-koval/cms-bundle` repository is filled automatically and does not accept pull requests.

## Decide where the change goes

1. **Look for an extension point first.** The core has many, and the package README describes each one: fields, resource classes, hooks and events, actions, filters, list views, the admin menu, extensions, settings in code, custom MCP tools, and the `itk:make:*` generators. If one of them solves the task, solve it in the site code.
2. **Keep site-specific code in the site.** Markup, templates, sections, collections, the client's business rules, and integrations that only this site needs go into the site's `src/`, `templates/`, and `config/`.
3. **Send core changes as a pull request.** This covers a missing extension point from step 1, a core bug, a general feature (a new field type, command, check, or setting), and a new system dependency of the core.

If you are not sure whether a feature is general, ask the person who gave you the task.

## Do not work around the core

- Do not edit files in `vendor/itk/cms`. The next `composer update` erases the edit.
- Do not copy core classes, templates, or JavaScript into the site to change the copy. The copy stops receiving core fixes.
- Do not use internal core classes. Classes marked `@internal` in their docblock, and everything the README calls internal, can change in any release. If you cannot do without such a class, the core is missing an extension point. Propose it as a pull request.
- Do not change the core tables (`itk_*`) in site migrations. Only bundle migrations change the core schema.

## Prepare a core pull request

- Follow [CONTRIBUTING.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.md). Write a test that fails without the change first. Run the PHP and admin checks. Add a [CHANGELOG.md](https://github.com/it-koval/cms/blob/main/CHANGELOG.md) entry. Add a note to [UPGRADE.md](https://github.com/it-koval/cms/blob/main/UPGRADE.md) if the public API changes. Document a new feature in the README. Update the Docker kit if the change adds a system dependency.
- Solve one task per pull request. Describe the site scenario that hit the core limit, what changes, and how you tested it.
- Keep client data out of the pull request: texts, addresses, keys, and database dumps. Describe the scenario in general terms.
- If you cannot open a pull request yourself, prepare a branch or a patch with a description and hand it to the person who gave you the task.

## Until the pull request is accepted

The site can wait for the release or add a temporary workaround in its own code. The workaround must not copy core code. Mark it with a comment that links to the pull request, for example `// temporary, until it-koval/cms#123`. Remove it when the site updates to the package version with the change.

## Report

In your task report, list the core pull requests you opened or prepared, and the temporary workarounds that wait for them.
