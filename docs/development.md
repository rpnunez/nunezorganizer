# Development with Docker

The Docker environment runs the whole stack with nothing installed on your machine except Docker and VS Code. It uses PHP 8.4 with Xdebug, nginx, MariaDB, the Vite dev server, a queue worker, the scheduler, Mailpit and phpMyAdmin.

- [First run](#first-run)
- [Services and ports](#services-and-ports)
- [Daily commands](#daily-commands)
- [Xdebug with VS Code](#xdebug-with-vs-code)
- [Tests and code quality](#tests-and-code-quality)
- [Windows notes](#windows-notes)
- [Troubleshooting](#troubleshooting)

## First run

Prerequisites:
- **Docker Desktop**, with the WSL2 backend on Windows
- **Git**
- **VS Code**, with the recommended extensions it offers when you open the folder (`.vscode/extensions.json`)

```bash
git clone https://github.com/rpnunez/nunezorganizer.git
cd nunezorganizer
cp .env.example .env              # PowerShell: Copy-Item .env.example .env
docker compose up -d --build      # first build takes a few minutes
docker compose exec app php setup.php
```

`setup.php`:
1. installs Composer packages
2. generates `APP_KEY`
3. waits for MariaDB, then migrates
4. creates the demo users (alice@example.test and bob@example.test, password `password`)
5. checks the test database

The `node` container installs npm packages itself and starts Vite. Watch it with `docker compose logs -f node`.

Then open http://localhost:8480.

## Services and ports

Every port is published on `127.0.0.1` only, and you can change each one in `.env`. If you change `NUNEZ_HTTP_PORT`, keep `APP_URL` in sync.

| Service | What it runs | URL / port | `.env` variable |
|---|---|---|---|
| `nginx` | Serves `public/`, passes PHP to `app` | http://localhost:8480 | `NUNEZ_HTTP_PORT` |
| `app` | PHP 8.4-FPM + Composer + Xdebug 3 | (internal) | |
| `queue` | `php artisan queue:listen --queue=notifications,default` (reloads code per job) | n/a | |
| `scheduler` | `php artisan schedule:work` | n/a | |
| `node` | `npm run dev`: Vite with live reload | http://localhost:5480 | `NUNEZ_VITE_PORT` |
| `mariadb` | MariaDB 10.11: `nunezorganizer` and `nunezorganizer_test` | `127.0.0.1:33480` | `NUNEZ_DB_PORT`, `MARIADB_VERSION` |
| `mailpit` | Catches all outgoing mail | http://localhost:8482 | `NUNEZ_MAILPIT_PORT` |
| `phpmyadmin` | Database UI (logged in as the app user) | http://localhost:8481 | `NUNEZ_PMA_PORT` |
| Xdebug → VS Code | Xdebug connects to VS Code on your machine | port 9013 | `NUNEZ_XDEBUG_PORT` |

The DB credentials come from `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`. The container uses them when it creates the database, so set them **before** the first `docker compose up`. Changing them later requires removing the `mariadb-data` volume.

To test against an older production MariaDB (e.g. 10.6), set `MARIADB_VERSION=10.6`, then `docker compose down -v && docker compose up -d`. **`-v` deletes the dev database.**

## Daily commands

```bash
docker compose up -d                          # start (the setup is already done)
docker compose down                           # stop (data is kept)
docker compose ps                             # what's running
docker compose logs -f app queue scheduler    # follow logs
docker compose exec app bash                  # shell in the PHP container

docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app php artisan nunez:invite someone@example.com
docker compose exec app composer require vendor/package
docker compose exec node npm install some-package

docker compose up -d --build app queue scheduler   # after changing docker/php/*
docker compose down -v                             # reset everything, including the database
```

`setup.php --check` reports what's missing or misconfigured without changing anything:
`docker compose exec app php setup.php --check`.

## Xdebug with VS Code

Xdebug is installed and on by default, but a debug session **only starts when you trigger one**. Normal page loads and test runs stay fast.

1. In VS Code, install the **PHP Debug** extension (`xdebug.php-debug`).
2. Open the **Run and Debug** panel, choose **Listen for Xdebug (Docker)**, and press F5. VS Code now listens on port 9013.
3. Set a breakpoint, e.g. in `routes/web.php` or a controller.
4. Trigger a session:

| From | How |
|---|---|
| Browser | Install the "Xdebug helper" extension (Chrome/Edge: *Xdebug helper by JetBrains*; Firefox: *Xdebug Helper*), set its IDE key to `VSCODE`, click the bug icon → **Debug**, and reload the page |
| Any request | Add `?XDEBUG_TRIGGER=1` to the URL, or send the `XDEBUG_TRIGGER` cookie |
| Artisan / CLI | `docker compose exec -e XDEBUG_TRIGGER=1 app php artisan your:command` |
| A test | `docker compose exec -e XDEBUG_TRIGGER=1 app php artisan test --filter=SomeTest` |
| Queue jobs | Set `XDEBUG_MODE_WORKERS=debug` in `.env`, run `docker compose up -d queue`, then dispatch with the trigger set |

The path mapping `/var/www/html` → your project folder is already in `.vscode/launch.json`.

**Turning it down:**
- `XDEBUG_MODE=off` in `.env`, then `docker compose up -d app`, disables Xdebug completely.
- `XDEBUG_MODE=coverage` enables code coverage:
  `docker compose exec -e XDEBUG_MODE=coverage app php artisan test --coverage`

**Changing the port:** set `NUNEZ_XDEBUG_PORT` in `.env`, change `"port"` in `.vscode/launch.json` to match, then `docker compose up -d app`.

**Nothing stops at breakpoints?**
- Is VS Code listening? The status bar turns orange.
- Did the request carry the trigger? Check the browser extension icon.
- Windows Firewall: allow VS Code on private networks the first time it asks.
- `docker compose exec app php -r 'var_dump(ini_get("xdebug.mode"));'` should print `debug,develop`.

## Tests and code quality

```bash
docker compose exec app php artisan test               # MariaDB database nunezorganizer_test
docker compose exec app composer lint:check            # Pint
docker compose exec app composer types:check           # PHPStan / Larastan
docker compose exec app composer test                  # all of the above
bash deploy/tests/run.sh                                # deploy + setup.php script tests (WSL/Linux/macOS)
```

The same steps as CI (`.github/workflows/ci.yml`):

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan migrate:rollback --force
docker compose exec app php artisan migrate --force
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app php artisan test
```

## Windows notes

- **Line endings:** `.gitattributes` keeps files LF (and `.cmd` files CRLF). If you cloned with `core.autocrlf=true` and scripts in containers fail with `$'\r': command not found`, re-clone with `git config --global core.autocrlf input`.
- **Speed:** Docker Desktop reads files from a Windows drive (`C:\...`) slowly. If pages take more than a second or two, clone the repository inside WSL2 (e.g. `\\wsl$\Ubuntu\home\you\nunezorganizer`) and open it with VS Code's **WSL** extension. The Docker and Xdebug setup is unchanged.
- **`node_modules`** lives in a Docker volume, so Linux packages never mix with Windows ones. If you also run `npm` on Windows, it creates a separate `node_modules` folder that the containers ignore.
- **Ports in use:** if `docker compose up` reports `port is already allocated`, change that `NUNEZ_*_PORT` in `.env`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `502 Bad Gateway` right after `up` | `app` is still starting or `vendor/` is missing: run `docker compose exec app php setup.php` |
| Page without styles, browser console shows `localhost:5480` errors | The `node` container isn't running or is still installing: `docker compose logs -f node` |
| Changes to CSS/Blade don't reload | Vite uses polling in Docker; give it a second. Make sure `node` is up |
| `SQLSTATE[HY000] [2002]` | MariaDB isn't healthy yet (`docker compose ps`), or `DB_HOST` isn't `mariadb` |
| `Access denied for user` after editing `DB_*` | The DB was created with the old values: `docker compose down -v` (deletes data), then `up -d` |
| 500 with `tempnam()` / permission errors (Linux hosts) | Set `UID`/`GID` in `.env` to your `id -u` / `id -g`, then `docker compose up -d --build` |
| Emails don't show up | Open Mailpit (http://localhost:8482); `.env` must have `MAIL_HOST=mailpit`, `MAIL_PORT=1025` |
