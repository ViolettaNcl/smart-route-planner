# Installation and Setup

[🇷🇺 Русская версия](setup_guide.md)

The project **needs no database**. Composer is optional (only needed for the
alternative class-autoloading path — the built-in `bootstrap.php`
autoloader works fine without it).

## Option 1 — PHP's built-in server (fastest)

Requires only PHP 8.1+ with the `curl`, `json`, and `mbstring` extensions
(usually enabled by default).

```bash
git clone <repository-url>
cd smart-route-planner

# The trained model already ships in the repo (src/ML/mlp_weights.json,
# with src/ML/model_weights.json as a fallback) —
# you don't need to run training before the first launch.
php -S localhost:8000 -t public
```

Open `http://localhost:8000` in a browser.

## Option 2 — XAMPP (no terminal required)

1. Install [XAMPP](https://www.apachefriends.org/) (make sure the PHP
   component is selected during installation; MySQL isn't needed, you can
   uncheck it).
2. Copy the project folder into `htdocs` (e.g.
   `C:\xampp\htdocs\smart-route-planner`). The trained model already ships
   in the repo — no need to retrain before the first run.
3. Start Apache from the XAMPP control panel.
4. Open in a browser: `http://localhost/smart-route-planner/public/`.

> Note the `/public/` at the end of the URL — the web-facing part of the app
> is deliberately kept in its own folder so that `src/` and `bin/` are never
> directly reachable by URL.

## Option 3 — Docker / docker-compose (simplest for server deployment)

Requires only Docker and Docker Compose — no PHP install on the host at all,
everything is already inside the image.

```bash
git clone <repository-url>
cd smart-route-planner

# var/ needs to be writable by the process inside the container (www-data,
# typically UID 33) — with a bind mount from the host, the host directory's
# permissions override whatever the Dockerfile set. The simplest fix for a
# demo/portfolio project:
chmod -R 777 var

# API keys are optional — see .env.example. Without them, the AI trip
# assistant runs in its honest rule-based fallback mode.
cp .env.example .env

docker compose up --build
```

Open `http://localhost:8080` (the port is set in `.env`, see `PORT`).

The trained model already ships in the repo — no need to retrain before the
first run. Changes under `var/` (the geocoding cache, rate-limiter state,
model weights after live fine-tuning) survive `docker compose restart` and
image rebuilds, thanks to the volume defined in `docker-compose.yml`.

**Deploying the same image to a VPS:** copy the repository to the server (or
set up `git pull` + `docker compose up --build -d` via CI/CD), and put
Nginx/Caddy in front of the container as a reverse proxy for HTTPS (Let's
Encrypt) — the container itself just serves plain HTTP on the port set in
`PORT`.

## Verifying the setup

```bash
php tests/run.php
```

Should print `Passed: 132, failed: 0` (the test count may grow in future
versions).

## Optional — AI trip assistant with a real LLM

With zero configuration, the AI trip note already works — offline, via clear
rules (see `docs/neural_net.md` and `src/AI/TripAssistantService.php`). To
have a real LLM (Anthropic Claude or OpenAI) generate the text instead, set
a key using one of two methods:

**Method A — environment variable** (PHP's built-in server):

```bash
export ANTHROPIC_API_KEY=sk-ant-...
php -S localhost:8000 -t public
```

**Method B — a local config file** (more convenient for XAMPP, where
`export` isn't always easy to pass through to Apache):

```bash
cp config.local.php.example config.local.php
```

Open `config.local.php` and uncomment the line with the key you want to use.
The file is already in `.gitignore` — the key won't accidentally end up in
git.

Both methods are equivalent; the key isn't required for any other part of
the app (routing, weather, and points of interest all work with no keys at
all).

## Optional — Composer

If you have Composer installed and prefer the standard autoloader:

```bash
composer dump-autoload
```

This generates `vendor/autoload.php`, which `bootstrap.php` automatically
loads if the file exists. Nothing needs to be installed via Composer — the
project has no external PHP dependencies.

## Common Issues

**"Model weights file not found"** — the trained weights already ship in the
repo (`src/ML/mlp_weights.json`, `src/ML/model_weights.json`), so this error
means the file is corrupted or was deleted. Fix: `php bin/train_model.php`
(regenerates both files).

**Cities aren't found / suddenly stopped resolving** — Nominatim sometimes
temporarily throttles heavy request volume. The app handles this gracefully
(the city just lands in the "skipped" list with a warning), but the
geocoding cache (`var/geocache/`) won't re-request cities it has already
resolved.

**The route is drawn as straight lines instead of following roads** — this
isn't a bug: the public OSRM demo server is occasionally temporarily
unavailable or rate-limits frequent requests. The app is specifically
designed not to crash in that case — it falls back to great-circle
distance, and the UI honestly labels this under the result.

**PHP warns about `curl` or `json`** — these extensions are usually enabled
by default in XAMPP; if disabled, enable them in `php.ini`
(`extension=curl`, `extension=json`) and restart Apache.

**Docker: "Permission denied" writing to var/** — a bind mount from the host
(`./var:/var/www/html/var` in `docker-compose.yml`) means the actual write
permissions are determined by the host directory, not by what the
`Dockerfile` set inside the image. Fix: `chmod -R 777 var` on the host
before the first run (see "Option 3 — Docker" above).
