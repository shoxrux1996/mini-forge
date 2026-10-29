# Mini Forge

A local-first, Forge-style manager for Laravel apps on your own Ubuntu VPS.
Mini Forge runs on your laptop and manages servers over SSH; nothing of it
runs on the server, and sites keep running when your laptop is off.

Built with Laravel 13, Filament 5, SQLite and phpseclib.

## Status

| Phase | What | State |
|---|---|---|
| 1 | Foundation: login, data model, server management | Done |
| 2 | SSH, host-key pinning, inspection, Docker + Traefik provisioning | Next |
| 3 | Sites, Compose templates, environment editor, start/stop | Planned |
| 4 | Image build and push, deployments, health checks, rollback | Planned |
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

Removing a server only forgets it in Mini Forge. Nothing on the VPS changes.

## Security notes

- Private keys, passphrases and every environment value are encrypted with
  `APP_KEY` and never shown again in the UI.
- Host, port, user and domain fields are validated against strict patterns
  before they can reach a shell command.
- There is no public sign-up; accounts come from `php artisan make:filament-user`.
- Changing a server's host or port drops its pinned host key so the next
  connection pins the new one.

## Development

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
```
