# Workload Identity Federation — one-time setup

Lets GitHub Actions authenticate to GCP without a static service account key.

## 1. Create the pool + provider

```bash
gcloud iam workload-identity-pools create github \
  --location=global
gcloud iam workload-identity-pools providers create-oidc github \
  --location=global \
  --workload-identity-pool=github \
  --issuer-uri=https://token.actions.githubusercontent.com \
  --attribute-mapping="google.subject=assertion.sub,repository=assertion.repository"
```

## 2. Create the GCP SA GitHub will impersonate

```bash
gcloud iam service-accounts create gh-actions-deployer
```

Grant it the roles the workflow needs:
```bash
for ROLE in run.admin sqladmin.admin secretmanager.admin \
            artifactregistry.admin dns.admin compute.admin \
            storage.admin iam.serviceAccountUser cloudscheduler.admin; do
  gcloud projects add-iam-policy-binding $PROJECT \
    --member="serviceAccount:gh-actions-deployer@${PROJECT}.iam.gserviceaccount.com" \
    --role="roles/${ROLE}"
done
```

## 3. Bind the pool to the SA for this repo

```bash
REPO="yourorg/easyappointments"
gcloud iam service-accounts add-iam-policy-binding \
  gh-actions-deployer@${PROJECT}.iam.gserviceaccount.com \
  --role=roles/iam.workloadIdentityUser \
  --member="principalSet://iam.googleapis.com/projects/$(gcloud projects describe $PROJECT --format='value(projectNumber)')/locations/global/workloadIdentityPools/github/attributes/repository/${REPO}"
```

## 4. Add repo secrets

- `WIF_PROVIDER`: `projects/<NUMBER>/locations/global/workloadIdentityPools/github/providers/github`
- `WIF_SA`: `gh-actions-deployer@<PROJECT>.iam.gserviceaccount.com`
- `GCP_PROJECT`, `DOMAIN`, `SMTP_PASS`
