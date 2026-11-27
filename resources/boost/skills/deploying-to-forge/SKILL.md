---
name: deploying-to-forge
description: "Use when deploying a Laravel app to Laravel Forge, or changing its deploy script, site or server settings: Forge's standard setup only, with a copy of the deploy script in .forge/deploy.sh."
---

# Deploying to Laravel Forge

## The rule

Forge's standard setup only: Forge's default zero-downtime deploy script and Forge's own settings. No extra deploy-script lines or server tweaks (nginx, PHP-FPM/OPcache, cron, supervisor) without Jasper's go for that specific line.

- Start from the deploy script Forge generates for a zero-downtime site. Don't add, reorder or remove lines.
- The standard is Forge's default zero-downtime script with nothing added: no smoke-test command (such as `deploy:smoke`), no `NODE_OPTIONS`, no PHP-FPM reload.
- Health checks (`/up`) and shared paths are Forge settings, not script lines.
- To clear old releases from OPcache, schedule one nightly PHP-FPM reload in Forge's Scheduler: `sudo -S service php<version>-fpm reload`. That's a Forge setting, not a script line.
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

### Caching built assets in nginx

One nginx line is approved (Jasper, 2026-11-27): in the site's server block, above `location / {`, add

```nginx
location ^~ /build/ { add_header Cache-Control "public, max-age=31536000, immutable"; try_files $uri =404; }
```

- It's safe because Vite's built assets are content-hashed: a changed file gets a new name, so a cached copy is never stale.
- Keep a copy in the repo at `.forge/nginx-build-cache.conf`, identical to what's in Forge.
- Forge regenerates the site's nginx config at times (for example, when you change the domain, SSL or PHP version), which drops the line. Re-apply it from the copy each time.
- Only this line. Any other nginx change still needs its own go.

## Keep a copy in the repo

A copy of the deploy script lives in the repo at `.forge/deploy.sh`, so a change to it is reviewed like code.

- When the script in Forge changes (with approval), update `.forge/deploy.sh` in the same change, and the other way round.
- The copy is a record, not a second source: Forge runs the script saved in Forge. Keep the two identical.
- Never put secrets in it; they belong in the site's environment in Forge.

## Checklist

- [ ] Deploy script is Forge's default zero-downtime script, with nothing added (a documented `--database=` migrate flag is the only script exception).
- [ ] If the site caches `/build/`, its nginx has the approved line exactly as in `.forge/nginx-build-cache.conf`, re-applied after any config regeneration.
- [ ] A nightly PHP-FPM reload is scheduled in Forge's Scheduler, not in the script.
- [ ] The site runs as `forge`, without website isolation (or Jasper approved otherwise for this site).
- [ ] Health checks and shared paths are set in Forge, not in the script.
- [ ] `.forge/deploy.sh` matches the script in Forge.
- [ ] Every extra line or server tweak has a recorded approval for that line.
