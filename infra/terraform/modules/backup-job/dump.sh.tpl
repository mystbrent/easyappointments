#!/bin/sh
set -eu
TS=$(date -u +%Y%m%dT%H%M%SZ)
for DB in ${DB_NAMES}; do
  mysqldump --no-tablespaces --single-transaction --host=${SQL_HOST} --user=root --password="${SQL_PASS}" "$DB" \
    | gzip > "/tmp/${DB}_${TS}.sql.gz"
  gsutil cp "/tmp/${DB}_${TS}.sql.gz" "gs://${BUCKET}/hourly/${DB}/${DB}_${TS}.sql.gz"
  rm "/tmp/${DB}_${TS}.sql.gz"
done
