# Trust Levels: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **No per-request cost.** Level, lock, hold and last check are columns on `users`; policies, limits and the permission-group processor read only the actor. A page costs 0 extra queries (measured on/off, guest/admin/member, 1 vs 20 items). Keep it that way.
- **Reading is tracked from core's read PATCH** (`UserRead`, `Tracking\Tracker`): posts read = the jump in last-read number (capped 30), reading time = gap since that member's previous read save today (capped 120 s, `trust_daily.last_read_at`). A visit = the first `last_seen_at` write each day. Never add a JS timer or beacon.
- **Stats are computed per chunk, one aggregate query per kind** (`Stats\Stats::forUsers`); never per member. `Evaluator::run` checks members seen since the last run + unplaced members; `--all` (nightly) adds every Regular and folds days older than 100 into `trust_stats.base_*`.
- **Levels 1–3 automatic, 4 staff only.** Regular is the only level that drops for going quiet (0.9 margin, `grace_days`). Upheld reports drop one level and cap re-promotion for 6 months (`demoted` events with reason `flags`). Locked = never moves. Silenced = never climbs. Sleuth open `evader` sign-up = held at 0.
- **Placement:** members who joined before `installed_at` are placed once from history (`Evaluator::estimate`), never below Basic, no alert. Until placed, `Exempt::level` treats them as Basic.
- **Level groups are virtual** (`InheritPermissions`): nobody is added to them; levels ≤ the member's add the group's permissions. A level can't use Admin/Guest/Member/Mod or a Ladder rung group (`Listener\ValidateSettings`).
- **Limits only restrict** (`Access\TrustPolicy` returns FORCE_DENY or null; `Limits\Limits` throws ValidationException). Exempt = admin, `bypassLimits` permission, `exempt_groups`. Links/images/mentions are counted in the parsed XML.
- **Spam:** our auto-hide only without Docket. Any hide with no actor on a reported post silences a level ≤ `silence_up_to` author; a staff hide/delete of a reported post upholds it. With Docket, `Integration\DocketCrowd` replaces Docket's `Crowd` to weigh reporters by level.
- **Only the member and staff see a level.** The user fields are `visible()`-guarded; `/api/trust-levels/users/{id}` 404s for anyone else.
- Config is ONE JSON setting cleaned by `Config::clean`; defaults live there and in the admin page's `DEFAULTS`/`LIMIT_DEFAULTS` (keep them in step).
- extend.php reads no class constants outside closures. Buzz/Onramp extenders are built by class name in `Integration\Optional`.

## Testing

- MySQL: `DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_DATABASE=trust_test DB_USERNAME=root DB_PREFIX=dev_ composer test:setup && composer test`.
- PostgreSQL: a throwaway `postgres:16-alpine` container on 55432, `DB_DRIVER=pgsql … DB_PREFIX=tl_`. Check `vendor/flarum/testing/src/integration/tmp/config.php` for the driver before calling a run MySQL or PostgreSQL.
- Settings are read once per process: set config with `$this->setting()` BEFORE the app boots; fixtures added after boot need direct inserts.
- Core's own post throttle is 10 s: move the test clock between posts.
- `composer analyse:phpstan` (level 6).
