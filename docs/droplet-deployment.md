# Deploying the multi-vendor booking stack

Takes two EasyAppointments instances from nothing to serving live bookings for
`pt-selfsell-zeekr-gold-coast-app` and `lostlocks-pwa`, on one host.

Written for a DigitalOcean droplet, but nothing here is DO-specific — it runs on
any host with Docker and ports 80/443 reachable.

**Target:** `s-2vcpu-2gb` in `syd1` (~$18/mo). Sydney because both vendors are
Gold Coast businesses; from Frankfurt every availability query would carry
~250 ms of latency it doesn't need.

---

## 1. Host

Create the droplet — Ubuntu 24.04, `s-2vcpu-2gb`, region `syd1`, your SSH key,
and **attach a Reserved IP**. The Reserved IP matters: DNS points at it, so the
droplet can be rebuilt or resized without touching Cloudflare.

```bash
ssh root@<reserved-ip>

# Docker
curl -fsSL https://get.docker.com | sh

# mysql-client, for the host-side backup script
apt-get update && apt-get install -y mysql-client

# Firewall: SSH and web only. MySQL is bound to loopback and must stay unreachable.
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable
```

```bash
git clone <this-repo> /opt/easyappointments
cd /opt/easyappointments
cp .env.production.example .env
# Fill in every blank. Generate secrets with:
#   openssl rand -base64 24 | tr -d '/+=' | head -c 32
$EDITOR .env
chmod 600 .env
```

---

## 2. DNS

In Cloudflare, on `knowwhats.best`:

| Type | Name | Value | Proxy | TTL |
|---|---|---|---|---|
| `A` | `*.bookings` | *(Reserved IP)* | **DNS only** (grey cloud) | Auto |

**The grey cloud is not optional.** Proxied (orange) intercepts Let's Encrypt's
HTTP-01 challenge, certificate issuance fails, and the symptom is a Cloudflare
525/526 that names nothing close to the real cause.

A wildcard DNS record is not a wildcard certificate — Caddy issues a separate
cert per hostname, which is why no Cloudflare API token is needed.

Verify before continuing, or Caddy will burn Let's Encrypt attempts against a
name that doesn't resolve (5 failures per domain per week and you're locked out):

```bash
dig +short zeekr.bookings.knowwhats.best      # → the Reserved IP
dig +short lostlocks.bookings.knowwhats.best  # → the same IP
```

---

## 3. Bring the stack up

```bash
cd /opt/easyappointments
docker compose -f docker-compose.production.yml up -d --build
```

First build takes a few minutes (multi-stage PHP + Node). Then watch Caddy get
its certificates:

```bash
docker compose -f docker-compose.production.yml logs -f caddy | grep -i "certificate\|error"
```

Confirm both vendors serve real TLS:

```bash
curl -sI https://zeekr.bookings.knowwhats.best      | head -1   # HTTP/2 200
curl -sI https://lostlocks.bookings.knowwhats.best  | head -1   # HTTP/2 200
```

---

## 4. Seed both vendors

Identical to seeding locally. `EA_SEED_ENDPOINT` points the seeder at the
instance from *inside* its own container, while the manifest's `base_url`
remains what the storefront is told.

```bash
set -a; . ./.env; set +a

seed() {   # seed <compose-service> <manifest>
  docker compose -f docker-compose.production.yml exec -T \
    -e EA_SEED_ENDPOINT=http://localhost \
    -e ZEEKR_ADMIN_PASSWORD -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_API_KEY -e ZEEKR_WEBHOOK_SECRET \
    -e LOSTLOCKS_ADMIN_PASSWORD -e LOSTLOCKS_PROVIDER_PASSWORD -e LOSTLOCKS_API_KEY -e LOSTLOCKS_WEBHOOK_SECRET \
    "$1" php /var/www/html/scripts/ea-seed.php apply "/var/www/html/$2"
}

# Preview first — plan writes nothing.
docker compose -f docker-compose.production.yml exec -T \
  -e EA_SEED_ENDPOINT=http://localhost -e ZEEKR_API_KEY -e ZEEKR_ADMIN_PASSWORD \
  -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_WEBHOOK_SECRET \
  ea-zeekr-gold-coast php /var/www/html/scripts/ea-seed.php plan \
  /var/www/html/verticals/zeekr-gold-coast.yml

seed ea-zeekr-gold-coast verticals/zeekr-gold-coast.yml
seed ea-lostlocks        verticals/lostlocks.yml
```

Expect `7 created, …` on the first run and `0 created, 0 updated, N unchanged`
on any re-run. If a re-run reports updates, something drifted — investigate
rather than ignoring it.

Collect the emitted config:

```bash
docker compose -f docker-compose.production.yml exec -T ea-zeekr-gold-coast \
  cat /var/www/html/.env.zeekr-gold-coast
docker compose -f docker-compose.production.yml exec -T ea-lostlocks \
  cat /var/www/html/.env.lostlocks
```

