---
name: deploying-to-forge
description: "Use when deploying a Laravel app to Laravel Forge, or changing its deploy script, site or server settings: Forge's standard setup only, with a copy of the deploy script in .forge/deploy.sh."
---

# Deploying to Laravel Forge

## The rule

Forge's standard setup only: Forge's default zero-downtime deploy script and Forge's own settings. No extra deploy-script lines or server tweaks (nginx, PHP-FPM/OPcache, cron, supervisor) without Jasper's go for that specific line.

- Start from the deploy script Forge generates for a zero-downtime site. Don't add, reorder or remove lines.
- The standard is Forge's default zero-downtime script with nothing added: no smoke-test command (such as `deploy:smoke`), no `NODE_OPTIONS`, and no PHP-FPM reload beyond the named exception below.
- Health checks (`/up`) and shared paths are Forge settings, not script lines.
- Configure the site through Forge's own settings (environment, queue workers, scheduler, SSL, PHP version), not by editing files on the server.
- Need something beyond that? Ask first, naming the exact line or setting and why. One approval covers that one line, not similar ones.
- Don't SSH in to edit nginx, PHP-FPM, OPcache, cron or supervisor config by hand.

## Site user

New sites run under the single `forge` user, with no website isolation, unless Jasper says otherwise for that specific site.

- Don't turn on website isolation or create a per-site user for a new site without that go.
- An existing site moves to `forge` only by a clean recreate, never an in-place user change, following a per-site plan Jasper approves first:
  - create a new site under `forge`;
  - leave the database intact;
  - stop the old site rather than deleting it, until Jasper says to delete it.

## The allowed exceptions

Two named exceptions are pre-approved. Everything else still needs Jasper's go for that specific line.

### Migrating through another connection


When the app's database user can't run migrations, the migrate line may name a connection that can: `php artisan migrate --database=<connection> --force`. Write the evidence down (the error the default user hits, or the grant it lacks) in the pull request and in a comment next to the line in `.forge/deploy.sh`.

### Reloading PHP-FPM

The script's last line may be a plain `sudo -S service $FORGE_PHP_FPM reload`, as Laravel's Envoyer does.

- Plain: no `flock`, no `/tmp` lock file, nothing wrapped around it.
- Last line only.
- First check that PHP-FPM's `process_control_timeout` is set, so a reload lets running requests finish instead of cutting them off. Checking means:
  - set `process_control_timeout = 60s` by hand in `/etc/php/<version>/fpm/php-fpm.conf` (for example `/etc/php/8.5/fpm/php-fpm.conf`); Forge has no setting for it;
  - record it in the app's `.forge` notes, next to `.forge/deploy.sh`;
  - re-apply it after a PHP upgrade or reinstall.

  This one server line is approved as part of the reload exception. Any other server change still needs Jasper's go for that specific line.

## Keep a copy in the repo

A copy of the deploy script lives in the repo at `.forge/deploy.sh`, so a change to it is reviewed like code.

- When the script in Forge changes (with approval), update `.forge/deploy.sh` in the same change, and the other way round.
- The copy is a record, not a second source: Forge runs the script saved in Forge. Keep the two identical.
- Never put secrets in it; they belong in the site's environment in Forge.

## Checklist

- [ ] Deploy script is Forge's default zero-downtime script, with nothing added (the only exceptions: a documented `--database=` migrate flag, and a plain PHP-FPM reload as the last line).
- [ ] If the script reloads PHP-FPM, `process_control_timeout` is set.
- [ ] The site runs as `forge`, without website isolation (or Jasper approved otherwise for this site).
- [ ] Health checks and shared paths are set in Forge, not in the script.
- [ ] `.forge/deploy.sh` matches the script in Forge.
- [ ] Every extra line or server tweak has a recorded approval for that line.
