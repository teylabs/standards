---
name: deploying-to-forge
description: "Use when deploying a Laravel app to Laravel Forge, or changing its deploy script, site or server settings: Forge's standard setup only, with a copy of the deploy script in .forge/deploy.sh."
---

# Deploying to Laravel Forge

## The rule

Forge's standard setup only: Forge's default zero-downtime deploy script and Forge's own settings. No extra deploy-script lines or server tweaks (nginx, PHP-FPM/OPcache, cron, supervisor) without Jasper's go for that specific line.

- Start from the deploy script Forge generates for a zero-downtime site. Don't add, reorder or remove lines.
- The standard is Forge's default zero-downtime script with nothing added: no smoke-test command (such as `deploy:smoke`), no PHP-FPM reload, no `NODE_OPTIONS`.
- Health checks (`/up`) and shared paths are Forge settings, not script lines.
- Configure the site through Forge's own settings (environment, queue workers, scheduler, SSL, PHP version), not by editing files on the server.
- Need something beyond that? Ask first, naming the exact line or setting and why. One approval covers that one line, not similar ones.
- Don't SSH in to edit nginx, PHP-FPM, OPcache, cron or supervisor config by hand.

## The one allowed exception

When the app's database user can't run migrations, the migrate line may name a connection that can: `php artisan migrate --database=<connection> --force`. Write the evidence down (the error the default user hits, or the grant it lacks) in the pull request and in a comment next to the line in `.forge/deploy.sh`. Nothing else is pre-approved.

## Keep a copy in the repo

A copy of the deploy script lives in the repo at `.forge/deploy.sh`, so a change to it is reviewed like code.

- When the script in Forge changes (with approval), update `.forge/deploy.sh` in the same change, and the other way round.
- The copy is a record, not a second source: Forge runs the script saved in Forge. Keep the two identical.
- Never put secrets in it; they belong in the site's environment in Forge.

## Checklist

- [ ] Deploy script is Forge's default zero-downtime script, with nothing added (a documented `--database=` migrate flag is the only exception).
- [ ] Health checks and shared paths are set in Forge, not in the script.
- [ ] `.forge/deploy.sh` matches the script in Forge.
- [ ] Every extra line or server tweak has a recorded approval for that line.
