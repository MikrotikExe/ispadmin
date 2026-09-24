# ISPadmin

Lightweight web-based customer management for small ISPs, built on top of the **MikroTik RouterOS API**. Multi-site: when you save a customer, the app automatically writes the DHCP lease + Simple Queue (or PPP secret for PPPoE) to the selected MikroTik, based on the customer's status and plan (speed profile).

**Plain PHP + SQLite/MySQL. No framework, no dependencies.** Up and running in minutes.

## Features

- Customer management: name, address, contact, contract, IP/MAC, plan, status, notes, change history
- Automatic MikroTik provisioning via RouterOS API — DHCP leases, Simple Queues, ARP, firewall address-lists, PPP secrets (PPPoE)
- Multi-router / multi-site: every customer belongs to a specific MikroTik and network
- Plans (speed profiles) with aggregation — `max-limit` and `limit-at` are calculated automatically
- Customer statuses: connected / temporarily disconnected / non-payer / contract terminated — the app blocks automatically based on status (address-list + speed throttling)
- Users and roles (administrator > admin > user), password management, trash bin, audit log
- One-click database backup + FTP/FTPS upload, restore from backup
- Optional geo-blocking of the login (allow selected countries only, via Cloudflare header or offline CIDR lists)
- **10 languages** — language picker on the login screen and in the header (Slovak, Czech, English, German, Polish, Hungarian, Romanian, Ukrainian, Latvian, Russian)
- Light / dark theme, responsive UI
- Time zone detected from the server automatically, overridable from the Settings page
- **PPPoE via RADIUS (optional)** — FreeRADIUS container sharing the app's MySQL database: PAP/CHAP/MS-CHAPv2, static IP and plan speed via RADIUS, status enforcement on live sessions (CoA / disconnect), session accounting per customer
- **DHCP Option 82 support** — bind a customer's lease to the physical circuit (Agent Circuit ID) instead of the MAC address, so swapping a modem needs no reconfiguration

## Screenshots

| | |
|---|---|
| ![Login](docs/screenshots/login.png) | ![Dashboard](docs/screenshots/home.png) |
| Login with language picker | Customer dashboard |
| ![Customer form](docs/screenshots/customer.png) | ![Dark mode](docs/screenshots/home-dark.png) |
| Customer form (PPPoE) | Dark mode |

More: [Settings](docs/screenshots/settings.png) · [Plans](docs/screenshots/programs.png) · [Routers](docs/screenshots/routers.png) · [Networks](docs/screenshots/networks.png) · [Backup](docs/screenshots/backup.png) · [Users](docs/screenshots/users.png)

## Requirements

- PHP 8.1+ with extensions: `pdo` + `pdo_sqlite` (or `pdo_mysql`), `openssl` (for api-ssl), optionally `curl`/`ftp` (FTP backups)
- MikroTik RouterOS with the API service enabled (port 8728, or api-ssl 8729)
- Or just Docker + docker compose — nothing else needed

## Quick start (testing, SQLite)

```bash
git clone https://github.com/MikrotikExe/ispadmin.git
cd ispadmin
php -S 0.0.0.0:8000 -t public
```

Open `http://server:8000/login.php`

**Default login: `admin` / `changeme`** — change the password right after logging in (Account section).

The database is created automatically on first run in `data/ispadmin.sqlite`, seeded with example plans (edit them in the UI to match your own offer).

## Full install on a clean Debian or Ubuntu server

This is the whole thing from a freshly installed machine, in order. It takes about
fifteen minutes and needs no PHP or web server knowledge. Steps 3 and 4 contain the two
things that most commonly make people think the install has failed when it hasn't.

### 1. Install Docker

```bash
sudo apt update && sudo apt install -y curl
curl -fsSL https://get.docker.com | sudo sh
```

### 2. Get ISPadmin and start it

```bash
git clone https://github.com/MikrotikExe/ispadmin.git
cd ispadmin
sudo docker compose up -d --build
```

