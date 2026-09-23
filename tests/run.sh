#!/usr/bin/env bash
# Runs every test file and exits non-zero if any check fails.
# Point PHP at a specific binary with: PHP=/path/to/php bash tests/run.sh
set -u
PHP="${PHP:-php}"
cd "$(dirname "$0")/.."

status=0
for t in tests/test-package.php tests/test-core.php tests/test-hardening.php tests/test-admin.php tests/test-concurrency.php; do
	echo "=============== $t"
	"$PHP" "$t" || status=1
done

echo
if [ "$status" -eq 0 ]; then echo "ALL TESTS PASSED"; else echo "SOME TESTS FAILED"; fi
exit "$status"
