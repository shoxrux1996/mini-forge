# Mini Forge

A local-first, Forge-style manager for Laravel apps on your own Ubuntu VPS.
Mini Forge runs on your laptop and manages servers over SSH; nothing of it
runs on the server, and sites keep running when your laptop is off.

Built with Laravel 13, Filament 5, SQLite and phpseclib.

## Status

| Phase | What | State |
|---|---|---|
| 1 | Foundation: login, data model, server management | Done |
| 2 | SSH, host-key pinning, inspection, Docker + Traefik provisioning | Done |
| 3 | Sites, Compose templates, environment editor, start/stop | Done |
| 4 | Image build and push, deployments, health checks, rollback | Next |
| 5 | Metrics, containers, logs, Artisan commands, docs | Planned |

## Run it locally

Requirements: PHP 8.4, Composer, SQLite.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan make:filament-user
composer run dev
```

Open http://localhost:8000 and sign in. `composer run dev` also starts the
queue worker that later phases use for provisioning and deployments.

`APP_KEY` encrypts the stored SSH keys and secret environment values. Back it
up with the database; without it they cannot be decrypted.

## Adding a server

1. **Servers → New server**: enter the host, SSH port and user.
2. Keep **Generate a new key pair** (or paste an existing private key).
3. Copy the public key from the server page into `~/.ssh/authorized_keys`
   for that user on the VPS.

4. Enter a Let's Encrypt email in the server settings.
5. **Test connection**, then **Provision**. Follow progress on the
   **Provisioning logs** tab; it needs the queue worker (`composer run dev`).

Removing a server only forgets it in Mini Forge. Nothing on the VPS changes.

## What provisioning does

Each step checks first and is skipped when already done, so running it again
is safe and resumes a failed run.

1. Checks root access (root, or `sudo -n` for other users), Ubuntu 22.04+,
   x86_64/arm64, and that ports 80/443 are free. It stops instead of touching
   another service that holds those ports.
2. Installs Docker Engine and the Compose plugin from Docker's official apt
   repository. An existing Docker install is left as it is.
3. Enables Docker at boot, creates `/opt/mini-forge/{infrastructure,sites,backups}`
   and the `mini-forge` network.
4. Writes `/opt/mini-forge/infrastructure/compose.yaml`, validates it with
   `docker compose config`, and starts Traefik. Waits for its health check.
5. Re-inspects the server and checks Docker, Compose and Traefik respond.

It never changes SSH settings, users or firewall rules. If `ufw` is active
without 80/443 open, the log says so and shows the command to run.

Provisioning also starts a shared Postgres 17 and Redis 7.4. Their admin
passwords are generated on the server (`/opt/mini-forge/infrastructure/.env`)
and never leave it. Both listen only on the internal `mini-forge-data`
network, which has no outside access, and publish no ports.

### Traefik and the Docker socket

Traefik routes only containers labelled `traefik.enable=true`, redirects
HTTP to HTTPS, and gets certificates from Let's Encrypt (stored in a volume,
so they survive restarts). Its dashboard and API are off.

Traefik does not mount the Docker socket. It talks to
`tecnativa/docker-socket-proxy` on an internal network, which allows only
read requests for containers, events, version and ping. A compromised Traefik
can list containers and their labels, but cannot start, stop or exec into
them. The proxy itself has the socket read-only; anything with socket access
is effectively root on the host, which is why the proxy has no published
port.

### Host keys

The first successful connection pins the server's SSH host key (trust on
first use; compare it with `ssh-keygen -lf` output on the server if you want
to be sure). Later connections are refused before any credentials are sent
if the key changes. After reinstalling a server, use **Forget host key**.

## Sites

**Sites → New site** records the domain, repository, image repository and
which processes run (web always; queue worker and scheduler optional). It
also creates a production environment: a fresh `APP_KEY`, and credentials
for the site's own Postgres database and Redis user.

**Apply configuration** writes everything to `/opt/mini-forge/sites/<slug>/`:

- `compose.yaml`, generated from `resources/templates/laravel/compose.blade.php`
  and checked with `docker compose config` before use;
- `.env` (mode 640, group www-data), mounted read-only at `/app/.env`;
- the Postgres role and database, and the Redis ACL user.

A deployed site's containers are then recreated so they pick up the change.
Start, stop and restart act on the Compose project; the containers table on
the site page is read live over SSH each time it loads.

### Isolation between sites

| | Per site | Shared |
|---|---|---|
| Containers | own Compose project and default network | web joins the `mini-forge` proxy network |
| Postgres | own database and login role; `CONNECT` revoked from others | one Postgres server |
| Redis | own logical database (1-63) and ACL user; cannot `SELECT` another database or run `FLUSHALL`, `CONFIG`, `MOVE`, `COPY` and other admin commands | one Redis server |
| Files | own directory and `storage` volume | — |

Credentials go to the server in files uploaded over SFTP (mode 600, deleted
right after use), never on a command line.

### Environment variables

Edit them on the site's **Environment** tab, or paste a whole `.env` with
**Import .env**. Every value is encrypted in Mini Forge's database; names
containing KEY, SECRET, PASSWORD, TOKEN and similar are marked sensitive and
masked. Changes take effect on the next apply or deploy.

### Removing a site

Removal asks you to type the domain. It always removes the containers, the
site directory and the Redis data; the Postgres database and `storage` volume
are deleted only if you tick the box.

## Trying it without a VPS

`tests/Integration/server` is a disposable Ubuntu 24.04 container with
systemd, sshd and a `deploy` user with passwordless sudo:

```bash
docker compose -f tests/Integration/server/compose.yaml up -d --build
```

Add a server for `127.0.0.1`, port `2222`, user `deploy`, then copy its
public key in:

```bash
docker exec -i mini-forge-test-server-vps-1 sh -c 'cat > /home/deploy/.ssh/authorized_keys && chown deploy: /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys'
```

(paste the key, then Ctrl-D). Provisioning installs a real Docker Engine
inside the container. Remove it all with `docker compose -f
tests/Integration/server/compose.yaml down -v`.

## Security notes

- Private keys, passphrases and every environment value are encrypted with
  `APP_KEY` and never shown again in the UI.
- Host, port, user and domain fields are validated against strict patterns
  before they can reach a shell command, and every remote command is built
  in `App\Infrastructure\SSH\Shell`, which quotes all values.
- Step logs keep only the last 16 KB of output per step.
- There is no public sign-up; accounts come from `php artisan make:filament-user`.
- Changing a server's host or port drops its pinned host key so the next
  connection pins the new one.

## Development

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
```
