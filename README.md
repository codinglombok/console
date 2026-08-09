# lombokclarion/console

**CLI kernel with 12 built-in commands: migrate, seed, optimize, audit, work.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/console
```

## Namespace

```php
LombokClarion\Console
```

## What's Inside

| Class | Role |
|-------|------|
| `ConsoleKernel` | CLI dispatcher: parses argv, routes to registered commands |
| `Command` | Command interface: `name()`, `description()`, `run()` |

### Built-in Commands (12)

| Command | Description |
|---------|-------------|
| `migrate` | Run pending migrations (`--connection`, `--force`) |
| `migrate:rollback` | Rollback last migration batch |
| `migrate:status` | Show migration status table |
| `make:migration` | Generate a migration file |
| `seed` | Run database seeders (`--class`, `--seed`, `--connection`) |
| `seed:status` | Show seeder execution status |
| `make:seeder` | Generate a seeder file |
| `optimize` | Compile container + config + routes + assets |
| `work` | Queue worker (`--queue`, `--loop`, `--sleep`) |
| `audit:sql` | SQL injection audit via TokenScanner |
| `audit:security` | Security audit (CSRF, headers, debug mode) |
| `user:create` | Create a user (app-level command) |

## Usage

```bash
# Run migrations
php bin/lombokclarion migrate
php bin/lombokclarion migrate --connection=reporting

# Seed database
php bin/lombokclarion seed
php bin/lombokclarion seed --class=DemoWidgetsSeeder

# Compile for production
php bin/lombokclarion optimize

# Start queue worker
php bin/lombokclarion work --queue=default --loop --sleep=3

# Security audits
php bin/lombokclarion audit:sql app/
php bin/lombokclarion audit:security
```

### Custom Commands

```php
use LombokClarion\Console\Command;

class PruneCommand implements Command {
    public function name(): string { return 'prune'; }
    public function description(): string { return 'Prune old records'; }
    public function run(array $args): int {
        // ...
        return 0;
    }
}
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
