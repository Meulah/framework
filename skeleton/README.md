# Meulah application

This is the minimal application starter for the Meulah framework. It is intended to be installed with:

```bash
composer create-project meulah/starter my-app
```

## Start

1. Copy `.env.example` to `.env` and configure the application.
2. Point the web server document root to `public/`.

Application code belongs in `app/`, routes in `routes/`, views in `resources/views/`, configuration in `config/`, and migrations in `database/migrations/`.

Use the root launcher for framework commands:

```bash
php meulah make:migration create_users_table
php meulah migrate
php meulah migrate:status
```
