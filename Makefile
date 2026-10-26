.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
DEPS_STAMP := $(ADMIN_DIR)/node_modules/.make-install

.PHONY: build
build: $(DEPS_STAMP)
	cd $(ADMIN_DIR) && $(NPM) run build

$(DEPS_STAMP): $(ADMIN_DIR)/package.json $(ADMIN_DIR)/package-lock.json
	cd $(ADMIN_DIR) && $(NPM) ci --include=dev
	touch "$@"
