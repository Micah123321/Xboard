#!/bin/sh
set -eu
# Run before PHP loads application code or the normal entrypoint changes state.
/usr/local/bin/php -n /usr/local/bin/xboard-check-php
exec "$@"
