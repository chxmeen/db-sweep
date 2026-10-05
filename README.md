# db-sweep

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)
![CLI Tool](https://img.shields.io/badge/type-CLI-blue)

Find and purge orphaned MySQL/MariaDB databases that no longer belong to any local project.

Over time, local dev databases accumulate from deleted branches, abandoned side projects, and moved directories. `db-sweep` crawls your project directories for database references (`.env`, `wp-config.php`, Docker Compose), compares them against your MySQL server, and identifies the leftovers.

## Install

**Composer (global)**

```bash
composer global require chameen/db-sweep
```

**From source**

```bash
git clone https://github.com/chxmeen/db-sweep.git
cd db-sweep
composer install --no-dev
chmod +x bin/db-sweep
```

## Usage

```bash
# Scan ~/Projects (default) and interactively manage orphans
db-sweep

# Preview only — no prompts, no drops
db-sweep --dry-run

# Scan a different directory
db-sweep --path=~/Code

# Custom MySQL credentials
db-sweep --host=127.0.0.1 --port=3306 -u root --pass=secret

# Ignore specific databases
db-sweep --ignore=legacy_app,scratch_db

# Drop without backing up first
db-sweep --no-backup

# Force drop databases with active connections
db-sweep --force-kill
```

## Backups

Before dropping any database, `db-sweep` pipes `mysqldump` through `gzip -9` and saves a snapshot to:

```
~/.db-sweep/backups/<database>_<timestamp>.sql.gz
```

Skip this with `--no-backup`. If a backup fails or produces an empty file, the drop is aborted for that database.

## Ignore File

Add database names to `~/.dbignore` (one per line) to permanently skip them:

```
my_playground
shared_testing_db
```

Lines starting with `#` are treated as comments.

## License

MIT
