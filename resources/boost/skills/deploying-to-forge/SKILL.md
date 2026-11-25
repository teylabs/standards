---
name: deploying-to-forge
description: "Use when deploying a Laravel app to Laravel Forge, or changing its deploy script, site or server settings: Forge's standard setup only, with a copy of the deploy script in .forge/deploy.sh."
---

# Deploying to Laravel Forge

## The rule

Forge's standard setup only: Forge's default zero-downtime deploy script and Forge's own settings. No extra deploy-script lines or server tweaks (nginx, PHP-FPM/OPcache, cron, supervisor) without Jasper's go for that specific line.

- Start from the deploy script Forge generates for a zero-downtime site. Don't add, reorder or remove lines.
- Configure the site through Forge's own settings (environment, queue workers, scheduler, SSL, PHP version), not by editing files on the server.
- Need something beyond that? Ask first, naming the exact line or setting and why. One approval covers that one line, not similar ones.
- Don't SSH in to edit nginx, PHP-FPM, OPcache, cron or supervisor config by hand.

## Keep a copy in the repo

A copy of the deploy script lives in the repo at `.forge/deploy.sh`, so a change to it is reviewed like code.

- When the script in Forge changes (with approval), update `.forge/deploy.sh` in the same change, and the other way round.
- The copy is a record, not a second source: Forge runs the script saved in Forge. Keep the two identical.
- Never put secrets in it; they belong in the site's environment in Forge.

## Checklist

- [ ] Deploy script is Forge's default zero-downtime script.
- [ ] `.forge/deploy.sh` matches the script in Forge.
- [ ] Every extra line or server tweak has a recorded approval for that line.
