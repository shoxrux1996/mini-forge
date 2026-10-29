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
| 4 | Image build and push, deployments, health checks, rollback | Done |
| 5 | Metrics, containers, logs, Artisan commands, docs | Next |

## Run it locally

Requirements: PHP 8.4, Composer, SQLite, git, and Docker with buildx
(Docker Desktop has it) for building images.

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
queue worker that runs provisioning and deployments. Run it with a timeout of
an hour or more (`php artisan queue:work --timeout=3600`) if you start it
yourself: a cold image build can take several minutes.

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

## Deployments

**Registries** hold the credentials for a container registry (host, user,
token; the token is stored encrypted). A site uses one to push from this
machine and pull on the server. Without one, pushing uses your own `docker
login` and the server pulls anonymously. For GHCR, use a token with
`write:packages`.

**Deploy** on a site page queues a deployment and opens its live log:

| Step | Where | What |
|---|---|---|
| Clone repository | this machine | shallow clone of the branch; records the commit |
| Build and push image | this machine | `docker buildx build --platform <server arch> --push` |
| Write configuration | server | database, Redis user, `.env`, `compose.yaml` with the new image |
| Pull image | server | with a throwaway Docker config holding only that registry's login |
| Run migrations | server | `php artisan migrate --force` in a one-off container of the new image, hidden from Traefik |
| Start new containers | server | `up --force-recreate`, then waits for the web container's health check |
| Check the site through Traefik | server | `https://<domain><health path>` must answer 2xx through Traefik |
| Remove old images | server | keeps the running image, the one it replaced and the last 3 successful releases |

Until **Start new containers**, the old release keeps serving. If that step
or the Traefik check fails, Mini Forge writes the previous release's files
back and starts the previous image again (a **Switch back** step in the log).
A first deployment has nothing to switch back to; its containers are left
for inspection and the site is marked failed.

Images are tagged `<commit>-<deployment id>`, so every deployment has its own
tag. A repository can bring its own `.mini-forge/Dockerfile`; otherwise the
default Laravel image in `resources/templates/laravel/docker` is used. Either
way the image must serve HTTP on port 8080, have `curl`, and read its config
from `/app/.env`.

Sites set to **Prebuilt image from registry** skip the build: Deploy asks for
a tag (for example one GitHub Actions pushed). **Redeploy** releases the
running image again without building or migrating, for example after an
environment change. **Roll back**, on a successful deployment in the site's
Deployments tab, releases that deployment's image again.

Know before you rely on it:

- **Migrations are never reverted.** A switch back or rollback runs older code
  against the current schema, so keep migrations backwards compatible.
- **Releases are not zero-downtime.** Containers are recreated, so the site is
  briefly down during each release. A release that never becomes healthy is
  down until the health check gives up (about a minute) and the previous
  image is back.
- One deployment per site at a time. Deployments, applies, starts and
  restarts of a site share one lock.
- Step logs keep the last 16 KB per step, with registry tokens and secret
  environment values replaced by `[redacted]`.

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

Recreating the container (for example after changing its ports) keeps
Docker's data but resets the rest of the filesystem: add the key again and
provision again.

To try deployments, run a registry with a password inside the test server.
Port 5000 is published to `127.0.0.1`, so the same `localhost:5000/<name>`
image works for pushing from your Mac and pulling on the server:

```bash
docker run --rm --entrypoint htpasswd httpd:2.4-alpine -Bbn forge <password> > htpasswd
docker cp htpasswd mini-forge-test-server-vps-1:/root/htpasswd
docker exec mini-forge-test-server-vps-1 docker run -d --name test-registry --restart unless-stopped \
  -p 5000:5000 -v /root/htpasswd:/auth/htpasswd:ro -e REGISTRY_AUTH=htpasswd \
  -e REGISTRY_AUTH_HTPASSWD_REALM=test -e REGISTRY_AUTH_HTPASSWD_PATH=/auth/htpasswd registry:3
```

Then add a registry for `localhost:5000` (user `forge`) and use an image
repository like `localhost:5000/shop`. A public repository such as
`https://github.com/laravel/laravel` (branch `13.x`) deploys as is.

## Security notes

- Private keys, passphrases and every environment value are encrypted with
  `APP_KEY` and never shown again in the UI.
- Host, port, user and domain fields are validated against strict patterns
  before they can reach a shell command, and every remote command is built
  in `App\Infrastructure\SSH\Shell`, which quotes all values.
- Step logs keep only the last 16 KB of output per step, with registry
  tokens and secret environment values redacted.
- Builds run `git` and `docker` with argument lists, never a shell string.
  Registry logins go into a temporary Docker config that is deleted after
  each push or pull; neither your `~/.docker/config.json` nor the server's is
  touched.
- There is no public sign-up; accounts come from `php artisan make:filament-user`.
- Changing a server's host or port drops its pinned host key so the next
  connection pins the new one.

## Development

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
```
