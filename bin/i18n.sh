#!/usr/bin/env bash
# Translation workflow for the PHP side (text domain "sw-backupit").
#   bin/i18n.sh pot       regenerate languages/sw-backupit.pot from the source code
#   bin/i18n.sh update    pot + merge new/changed strings into every languages/sw-backupit-<locale>.po (msgmerge)
#   bin/i18n.sh compile   compile every .po to .mo (+ catalogs of the standalone restore app)
#   bin/i18n.sh check     fail when the .pot is outdated or a .po lacks a translation (used by the PHPUnit test and CI)
# Needs GNU gettext (xgettext, msgmerge, msgfmt) on the DEVELOPMENT machine only.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LANG_DIR="$ROOT/languages"
POT="$LANG_DIR/sw-backupit.pot"
LOCALES=(de_DE fr_FR pl_PL it_IT)
VERSION="$(grep -m1 "Version:" "$ROOT/sw-backupit.php" | awk '{print $NF}')"

make_pot() {
  local out="${1:-$POT}"
  php "$ROOT/bin/i18n-core-messages.php" >/dev/null
  (cd "$ROOT" && { find sw-backupit.php uninstall.php packages/wordpress/src -name '*.php'; find packages/restore/src/Web -name '*.php'; } | sort -u | \
    xargs xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
      --keyword=__:1 --keyword=_e:1 --keyword=esc_html__:1 --keyword=esc_attr__:1 --keyword=esc_html_e:1 \
      --keyword=t:1 --keyword=_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c \
      --package-name="sw-backupit" --package-version="$VERSION" --msgid-bugs-address="" \
      --copyright-holder="sw-backupit contributors" --sort-by-file --no-wrap -o "$out")
  # Stable header: no creation date noise, UTF-8.
  sed -i 's/^"POT-Creation-Date:.*$/"POT-Creation-Date: YEAR-MO-DA HO:MI+ZONE\\n"/; s/charset=CHARSET/charset=UTF-8/' "$out"
  sed -i '1,/^$/ s/^# SOME DESCRIPTIVE TITLE\./# sw-backupit translation template./' "$out"
}

case "${1:-}" in
  pot) make_pot ;;
  update)
    make_pot
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-backupit-$locale.po"
      if [ -f "$po" ]; then msgmerge --quiet --update --no-fuzzy-matching --backup=none --no-wrap "$po" "$POT"; fi
    done
    "$0" compile
    ;;
  compile)
    for po in "$LANG_DIR"/sw-backupit-*.po; do msgfmt --check-format -o "${po%.po}.mo" "$po"; done
    php "$ROOT/bin/i18n-restore.php"   # PHP array catalogs for the standalone restore application
    ;;
  check)
    tmp="$(mktemp)"; make_pot "$tmp"
    if ! diff <(grep -E '^(msgid|msgctxt)' "$tmp") <(grep -E '^(msgid|msgctxt)' "$POT") >/dev/null; then
      echo "languages/sw-backupit.pot is outdated - run bin/i18n.sh update" >&2; exit 1
    fi
    for locale in "${LOCALES[@]}"; do
      po="$LANG_DIR/sw-backupit-$locale.po"
      [ -f "$po" ] || { echo "missing $po" >&2; exit 1; }
      msgfmt --check-format --statistics -o /dev/null "$po" 2> "$tmp.stat" || exit 1
      if grep -Eq "untranslated|fuzzy" "$tmp.stat"; then echo "$po is incomplete: $(cat "$tmp.stat")" >&2; exit 1; fi
      [ -f "${po%.po}.mo" ] || { echo "missing ${po%.po}.mo - run bin/i18n.sh compile" >&2; exit 1; }
    done
    echo "translations are complete and up to date"
    ;;
  *) echo "usage: $0 pot|update|compile|check"; exit 1 ;;
esac
