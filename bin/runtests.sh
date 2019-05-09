#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
PROJ_ROOT_DIR="$SCRIPT_DIR/.."
TESTS_DIR="$PROJ_ROOT_DIR/tests"
VENDOR_DIR="$PROJ_ROOT_DIR/vendor"

(cd "$TESTS_DIR" && "$VENDOR_DIR/bin/phpunit" $@)