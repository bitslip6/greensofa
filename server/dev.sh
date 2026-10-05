#!/bin/sh
export LOBBY_DEV_MODE=1
export LOBBY_HOST=0.0.0.0
export LOBBY_PORT=9502
unset LOBBY_HOST LOBBY_PORT
unset LOBBY_TLS_CERT_FILE LOBBY_TLS_KEY_FILE
export LOBBY_TOKEN_PEPPER='local-development-pepper-change-me-123456789'

/usr/local/bin/php bin/diagnose.php
/usr/local/bin/php bin/lobby-server.php
