# Translations (i18n)

Languages: **English** (source), **Deutsch (de_DE)**, **Français (fr_FR)**, **Polski (pl_PL)**, **Italiano (it_IT)**. Regional WordPress locales
(de_AT, de_CH, de_DE_formal, fr_CA, it_CH, …) use the shipped language of the same family.

| Part | Mechanism | Files |
|---|---|---|
| PHP (REST messages, notifications, WP-CLI, default profiles) | gettext, text domain `sw-backupit`, loaded from `languages/` by WordPress | `languages/sw-backupit.pot`, `sw-backupit-<locale>.po/.mo` |
| Messages of the framework independent core/restore packages | listed as literal `__()` calls in the generated `Support/CoreMessages.php` (so they reach the .pot) and translated at presentation time (REST errors, job errors) | same catalogs |
| Admin UI (Vue/Quasar) | vue-i18n catalogs bundled in the build; language follows the WordPress user locale; Quasar's own labels follow too | `apps/admin/src/i18n/{en,de,fr,pl,it}.ts` |
| Standalone restore wizard | tiny translator (no gettext, no WordPress); PHP array catalogs generated from the .po files; language from the browser (`Accept-Language`) or `language` in its config | `packages/restore/lang/<lang>.php` |
| Restore CLI, log files, technical details in job logs | English only (log files are for operators/support) | – |

## Keeping translations up to date

Translations are **part of the definition of done**: a change that adds or edits a user-facing string must update all languages.

```bash
bin/i18n.sh update     # regenerates the .pot (code + core messages), merges new/changed strings into every .po, compiles .mo and the restore catalogs
# translate the new (empty) entries of languages/sw-backupit-<locale>.po, then
bin/i18n.sh compile
bin/i18n.sh check      # fails when the .pot is outdated, a language has untranslated/fuzzy entries or a compiled file is missing
```

* `tests/Unit/Core/I18nTest.php` runs `bin/i18n.sh check`, so `composer test` fails as soon as a language is behind.
* `apps/admin/tests/i18n-parity.test.ts` (Vitest) fails when a UI catalog misses a key, differs in placeholders (`{name}`) or in the number of plural forms.
* Workflow for a new string: write it in English with `__( '…', 'sw-backupit' )` (PHP, add a `/* translators: */` comment for placeholders) or add the key to
  `en.ts`/`de.ts` and the other UI catalogs; run the commands above.
* Adding a language: create `languages/sw-backupit-<locale>.po` with `msginit`, add the locale to `LOCALES` in `bin/i18n.sh` and `$map` in `bin/i18n-restore.php`, add the
  UI catalog and register it in `apps/admin/src/i18n/index.ts` and `config.ts`.
* Needs GNU gettext on the **development** machine only; the release ZIP ships the compiled `.mo` files.