---

## 5. Wire up the storefronts

The seeder holds no credentials for Cloudflare, so this step is deliberately
manual. For the Zeekr Worker, set these — the first three come from `.env`, the
rest from the emitted file above:

| Variable | Where from |
|---|---|
| `EA_BASE_URL` | `https://zeekr.bookings.knowwhats.best` |
| `EA_API_KEY` | `ZEEKR_API_KEY` in `.env` — **secret** |
| `EA_WEBHOOK_SECRET` | `ZEEKR_WEBHOOK_SECRET` in `.env` — **secret** |
| `EA_PROVIDER_ID` | emitted |
| `EA_SERVICE_TEST_DRIVE` | emitted |
| `EA_SERVICE_SHOWROOM` | emitted |
| `EA_SERVICE_FINANCE` | emitted |
| `EA_SERVICE_TRADEIN` | emitted |
| `EA_TIMEZONE` | `Australia/Brisbane` |

```bash
cd ~/PitchTalks/pt-selfsell-zeekr-gold-coast-app
wrangler secret put EA_API_KEY
wrangler secret put EA_WEBHOOK_SECRET
# The non-secret ids go in wrangler.jsonc [vars], then:
wrangler deploy
```

Lostlocks additionally needs `EA_GATED_SERVICE_IDS` (Mastermind Heist and
Zombie Lab require owner approval). Zeekr does not use that variable at all.

Then verify end to end:

```bash
curl -s "https://zeekr.bookings.knowwhats.best/api/v1/availabilities?serviceId=<TEST_DRIVE>&providerId=<PROVIDER>&date=$(date -v+3d +%Y-%m-%d)" \
  -H "Authorization: Bearer $ZEEKR_API_KEY"
```

Query a few days out, not tomorrow: `book_advance_timeout` (30 min, evaluated
in the provider's timezone) legitimately withholds near-boundary slots, which
looks like a bug and isn't.

---

## 6. Backups

`scripts/backup-databases.sh` already does this — it discovers every `ea_*`
database, keeps 24 hourly and 8 weekly dumps per vendor, and uses
`--single-transaction` so a dump never blocks a customer mid-booking.

Per-vendor dumps rather than whole-droplet snapshots: restoring one vendor's
bookings from a droplet image would roll back every vendor.

```bash
ln -sf /opt/easyappointments/.env /etc/ea-backup.env
/opt/easyappointments/scripts/backup-databases.sh        # verify by hand first
crontab -e
```

```cron
0 * * * * cd /opt/easyappointments && ./scripts/backup-databases.sh >> /var/log/ea-backup.log 2>&1
```

Restore one vendor:

```bash
gunzip -c /opt/easyappointments/backups/ea_lostlocks/hourly/<file>.sql.gz \
  | docker compose -f docker-compose.production.yml exec -T mysql \
      mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ea_lostlocks
```

Dumps live on the same disk as the database. Once real bookings exist, push them
off-host — DO Spaces ($5/mo) or `rclone` to anywhere.

---

## Adding a vendor

1. `verticals/<product>.yml` — the manifest
2. `docker-compose.production.yml` — a service, its `EA_*` env, an uploads volume
3. `docker/production/Caddyfile` — three lines
4. `docker/production/init-vendors.sh` — a block **and**, because that file only
   runs on an empty data volume, create the database by hand on the live server:

```bash
docker compose -f docker-compose.production.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE ea_<product> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ea_<product>'@'%' IDENTIFIED BY '<password>';
GRANT ALL PRIVILEGES ON ea_<product>.* TO 'ea_<product>'@'%';
FLUSH PRIVILEGES;
SQL
```

5. `.env` — the new secrets
6. `docker compose … up -d --build`, then seed

No DNS change — the wildcard covers it.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Cloudflare 525/526 | DNS record is proxied | Set it to **DNS only** (grey cloud) |
| `too many certificates` | Let's Encrypt rate limit, 5/domain/week | Uncomment `acme_ca` staging in the Caddyfile until DNS is right |
| Booking fails, console shows CORS | `EA_CORS_ORIGINS` doesn't match the Worker origin exactly | Scheme + host, no trailing slash, no path |
| Seeder: "could not reach" | Missing `EA_SEED_ENDPOINT` | Add `-e EA_SEED_ENDPOINT=http://localhost` |
| Seeder: "No admin role found" | Migrations haven't run | Wait for the container's first boot to finish, then re-seed |
| Re-apply reports updates | Real drift, or a resource edited in the admin UI | Run `plan` to see what differs |
| Midnight slot missing | `book_advance_timeout` in the provider's timezone | Query 2–3 days out before suspecting the working plan |
| EA builds `http://` URLs | Caddy not passing `X-Forwarded-Proto`, or `EA_PROXY_IPS` wrong | Must match the compose subnet (`172.28.0.0/16`) |
