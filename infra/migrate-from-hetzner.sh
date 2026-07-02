#!/usr/bin/env bash
# One-shot migration: dump each ea_* DB from the Hetzner MySQL,
# import into Cloud SQL. Run once per product.
set -euo pipefail

: "${1:?Usage: $0 <product> <hetzner-mysql-host> <hetzner-root-pass> <gcp-project> <gcp-region>}"
PRODUCT="$1"; HZ_HOST="$2"; HZ_PASS="$3"; PROJECT="$4"; REGION="$5"
DB="ea_$(echo "$PRODUCT" | tr - _)"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo ">>> Dumping $DB from Hetzner ($HZ_HOST)..."
ssh -i ~/.ssh/hetzner_easyappointments "root@$HZ_HOST" \
  "docker exec booking-mysql mysqldump --single-transaction --no-tablespaces -u root -p$HZ_PASS $DB" \
  > "$TMP/$DB.sql"

echo ">>> Uploading to GCS import bucket..."
BUCKET="gs://${PROJECT}-ea-import"
gcloud storage cp "$TMP/$DB.sql" "$BUCKET/"

echo ">>> Importing into Cloud SQL (ea-mysql)..."
gcloud sql import sql "ea-mysql" "$BUCKET/$DB.sql" \
  --database="$DB" --quiet

echo ">>> Done. Verify row counts, then flip DNS for $PRODUCT."