The container runs with `network_mode: host`, so it reaches your MikroTiks exactly like the
host server does, including over WireGuard or other tunnels. The SQLite database lives in the
`ispadmin-data` volume and survives rebuilds.

### 3. Reach the web interface

**For safety the app listens only on `127.0.0.1:8090`, not on the network.** Opening
`http://your-server-ip:8090` in a browser will simply not respond, which looks like a failed
install but isn't. You have two options.

Either tunnel to it from your own machine — nothing to configure on the server:

```bash
ssh -L 8090:127.0.0.1:8090 youruser@your-server
```

and then browse to `http://localhost:8090`.

Or, if the server sits on a trusted internal network and you want to reach it directly, edit
`docker/ports.conf`, change `Listen 127.0.0.1:8090` to `Listen 8090`, and run
`sudo docker compose up -d --build` again.

For anything reachable from the internet, use the reverse proxy in the next step instead.

### 4. Put it behind nginx with HTTPS

Point a DNS record at the server first, then:

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
sudo cp docker/nginx-proxy.conf /etc/nginx/sites-available/ispadmin.conf
sudo nano /etc/nginx/sites-available/ispadmin.conf     # set server_name to your domain
```

**Remove the stock Debian site before running certbot.** It has a catch-all `server_name`, so
it swallows your domain and certbot installs the certificate into the wrong file — you end up
with a valid certificate serving the nginx welcome page:

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -s /etc/nginx/sites-available/ispadmin.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d ispadmin.example.com
```

Check that it worked, and that the app is what answers:

```bash
curl -I https://ispadmin.example.com/login.php     # expect 200
```

Once HTTPS is confirmed working, enable HSTS by uncommenting the
`Strict-Transport-Security` line in your nginx config and reloading. Don't do it earlier —
browsers will then refuse plain HTTP to this host and you can lock yourself out.

### 5. First login

Open your domain and log in with **`admin` / `changeme`**.

**Change the password immediately** under **Account**. There's a *Generate password* button
next to the field. The default is public knowledge, so on an internet-facing install this is
not optional.

Then, if more people need access, create accounts under **Users**. Give colleagues the
`admin` role rather than `administrator` — `admin` already unlocks every page, it just can't
delete or modify accounts at the same level, which stops someone accidentally locking you out.

### 6. Add your first router

