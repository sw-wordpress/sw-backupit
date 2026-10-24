# Contributing

1. Read `PLAN.md`, `CODE-STYLE.md`, `AGENTS.md`.
2. Keep changes small; add tests (unit + integration; restore-relevant changes need an E2E test) and docs.
3. Run `composer check` and the frontend checks before opening a pull request.
4. Security-relevant changes need a regression test and an entry in `docs/security.md`.
5. Never commit secrets, backups or build artefacts. Commit messages in English.
6. User-facing strings must be translatable and all shipped languages (de, fr, pl, it) must be updated in the same change: see `docs/translations.md` (`bin/i18n.sh update` / `check`).
