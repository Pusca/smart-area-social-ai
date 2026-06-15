# AI Workflow (Codex + Laravel)

## Scope

This document describes how to work safely and efficiently in this repository (`C:\dev\smart-area-social-ai`) with Codex.

## Local run

### 1) Install dependencies

```powershell
composer install
npm install
```

Optional bootstrap shortcut from `composer.json`:

```powershell
composer run setup
```

### 2) Environment setup

Copy the example env file and generate app key:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Run DB migrations:

```powershell
php artisan migrate
```

### 3) Start app + frontend

Backend:

```powershell
php artisan serve
```

Frontend (Vite):

```powershell
npm run dev
```

Alternative one-command dev workflow defined in Composer:

```powershell
composer run dev
```

## Tests and lint

### Tests

```powershell
php artisan test
```

or:

```powershell
composer test
```

### PHP lint/format

`laravel/pint` is present in `require-dev`.

Check/fix formatting:

```powershell
./vendor/bin/pint
```

### Frontend build check

```powershell
npm run build
```

No dedicated JS lint script is currently defined in `package.json`.

## Codex safety in this repo

- Sandbox mode: `workspace-write` (read/write only inside repo workspace).
- Network: restricted by default.
- Do not run destructive git/file commands unless explicitly requested.
- Prefer non-interactive commands and small, reviewable diffs.
- Validate changes with local tests/build when possible.

## Common commands

```powershell
# app + vite in separate terminals
php artisan serve
npm run dev

# migrations
php artisan migrate

# clear config/cache when env changes
php artisan config:clear
php artisan cache:clear

# tests
php artisan test
composer test

# formatter
./vendor/bin/pint

# production frontend build
npm run build
```

## Where to change environment values

- Source template: `.env.example`
- Local runtime config: `.env` (not committed)

Common keys used here:

- `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `DB_CONNECTION` and DB credentials
- `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`
- `MAIL_*`
- `OPENAI_API_KEY`
- `OPENAI_BASE_URL` (defaults to `https://api.openai.com`)
- `OPENAI_TEXT_MODEL`, `OPENAI_IMAGE_MODEL`
- `OPENAI_TIMEOUT`, `OPENAI_TIMEOUT_IMAGES`, `OPENAI_IMAGE_SIZE`

After editing `.env`, run:

```powershell
php artisan config:clear
```