Under **Routers**, fill in the host, API username and password, then press **Test**. If it
goes green, everything else in the app will work. See [MikroTik setup](#mikrotik-setup) below
for what to enable on the router itself.

## Production (MySQL)

1. In `config.php` set `'driver' => 'mysql'` and fill in the credentials (or use the `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` environment variables).
2. Create the database and import the schema:

```bash
mysql -u root -p -e "CREATE DATABASE ispadmin CHARACTER SET utf8mb4"
mysql -u ispadmin -p ispadmin < schema.sql
```

3. Point the DocumentRoot at the `public/` directory — `config.php`, `lib/`, `lang/` and `data/` stay outside the web root. On classic Apache shared hosting the bundled root `.htaccess` handles this.

With Docker you don't need your own MySQL server: the `radius` profile brings a MariaDB container
(see [PPPoE via RADIUS](#pppoe-via-radius) → Setup), and `migrate_sqlite_to_mysql.php` moves existing data over.

The **Backup** page (download, FTP upload, restore) works with SQLite only. On MySQL, back up with `mysqldump`, e.g. with the bundled container:

```bash
sudo docker exec mt-ispadmin-db sh -c 'exec mariadb-dump -P 3307 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > ispadmin-$(date +%F).sql
```

A daily backup with 14 days of rotation (the dump contains PPPoE passwords and RADIUS secrets, so it is root-only):

```bash
sudo tee /usr/local/bin/ispadmin-backup.sh >/dev/null <<'SH'
#!/bin/bash
set -euo pipefail
D=/var/backups/ispadmin
mkdir -p "$D"; chmod 700 "$D"
F="$D/ispadmin-$(date +%F-%H%M).sql.gz"
docker exec mt-ispadmin-db sh -c 'exec mariadb-dump -P 3307 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction "$MARIADB_DATABASE"' | gzip > "$F.tmp"
mv "$F.tmp" "$F"; chmod 600 "$F"
find "$D" -name 'ispadmin-*.sql.gz' -mtime +14 -delete
SH
sudo chmod 700 /usr/local/bin/ispadmin-backup.sh
echo '15 3 * * * root /usr/local/bin/ispadmin-backup.sh' | sudo tee /etc/cron.d/ispadmin-backup
```

`pipefail` makes a failed dump fail the job instead of leaving a truncated but valid-looking file. Restore with the
MariaDB client inside the container (MariaDB 11 dumps start with a "sandbox mode" line older `mysql` clients reject):

```bash
gunzip -c /var/backups/ispadmin/BACKUP.sql.gz | sudo docker exec -i mt-ispadmin-db sh -c 'exec mariadb -P 3307 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

## MikroTik setup

Enable the API on every router:

```
/ip service enable api
```

(port 8728; for SSL enable `api-ssl` on 8729 and turn on `use_ssl` for the router in the app)

Create an API account with permissions for: `dhcp-server/lease`, `queue`, `firewall/address-list`, `ppp/secret`, `system` (routers using PPPoE via RADIUS additionally need read access to `/radius` and `/ppp aaa` for the RADIUS test). You enter the API credentials in the **Routers** section of the UI — they are stored only in your own database.

To actually block non-payers, add a firewall rule on the router:

```
/ip firewall filter add chain=forward src-address-list=unpaid action=drop
/ip firewall filter add chain=forward src-address-list=suspended action=drop
```

The app maintains the address lists; the drop rules above are what actually blocks the traffic. List names default to `suspended`, `unpaid` and `terminated`, and are configurable in `config.php` (`block_lists`) or via environment variables.

## Status logic

| Status | What the app does on the MikroTik |
|---|---|
| Connected | lease + queue (enabled), IP removed from block list |
| Temporarily disconnected | lease stays, queue throttled/disabled, IP added to address-list |
| Non-payer | same as temporary, different address-list |
| Contract terminated | deletes lease + queue + address-list entry |

PPPoE customers are managed through `/ppp/secret` (login, password, profile) instead of lease/queue —
or, on routers switched to [PPPoE via RADIUS](#pppoe-via-radius), through FreeRADIUS.

## PPPoE via RADIUS

Optional. Off by default, and even when enabled it only applies to routers you switch over one
by one — every other router keeps the classic `/ppp secret` provisioning described above.

### How it works

```
PPPoE CPE ──PPPoE──> MikroTik (NAS) ──RADIUS 1812/1813──> FreeRADIUS ──SQL──> MySQL <── ISPadmin (web)
                          ^                                                         |
                          └──────────────── CoA / Disconnect, UDP 3799 ─────────────┘
```

- **FreeRADIUS 3.2** runs in its own container (`docker/freeradius/`) with `rlm_sql`, reading the
  **same MySQL database** as ISPadmin. ISPadmin is not a RADIUS server; it manages the standard
  FreeRADIUS tables (`radcheck`, `radreply`, `nas`) and reads `radacct`.
- Saving a router with *PPPoE via RADIUS = yes* writes its row to the `nas` table (NAS IP +
  its own shared secret). FreeRADIUS looks NAS entries up on demand, so no restart is needed.
- Saving a PPPoE customer on such a router writes their RADIUS rows instead of touching the router:

| Status | RADIUS result | Attributes |
|---|---|---|
| Connected | Access-Accept | `Framed-IP-Address` (the customer's IP), `Mikrotik-Rate-Limit` (plan, or real speed), `Mikrotik-Group` (PPPoE profile field, if set) |
| Temporarily disconnected / Non-payer | Access-Accept, restricted (default) — or Access-Reject with `ISPADMIN_RADIUS_MODE=reject` | as above plus `Mikrotik-Address-List` = the status list (`suspended` / `unpaid`), so the firewall rules you already have keep working; optional throttle and `Filter-Id` |
| Contract terminated | Access-Reject | — |

- A change on a customer who is **online** is applied immediately: a plan change sends a CoA with the new
  `Mikrotik-Rate-Limit`; a status, IP or profile change sends a Disconnect (PoD) and the CPE redials
  within seconds with the new profile. (`ISPADMIN_RADIUS_COA_STATUS=coa` tries CoA for status changes too.)
- No Simple Queue, DHCP lease or ARP entry is created for RADIUS PPPoE customers, and DHCP customers never
  get RADIUS rows. If a local `/ppp secret` with the same name exists on the router it is removed, because
  RouterOS would prefer it over RADIUS and status changes would silently stop working.
- The customer page shows the PPPoE sessions (start/stop, IP, bytes up/down, duration, terminate cause) and the
  last login attempts.
- PPPoE logins must be unique across RADIUS routers (RADIUS has no notion of "which router").
  An empty password is replaced by a generated 14-character one.

### Setup (Docker)

1. **Copy `.env.example` to `.env`** and fill in `DB_PASS`, `RADIUS_LOCAL_SECRET` (long random strings),
   `RADIUS_BIND` (the management address of this host the MikroTiks can reach) and `RADIUS_ADDRESS`
   (the same address, shown in the generated router config).
2. **`sudo docker compose up -d --build`** — with `COMPOSE_PROFILES=radius` from `.env` this starts
   `ispadmin` + `db` (MariaDB on `127.0.0.1:3307`) + `freeradius`. On first start the database is created
   with the app schema and the FreeRADIUS schema.
3. **Existing SQLite data?** Copy it over once (the SQLite file is only read):

   ```bash
   sudo docker exec -u www-data -it mt-ispadmin php /var/www/html/migrate_sqlite_to_mysql.php
   sudo docker exec -u www-data -it mt-ispadmin php /var/www/html/migrate_sqlite_to_mysql.php --apply
   ```

   Without this step the app starts on the new, empty MySQL database (login `admin` / `changeme`).
4. In **Routers**, edit a router, set *PPPoE via RADIUS = yes* and save. A strong secret is generated if you
   leave it empty. Fill *NAS IP* only if the router sends RADIUS from a different address than its API host.
5. Paste the **MikroTik configuration** shown under the router form (see below), then press **RADIUS test**.
6. Nothing else to do for existing customers: when *PPPoE via RADIUS* is switched on or off, the router's PPPoE
   customers are re-applied automatically (RADIUS rows ↔ `/ppp secret`). Logins already used by a customer on another
   RADIUS router are reported and skipped — RADIUS logins must be unique.

Non-Docker installs: install FreeRADIUS 3.2 with `freeradius-mysql`, use the files in `docker/freeradius/` as the
`sql` module, virtual servers and `clients.conf`, and set the same environment variables for PHP.

### MikroTik (NAS) setup

```
/radius add service=ppp address=<ISPADMIN_RADIUS_IP> secret="<ROUTER_RADIUS_SECRET>" authentication-port=1812 accounting-port=1813
/radius incoming set accept=yes port=3799
/ppp aaa set use-radius=yes accounting=yes interim-update=5m
/ppp profile add name=ispadmin-pppoe local-address=<PPP_GATEWAY_IP>
/interface pppoe-server server add service-name=pppoe interface=<CUSTOMER_IFACE_OR_VLAN> default-profile=ispadmin-pppoe authentication=pap,chap,mschap2 disabled=no
```

- `<ISPADMIN_RADIUS_IP>` is the address the **FreeRADIUS container listens on** (`RADIUS_BIND`), not necessarily
  the address of the web UI. ISPadmin does not listen on 1812/1813 itself.
- `<PPP_GATEWAY_IP>` is the router-side address of the PPPoE sessions (e.g. the gateway of the customers' subnet).
  RADIUS use is switched on globally in `/ppp aaa`; `/ppp profile` has no `use-radius` option.
- `<ROUTER_RADIUS_SECRET>` must match the router's secret in ISPadmin — the Routers page prints this block
  with the real secret filled in.
- CoA / disconnect packets come from the ISPadmin host. RouterOS only accepts them from an address that is in its
  `/radius` list, so the host must send from `<ISPADMIN_RADIUS_IP>` (set `RADIUS_COA_SOURCE` if it has several).
- If the router sends RADIUS from another address than its API host, put that address into *NAS IP* in ISPadmin
  (and, if it is one of the router's own addresses, add `src-address=` to `/radius`). Typical case: the router sits
  **behind NAT** — its API is reachable on a forwarded/1:1 address, but outgoing RADIUS leaves through another public
  address. Check with `tcpdump -ni any udp port 1812` on the ISPadmin host which source address really arrives.
  Each NAS IP (and each secret) can belong to one router only, so two RADIUS routers behind the same NAT address are
  not supported — give them separate public addresses or exempt them from NAT.
- CoA / disconnect packets are always sent to the router's **API host** (with the router's secret), not to the
  NAS-IP-Address the router reports, so they also reach routers behind NAT as long as UDP 3799 is forwarded like the API port.
  The reply is accepted even when it comes back from another address (an upstream router that masquerades the NAS
  subnet); it is authenticated by the shared secret, and the History entry shows `via <address>` in that case.
- RouterOS accepts CoA / disconnect **only from a source address listed in its `/radius`**. If a router between
  ISPadmin and the NAS source-NATs this traffic, the NAS counts it as `bad-requests` (`/radius incoming monitor`)
  and nothing happens. Exempt ISPadmin → NAS traffic from NAT on that router, e.g.
  `/ip firewall nat add chain=srcnat src-address=<ISPADMIN_IP> dst-address=<NAS_SUBNET> action=accept place-before=0`.
  The same applies in the other direction for RADIUS: the source FreeRADIUS sees must be the router's *NAS IP*.
- One session per login (`/ppp profile ... only-one=yes`) is left to your preference.
- The API user additionally needs read access to `/radius` and `/ppp aaa` for the RADIUS test button,
  and `ppp/secret` remove rights for the cleanup of old local secrets.

### Passwords and security

- `ISPADMIN_RADIUS_PW_STORAGE=cleartext` (default) stores `Cleartext-Password`: PAP, CHAP and MS-CHAPv2 all work.
  `nt` stores the NT hash instead: PAP and MS-CHAPv2 work, **CHAP does not** (it needs the cleartext by design).
  Either way the customer record itself keeps the password so it can be shown in the form — protect the database.
- **Never expose 1812, 1813 or 3799 to the internet.** Bind FreeRADIUS to the management network
  (`RADIUS_BIND`) and restrict it with the host firewall. FreeRADIUS answers only addresses present in the `nas` table.
- Every router gets its own secret (16–60 characters); the app refuses to reuse one. Only `admin` / `administrator`
  accounts see and change RADIUS secrets — with a secret one could forge CoA / disconnect packets.
- `RADIUS_REQUIRE_MA=yes` (in `.env`) makes FreeRADIUS require the Message-Authenticator attribute from routers
  (BlastRADIUS hardening). Enable it once all RADIUS routers run RouterOS 7.15 or newer.
- FreeRADIUS does not log passwords: the stock `radpostauth` query would store PAP / CHAP passwords (also wrong
  attempts) in cleartext, so they are stripped from the request before the login attempt is logged.

### Accounting and export

Sessions go to `radacct` (start, interim updates, stop; bytes including gigawords). For reporting there is an export hook:

```bash
sudo docker exec -u www-data mt-ispadmin php /var/www/html/export_sessions.php --from=2026-09-01 --to=2026-10-01 > sessions.csv
```

If a router crashes or reboots without sending Accounting-Stop, its sessions stay "online" in `radacct` forever.
`close_stale_sessions.php` closes sessions without any update for 3 × the interim interval (15 min by default) with
terminate cause `Stale-Session`. Run it from cron (it needs interim updates on the NAS, which ISPadmin requests via
`Acct-Interim-Interval`):

```bash
echo '*/10 * * * * root docker exec -u www-data mt-ispadmin php /var/www/html/close_stale_sessions.php --apply >/dev/null' | sudo tee /etc/cron.d/ispadmin-stale-sessions
```

Full data-retention reporting (which records, how long, which format) is not implemented yet.

### Configuration

All options are in the `radius` block of `config.php`, most of them settable from `.env`:
`ISPADMIN_RADIUS` (on/off), `ISPADMIN_RADIUS_MODE` (`restrict` / `reject`), `ISPADMIN_RADIUS_RESTRICT_RATE`,
`ISPADMIN_RADIUS_FILTER_ID`, `ISPADMIN_RADIUS_PW_STORAGE`, `ISPADMIN_RADIUS_POOL`, `ISPADMIN_RADIUS_INTERIM`,
`ISPADMIN_RADIUS_COA_STATUS`, `RADIUS_COA_SOURCE`. The Mikrotik-Rate-Limit format is `rate_limit_template`
(`{ul}/{dl}` by default; with plan aggregation use `{ul}/{dl} 0/0 0/0 0/0 8 {ul_at}/{dl_at}`).

Turning `ISPADMIN_RADIUS` off again makes RADIUS routers fall back to `/ppp secret` provisioning on the next save of
each customer. To switch a single router back, set *PPPoE via RADIUS = no* on it instead — its customers are
re-applied immediately.

## Circuit ID (DHCP Option 82)

Each customer has an optional **Circuit ID** field holding the DHCP Option 82 agent circuit identifier — for example an NBN AVC ID in Australia, or a port identifier from a DSLAM or access switch.

**When the field is filled, the app binds the DHCP lease to the circuit instead of the MAC address.** The MAC is deliberately not sent, so the customer can replace their modem and the lease still applies — no reconfiguration, no manual re-entry. The router matches the lease using the `agent-circuit-id` parameter:

```
/ip dhcp-server lease add address=10.0.0.50 \
    agent-circuit-id=41564330303032353038313730313138 server=dhcp1
```

If the Circuit ID is empty, the app falls back to the usual MAC-based lease, so existing setups are unaffected.

### Entering the value

RouterOS displays Option 82 identifiers as hex, but the underlying value is usually plain text. You can paste either form and the app normalises it:

| What you enter | Stored / sent to the router |
|---|---|
| `AVC0002508170118` | `41564330303032353038313730313138` |
| `41564330303032353038313730313138` | unchanged |
| `0x4156433030...` | `0x` prefix stripped |

A string is only treated as hex if it decodes to readable text — so a purely numeric circuit ID such as `0012345678` is correctly kept as text rather than misread as hex. To force hex interpretation of a binary identifier, prefix it with `0x`.

To find the value on a running system, look at an active lease in RouterOS (`IP → DHCP Server → Leases`, the **Agent Circuit Id** field), or take it from the carrier's service order.

Notes and open questions live in [issue #1](https://github.com/MikrotikExe/ispadmin/issues/1).

## Time zone

Every timestamp in the app — change history, logins, backup file names — uses a single time zone, resolved in this order:

1. the `ISPADMIN_TZ` environment variable, if set (useful for Docker)
2. a manual choice saved on the **Settings** page
3. the server's own time zone, read from `/etc/timezone` or `/etc/localtime`
4. UTC, as a last resort

In practice step 3 means timestamps are correct straight after installation without configuring anything. If the server's zone is wrong or the app runs somewhere else than your customers, pick the right one under Settings — it is stored in the database, not in `config.php`, so it survives updates. When `ISPADMIN_TZ` is set it wins over everything and the Settings field is shown read-only, so it is always obvious where the value comes from.

## Plans and aggregation

Per-user Simple Queue:

- `max-limit` = user UL/DL (upload/download, in kbit)
- `limit-at` = `max-limit / aggregation` (guaranteed share)

A plan with no speed set (e.g. IPTV, GPON) creates only the lease, no queue.

## Importing existing customers

Prepare a JSON file following [`example_data.json`](example_data.json) and run:

```bash
php import_json.php my_site.json          # preview, writes nothing
php import_json.php my_site.json --apply  # real import
```

The import is idempotent — existing customers (same IP or PPPoE login) are skipped, and nothing is changed on the MikroTik.

## CLI helper scripts

| Script | Purpose |
|---|---|
| `import_json.php` | import a router, its networks and customers from JSON |
| `pull_speeds.php` | fill in customers' real speeds from Simple Queues on the MikroTik (read-only) |
| `set_siet.php` | bulk-set the "Network" field on customers |
| `fix_encoding.php` | fix diacritics (CP1250 escapes from RouterOS) in already imported data |
| `update_geoip.php` | download country CIDR lists for geo-blocking (cron-friendly) |
| `migrate_sqlite_to_mysql.php` | copy the SQLite database into MySQL (needed for PPPoE via RADIUS) |
| `export_sessions.php` | export PPPoE sessions from RADIUS accounting as CSV / JSON lines |
| `close_stale_sessions.php` | close RADIUS sessions whose NAS stopped reporting them (cron-friendly) |

The data-changing ones run in preview mode until you add `--apply`.

**Run them as the web user.** Inside Docker the app runs as `www-data`, and the SQLite
database has to stay writable by it. If you run a script as root, the database file ends up
owned by root and the web interface then fails with *"attempt to write a readonly database"*:

```bash
sudo docker exec -u www-data -it mt-ispadmin php /var/www/html/import_json.php \
     /var/www/html/my_site.json --apply
```

If it has already happened, fix the ownership with:

```bash
sudo docker exec mt-ispadmin chown -R www-data:www-data /data
```

## Languages / adding a translation

The UI ships in 10 languages; users pick their language on the login screen (stored in a cookie, auto-detected from the browser on first visit). Slovak is the source language, English is the fallback for missing strings.

To add or improve a language:

1. Copy `lang/en.php` to `lang/xx.php` and translate the values (keys stay in Slovak).
2. Add the code and native name to `LANGS` in `lib/lang.php`.
3. Check completeness: `php lang/verify.php xx` (uses `lang/keys.txt`, verifies keys, `%s` placeholders and inline HTML).

Pull requests with new languages are welcome.

## Geo-blocking (optional)

Access can be limited to selected countries. Enable it with the `ISPADMIN_GEO_ENFORCE=1` env variable (disabled by default in `docker-compose.yml` so you can't lock yourself out). It works via the Cloudflare `CF-IPCountry` header, or fully offline via CIDR lists (`update_geoip.php`). Add your own IPs to `ISPADMIN_GEO_ALLOW_IPS` as a safety net.

## Logo and branding

The header logo is text-based and configured in `config.php`:

```php
'brand_pre'  => 'isp',      // first (blue) part
'brand_post' => 'admin',    // second (dark) part
'tagline'    => 'customer management · MikroTik',
```

A default SVG logo is included in `public/assets/logo.svg` — feel free to modify it or replace it with your own.

## Security notes

- **Change the default password immediately** after the first login (`admin` / `changeme`). The default is published here, so anyone who finds your install knows it.
- `config.php`, `lib/`, `lang/` and `data/` must not be reachable from the web — both Docker and the bundled `.htaccess` take care of this.
- Router API credentials and customer data live only in your own database (`data/` is in `.gitignore`) — never commit them.
- Run the app behind HTTPS (certbot + nginx proxy), ideally on an internal network / behind a VPN.
- With PPPoE via RADIUS: never expose UDP 1812, 1813 or 3799 to the internet, bind FreeRADIUS to the management network (`RADIUS_BIND`), keep one secret per router, and protect the database — it holds the PPPoE passwords.

## Troubleshooting

**"Fatal error: attempt to write a readonly database"**
A CLI script was run as root, so the SQLite file is now owned by root while the web app runs
as `www-data`. Fix the ownership and always pass `-u www-data` to `docker exec`:

```bash
sudo docker exec mt-ispadmin chown -R www-data:www-data /data
```

**The browser doesn't respond on port 8090**
That's intended — the app listens on `127.0.0.1` only. Use an SSH tunnel or the nginx reverse
proxy, see [step 3](#3-reach-the-web-interface) above.

**Certbot succeeded but the domain shows the nginx welcome page**
Certbot installed the certificate into the stock Debian site because it matched the domain
first. Remove `/etc/nginx/sites-enabled/default`, make sure `server_name` in your own config
is exactly right, then re-run certbot and choose *reinstall*.

**The Test button under Routers fails**
Check in order: the API service is enabled on the router (`/ip service print`), the host and
port are reachable from the server (`nc -vz ROUTER_IP 8728`), the API account exists with the
right permissions, and no firewall rule on the router blocks the API port.

**RADIUS test: FreeRADIUS timeout**
Check `sudo docker compose logs --tail 50 freeradius`. The container stops right away if `DB_PASS` or
`RADIUS_LOCAL_SECRET` is missing in `.env`, or if the database is unreachable. `RADIUS_SERVER` must be
the address FreeRADIUS listens on (`RADIUS_BIND`).

**PPPoE customer on a RADIUS router cannot log in**
The customer page shows the last login attempts. No attempt at all: the MikroTik doesn't reach FreeRADIUS
(check `/radius` address, the host firewall, and that the router's source address equals *NAS IP*).
Access-Reject: wrong password, customer terminated/rejected, or CHAP with `ISPADMIN_RADIUS_PW_STORAGE=nt`.
A local `/ppp secret` with the same name also wins over RADIUS; saving the customer removes it.

**CoA / disconnect fails ("timeout")**
The router needs `/radius incoming set accept=yes`, UDP 3799 must be open from the ISPadmin host, and the
packet must come from an address listed in the router's `/radius` (set `RADIUS_COA_SOURCE` if needed).
Check `/radius incoming monitor` on the router: growing `bad-requests` means the packet arrives from another
address — usually source NAT on a router in between (see [MikroTik (NAS) setup](#mikrotik-nas-setup)).
`/radius monitor` with growing `timeouts` means the router's RADIUS requests get no answer: its source address
is not the *NAS IP* stored in ISPadmin (check with `tcpdump -ni any udp port 1812` on the ISPadmin host).

**Session times differ from the change history**
FreeRADIUS writes session times in the database's time zone. Set `TZ` in `.env` to the same zone as the app.

**Timestamps are hours off**
See [Time zone](#time-zone). The quickest fix is to set it explicitly on the Settings page.

**Check what the app is actually doing**

```bash
sudo docker compose logs --tail 50
```

## TODO / possible extensions

- Parent queue / queue-tree for shared group caps
- IPv6 prefix delegation
- Bulk re-sync of all customers to a router
- Per-field audit log

## License

MIT — see [LICENSE](LICENSE). Use at your own risk; test everything on a lab router before deploying to production.

---

Author: [Juraj Chudý](https://jurajchudy.sk)
