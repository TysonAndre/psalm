#!/usr/bin/env bash
if [ "$#" != 0 ]; then
	echo "Usage: $0" 1>&2
	echo "Runs all test scripts" 1>&2
	exit 1
fi

base_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

TEST_FOLDERS="plugin_test"

FAILURES=""
for TEST_FOLDER in $TEST_FOLDERS; do
	echo "Running test suite: $TEST_SUITE"
	pushd $base_dir/$TEST_FOLDER
	if ! ./test.sh; then
		FAILURES="$FAILURES $TEST_SUITE"
	fi
	popd
	echo
done
if [[ "x$FAILURES" != "x" ]]; then
	echo "These tests or checks failed (see above output): $FAILURES"
	exit 1
else
	echo 'All tests and checks passed!'
fi
