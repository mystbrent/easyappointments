-- Runs once on first MySQL start (docker-entrypoint-initdb.d).
-- Mirrors what scripts/provision-instance.sh does against Cloud SQL:
-- one database + one least-privilege user per vendor.

CREATE DATABASE IF NOT EXISTS ea_zeekr_gold_coast
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_zeekr'@'%' IDENTIFIED BY 'zeekr_pass';
GRANT ALL PRIVILEGES ON ea_zeekr_gold_coast.* TO 'ea_zeekr'@'%';

CREATE DATABASE IF NOT EXISTS ea_lostlocks
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ea_lostlocks'@'%' IDENTIFIED BY 'lostlocks_pass';
GRANT ALL PRIVILEGES ON ea_lostlocks.* TO 'ea_lostlocks'@'%';

FLUSH PRIVILEGES;
