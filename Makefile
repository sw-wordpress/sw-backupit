.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
DEPS_STAMP := $(ADMIN_DIR)/node_modules/.make-install

# Usage: make release VERSION=0.8.2 (publishes the current branch and release tag to origin).
.PHONY: release
release: export SWBI_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh

.PHONY: build
build: $(DEPS_STAMP)
	cd $(ADMIN_DIR) && $(NPM) run build

$(DEPS_STAMP): $(ADMIN_DIR)/package.json $(ADMIN_DIR)/package-lock.json
	cd $(ADMIN_DIR) && $(NPM) ci --include=dev
	touch "$@"

# Release ZIP exactly as the release workflow builds it (.github/workflows/main.yml): same build script, same checks.
# Needs composer, Docker (Mozart), node/npm, zip/rsync. Output: dist/sw-backupit-<version>.zip
# Like CI it builds from the committed state: untracked files are never shipped, uncommitted changes to tracked files are.
# The public key ring comes from the checkout (CI re-embeds the same public key from the protected environment).
.PHONY: dist
dist:
	composer validate --strict
	rm -rf dist
	bash bin/build-zip.sh "$(CURDIR)/dist"
	bash bin/verify-zip.sh "$$(ls dist/sw-backupit-*.zip)" "sw-backupit-$$(grep -m1 'Version:' sw-backupit.php | awk '{print $$NF}').zip"
	@echo "Dist file: $$(ls dist/sw-backupit-*.zip)"
