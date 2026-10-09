# BRANCHING.md — KTN Gestión ↔ skeleton workflow

> How this downstream (`ktn_gestion`) relates to `symfony-backoffice-skeleton`
> and how generic improvements flow between them. The procedure below is the
> skeleton's own document; "downstream" includes this repository.

## This repository

| Item | Value |
|---|---|
| Upstream | `symfony-backoffice-skeleton` — remote `skeleton` → `git@github.com:ortiz-facundo/symfony-backoffice-skeleton.git` |
| Origin | `git@github.com:ortiz-facundo/ktn_gestion.git` |
| Created from | `git clone` of skeleton `main` at `32638c3` (2026-10-09) — full skeleton history is shared, so every later skeleton commit cherry-picks cleanly |
| Sync watermark | local tag `skeleton-sync` (initialized at `32638c3`); push it with `git push origin main skeleton-sync` |
| Proposal trailer | skeleton commits generalized from this repo carry `Source: ktn_gestion` — skip them on sync |
| Dev ports | `8070` (PHP) / `33096` (DB) / `1075`+`8075` (Mailpit) / `5193` (Vite) |

Quick sync (details below):

```bash
git fetch skeleton
git log skeleton-sync..skeleton/main --oneline --reverse
git cherry-pick <sha>            # each, oldest first; --skip for Source: ktn_gestion
git tag -f skeleton-sync skeleton/main
```

## Repositories

| Role | Repository | Purpose |
|---|---|---|
| Skeleton (upstream) | `symfony-backoffice-skeleton` | Common code: auth, RBAC, i18n, CRUD base, deploy tooling. No public homepage. |
| Downstream A | `web-nova-argentum` | Adds a public marketing site, a contact form, and the cPanel deploy tooling. |
| Downstream C | `ktn_gestion` | Administration system for Ketten, an event production company (this repository). |
| Downstream B | `sci_gestion` | General administration system for a textile company (Scachi — Indumentaria Institucional). Today ships the Catalog and Inventory modules; future modules will be added on the same architecture. |

The three repos share the commit `4f9d474b65b222dc1b591ff14b8962a77da11b24` ("feat(i18n): full bilingual sweep") as a real git ancestor. Every downstream's `main` is descended from this commit, so skeleton commits are always cherry-pickable into a downstream without `--allow-unrelated-histories`. Downstreams don't replay that whole shared history though — they sync via a bounded, tag-tracked cherry-pick (see "Each downstream pulls it" below); a full-history rebase/merge was tried and abandoned once elsewhere in this family of projects (gestion-nova, 2026-08-31) because skeleton's own later commits were themselves generalized from downstream code, producing large non-substantive conflicts.

## Layout

```
symfony-backoffice-skeleton/        ← this repo (upstream)
web-nova-argentum/                  ← downstream A
sci_gestion/                        ← downstream B
```

All three live in the same parent folder. Each downstream registers the skeleton as a git remote named `skeleton` (already done in this workspace; verify with `git remote -v`).

## Daily workflow

### Generic improvement lands in the skeleton

```bash
cd symfony-backoffice-skeleton
git checkout -b feat/<short-name>
# ... edit, lint, test ...
git commit -m "feat(...): <description>"
git checkout main
git merge --no-ff feat/<short-name>
git push origin main
```

### Each downstream pulls it — tag-bounded cherry-pick

Downstreams do **not** rebase or merge the whole shared history against
skeleton. Each downstream keeps a local `skeleton-sync` tag marking the
last skeleton commit it has already accounted for. A sync only ever
replays the commits *after* that tag — bounded to "what's new since last
time," not "everything since the beginning of time."

```bash
cd ../web-nova-argentum     # or sci_gestion
git fetch skeleton
git log skeleton-sync..skeleton/main --oneline --reverse   # what's new, oldest first
```

For each commit in that list, oldest first:

```bash
git cherry-pick <sha>
# resolve conflicts if any, then: git cherry-pick --continue
# or skip it entirely if it carries a `Source: <this-downstream>` trailer
# (see "Commit hygiene" below) — it's already got the specific original:
git cherry-pick --skip
```

Once every new commit has been applied or explicitly skipped, advance the
watermark and push:

```bash
git tag -f skeleton-sync skeleton/main
docker compose exec php bin/console cache:clear
git push origin main skeleton-sync
```

**Initializing `skeleton-sync` in a downstream that doesn't have it yet:**
point it at the skeleton commit that downstream has already effectively
accounted for (whatever it's manually verified is functionally represented
in its own history), not at the shared ancestor — otherwise the first sync
tries to replay the entire shared history again.

