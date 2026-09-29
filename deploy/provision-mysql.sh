#!/usr/bin/env bash
# Installs MySQL 26.7 (Innovation track) from Oracle's APT repository and creates the RMS database and users.
# Run as root on a fresh Ubuntu 26.04 server BEFORE adding the site in Forge (Forge offers no MySQL 26.x).
# Usage: RMS_APP_PASSWORD=... RMS_MIGRATE_PASSWORD=... [RMS_DB=rms] ./provision-mysql.sh
set -euo pipefail

: "${RMS_APP_PASSWORD:?set RMS_APP_PASSWORD}"
: "${RMS_MIGRATE_PASSWORD:?set RMS_MIGRATE_PASSWORD}"
RMS_DB="${RMS_DB:-rms}"
CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"

# Oracle's repository must publish this Ubuntu release, or apt would silently install Ubuntu's own MySQL.
if ! curl -fsSL "https://repo.mysql.com/apt/ubuntu/dists/${CODENAME}/Release" >/dev/null; then
  echo "Oracle's MySQL APT repository has no packages for Ubuntu ${CODENAME}. Stop and choose another release." >&2
  exit 1
fi

if ! command -v mysqld >/dev/null || ! mysqld --version | grep -q ' 26\.7\.'; then
  install -d /usr/share/keyrings
  curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | gpg --dearmor -o /usr/share/keyrings/mysql.gpg
  echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-innovation" \
    > /etc/apt/sources.list.d/mysql.list
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
fi

mysqld --version | grep -q ' 26\.7\.' || { echo "Installed MySQL is not 26.7" >&2; exit 1; }

mysql -u root <<SQL
SET PERSIST log_bin_trust_function_creators = 1;
SET PERSIST binlog_expire_logs_seconds = 604800;
CREATE DATABASE IF NOT EXISTS \`${RMS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rms_app'@'localhost' IDENTIFIED BY '${RMS_APP_PASSWORD}';
CREATE USER IF NOT EXISTS 'rms_migrate'@'localhost' IDENTIFIED BY '${RMS_MIGRATE_PASSWORD}';
-- rms_migrate is the DEFINER of every trigger: rotate its password with ALTER USER, never drop it.
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON \`${RMS_DB}\`.* TO 'rms_app'@'localhost';
GRANT ALL PRIVILEGES ON \`${RMS_DB}\`.* TO 'rms_migrate'@'localhost';
SQL

mysql -u root -N -e "SELECT @@version, @@log_bin, @@log_bin_trust_function_creators"
echo "MySQL ready: database ${RMS_DB}, users rms_app and rms_migrate."
