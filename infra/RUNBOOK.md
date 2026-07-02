# Operations Runbook

Operational procedures for the GCP EasyAppointments deployment. Run all
`terraform` commands from `infra/terraform/` and set shell variables first:

```bash
export PROJECT="your-gcp-project-id"
export REGION="us-central1"
export DOMAIN="bookings.example.com"
```

See [`README.md`](README.md) for setup and day-to-day usage, and
[`WIF-SETUP.md`](WIF-SETUP.md) for CI authentication.

---

## Rotate a secret

When an instance's database password (or any `random_password`-backed secret)
must be rotated, force Terraform to replace it and then redeploy the Cloud Run
revision so the new value is picked up.

```bash
cd infra/terraform
terraform apply -replace=random_password.db_pass
```

Then redeploy the Cloud Run revision to force the service to re-resolve the
secret from Secret Manager:

```bash
gcloud run services update ea-product-a --region $REGION
```

> Note: the `random_password.db_pass` resource is created inside the
> `ea-instance` module, which is fanned out per product. Target the specific
> instance's resource if rotating only one product, e.g.
> `-replace='module.ea_instance["product-a"].random_password.db_pass'`.

---

## Force an ad-hoc backup

Hourly `mysqldump` runs automatically via the `ea-db-dumper` Cloud Run Job
triggered by Cloud Scheduler. To take an immediate, out-of-schedule backup:

```bash
gcloud run jobs execute ea-db-dumper --region $REGION
```

Dumps land in `gs://$PROJECT-ea-db-backups/hourly/<db>/`. Verify with:

```bash
gcloud storage ls "gs://$PROJECT-ea-db-backups/hourly/" | head
```

---

## Scale an instance

Adjust the autoscaling bounds for a product by editing its `min_instances` /
`max_instances` in the `products` map of `terraform.tfvars`:

```hcl
products = {
  product-a = {
    # ...
    min_instances = 2
    max_instances = 20
  }
}
```

Then apply:

```bash
cd infra/terraform
terraform apply
```

Cloud Run picks up the new bounds on the next revision.

---

## View logs

Tail or read the logs for a specific instance's Cloud Run service:

```bash
gcloud run services logs read ea-product-a --region $REGION
```

For a live tail, add `--follow`. Filter by severity with `--filter=severity>=ERROR`.

---

## Roll back a deploy

Each `terraform apply` that changes the image creates a new Cloud Run revision
while keeping prior revisions. To instantly shift 100% of traffic back to the
previous revision:

```bash
gcloud run services update-traffic ea-product-a \
  --to-revisions=LATEST-1=100
```

To target a specific named revision instead, use `REVISION=100`:

```bash
gcloud run services update-traffic ea-product-a \
  --to-revisions=ea-product-a-00001-xyz=100
```

List available revisions with
`gcloud run revisions list --service ea-product-a --region $REGION`.

---

## Decommission Hetzner

**Only after** the instance has run stably on GCP for ~48 hours and data
integrity has been verified against the Hetzner source (see
[`migrate-from-hetzner.sh`](migrate-from-hetzner.sh) for the migration flow):

1. **Terminate the Hetzner CX22 server** from the Hetzner Cloud console (or
   `hcloud server delete <name>` via the `hcloud` CLI).
2. **Delete the Coolify project** for EasyAppointments from the Coolify UI to
   remove its services, databases, and proxy config.
3. **Revoke the SSH keys** that were deployed to the Hetzner box — both from
   the Hetzner Cloud console and from any shared key directories
   (`~/.ssh/hetzner_easyappointments` is referenced by the migration script).

Verify there are no lingering DNS records pointing at the old Hetzner IP and
that no billing resources remain in the Hetzner project.
