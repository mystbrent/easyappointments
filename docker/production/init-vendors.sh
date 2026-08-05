#!/bin/bash
# Creates one database and one least-privilege user per vendor.
#
# Runs once, on first start against an empty data volume
# (docker-entrypoint-initdb.d). A shell script rather than plain SQL so the
# passwords come from the environment instead of being committed.
#
# Adding a vendor: add a block here AND create the database by hand on an
# already-initialised server — this file will not re-run once the volume exists.
# See docs/droplet-deployment.md, "Adding a vendor".

set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?required}"
: "${ZEEKR_DB_PASSWORD:?required}"
: "${LOSTLOCKS_DB_PASSWORD:?required}"

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS ea_zeekr_gold_coast
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_zeekr'@'%' IDENTIFIED BY '${ZEEKR_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ea_zeekr_gold_coast.* TO 'ea_zeekr'@'%';

CREATE DATABASE IF NOT EXISTS ea_lostlocks
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_lostlocks'@'%' IDENTIFIED BY '${LOSTLOCKS_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ea_lostlocks.* TO 'ea_lostlocks'@'%';

FLUSH PRIVILEGES;
SQL

echo "init-vendors: created ea_zeekr_gold_coast and ea_lostlocks"