```bash
git tag skeleton-sync <skeleton-commit-sha>
git push origin skeleton-sync
```

**Commit hygiene on the skeleton side.** Skeleton commits should stay
atomic — one concern per commit — so a downstream can cherry-pick or skip
each one independently. When a skeleton commit is itself a generalization
of code that came *from* a specific downstream, tag the commit message
with a trailer:

```
Source: <downstream-name>
```

That downstream's sync skips any commit carrying its own name in
`Source:` — it already has the more-specific original, so cherry-picking
the generalized version back would conflict for no benefit. Other
downstreams (without a matching `Source:` trailer) still cherry-pick it
normally.

### Downstream-only feature lands

```bash
cd ../web-nova-argentum     # or sci_gestion
git checkout -b feat/<short-name>
git commit ...
git checkout main
git merge --no-ff feat/<short-name>
git push origin main
```

If the feature is generic enough to belong in the skeleton, lift it:

```bash
cd ../symfony-backoffice-skeleton
git remote add nova git@github.com:ortiz-facundo/web-nova-argentum.git   # one-time, if not already there
git fetch nova
git cherry-pick <commit-sha>          # lift the generic bits
# if the lifted commit is a generalization of that downstream's own code
# (it already has the specific original and would just skip this on its
# own next sync), mark it so — see "Commit hygiene" above:
git commit --amend -m "$(git log -1 --format=%B)

Source: web-nova-argentum"
git push origin main
# now both downstreams can pull it via the tag-bounded cherry-pick sync
```

## Verifying after every sync

```bash
git log skeleton-sync..skeleton/main --oneline   # should be empty — everything's applied or skipped
git log --oneline <old-skeleton-sync-sha>..main  # the commits the sync added
docker compose exec php bin/console cache:clear
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction   # if migrations are involved
docker compose exec php bin/console lint:twig templates/
docker compose exec php bin/console lint:yaml translations/
docker compose exec php bin/console lint:translations --locale=en
docker compose exec php bin/console lint:translations --locale=es
```

Open the affected surfaces in the browser and confirm the expected behaviour:
- Skeleton (`http://localhost:8000`) — backoffice login, user CRUD, profile settings.
- web-nova-argentum (`http://localhost:8020`) — public homepage, contact form, backoffice.
- sci_gestion (`http://localhost:8010`) — backoffice, catalog, inventory.
- ktn_gestion (`http://localhost:8070`) — backoffice and Ketten modules.

## Conflict resolution

Conflicts during a cherry-pick sync are normal — they mean the downstream edited the same lines as the skeleton improvement. Resolution rules:

- **Skeleton behaviour wins for shared code** (auth, RBAC, i18n, CRUD base, deploy tooling, profile settings, form theme).
- **Downstream brand wins for chrome** (project name in templates, brand colour in `tailwind.config.js` if added).
- **Downstream-only files** (e.g. `templates/home/`, `templates/contact/` in web-nova-argentum; `templates/catalog/`, `templates/inventory/` in sci_gestion) almost never conflict — accept either side.

If a single commit's conflict is hard to resolve, do the cherry-pick on a scratch branch first (`git checkout -b sync/skeleton-<date>`, cherry-pick there, verify, then fast-forward or `--no-ff` merge into `main`) rather than fighting it directly on `main`. If a skeleton commit clearly doesn't apply at all to this downstream (not just conflicting, but genuinely not wanted), `git cherry-pick --skip` it — the watermark still advances past it once the tag moves, so it won't resurface next sync.

## Watch-outs

1. **Skeleton's `main` is force-pushed only when the rescue is redone.** That has happened once (Aug 2026, "feat: bootstrap symfony-backoffice-skeleton from web-nova-argentum at 4f9d474") and is not a routine operation. Routine `main` updates on the skeleton are normal pushes.
2. **Dev environments use different host ports** so they can run concurrently:
   - Skeleton: `8000` (PHP), `33067` (DB), `1025`+`8025` (Mailpit)
   - web-nova-argentum: `8020` / `33076` / `1045`+`8045`
   - sci_gestion: `8010` / `33066` / `1035`+`8035`
   - ktn_gestion: `8070` / `33096` / `1075`+`8075` (Vite `5193`)
3. **Vite assets in `public/build/` are gitignored.** After any change to a Twig template or `assets/scripts/*`, run `npm run build` (or keep `npm run dev` running for HMR). Stale built bundles are a common source of "the button doesn't work" reports.