#!/bin/bash

if [ "$#" -ne 1 ]; then
    echo "Usage: runtestsuite.sh <test_suite_name>"
    exit
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"

echo "About to excute test suite $1" && echo

"$SCRIPT_DIR"/runtests_dbg.sh --testsuite "$1"