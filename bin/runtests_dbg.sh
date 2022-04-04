#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"

"$SCRIPT_DIR"/runtests.sh --prepend inc/DbgTestEnv.php $@