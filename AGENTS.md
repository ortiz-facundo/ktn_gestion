# AGENTS.md — Ketten / KTN Gestión

> Project context for AI coding assistants (Claude Code, Aider, Cursor, etc.).
> Read this first. Update freely as the project evolves — this is the
> project's living rulebook, not a read-only artifact.

---

## TL;DR

- **KTN Gestión** — administration system for **Ketten**, an event
  production company. Downstream of `symfony-backoffice-skeleton`
  (remote `skeleton`); no public homepage — `GET /` redirects to `/login`.
  Business modules (events, clients, suppliers, staff, budgets, …) are added
  on top of the shared backoffice architecture, never inside its shared modules.
- Stack: **PHP 8.4**, **Apache 2.4** (single container), **MariaDB 10.11**,
  **Vite 5**, **Tailwind 3.4**, **Doctrine ORM** (attribute mapping), Symfony
  form login + stateless CSRF + login throttling. **Dev/prod parity** — the
  local `mariadb:10.11` container mirrors the cPanel shared-hosting MariaDB
  version; `serverVersion` in the DSN pins Doctrine DBAL to the same
  `MariaDB1010Platform` used in production.
- Three Docker services: `php` (Apache), `database` (MariaDB), `composer`
  (sidecar, `profile=tools`, never auto-starts). Frontend assets build on
  the host with `npm run build` / `npm run dev`.
- No host PHP, no host Composer, no host MariaDB — only Docker.
- Dev endpoints (chosen not to collide with the other projects): app
  `http://localhost:8070`, DB `127.0.0.1:33096`, Mailpit SMTP `1075` /
  UI `http://localhost:8075`, Vite `5193`. Compose project `ktn_gestion`.
- Authenticated **backoffice** gated by RBAC. Login at `/login`,
  dashboard at `/dashboard`, user/role/permission CRUD under `/admin/...`,
  self-service at `/account/...`. **No public registration** — admins
  create users via `/admin/users/new`, who confirm via email link.
- Dev test user: `test@ketten.local` / `test` (`ROLE_ADMIN`,
  `USER_STATE_ACTIVE`).
- **Shared code comes from the skeleton** via a tag-bounded cherry-pick
  (`skeleton-sync` tag, see `BRANCHING.md`). Reusable improvements made here
  go back to the skeleton as Engram **skeleton proposals** (see below).

---

## Mandatory reading before doing anything

1. This file (you're here).
2. `README.md` — full architecture, workflow, troubleshooting.
3. `BRANCHING.md` — tag-bounded cherry-pick sync workflow for the
   downstream projects.
4. `INFRASTRUCTURE_PLAN.md` — rationale for the infrastructure decisions
   (only consult when "why" questions come up, e.g. why Vite 5 and not 6,
   why PHP 8.4 and not 8.3, why no named volumes for vendor/var).
5. `.opencode/skills/frontend-design/SKILL.md` — full aesthetic guidance.
   **Required reading before any UI work.**
6. `HARNESS.md` — the planner/builder Herdr workflow, when running as
   part of the two-agent dev harness.

---

## Hard rules

In priority order. If you find yourself about to violate one of these, stop.

1. **Never commit, never push, never amend history, never create branches.**
   The user commits manually. Don't run `git commit`, `git push`, `git
   checkout -b`, `git rebase`, or `git reset` unless the user explicitly asks
   for it. Exception: the user *can* ask, e.g. "commit this for me."
2. **Never run `composer` on the host.** Use
   `docker compose --profile tools run --rm composer …`. The host has no PHP
   and no Composer installed.
3. **Never run `npm` inside the php container.** It runs on the host.
4. **Never edit `vendor/`, `var/`, `node_modules/`, `public/build/`.** These
   are generated. If you need to change behavior, change the source of truth
   (`composer.json`, `assets/`, `vite.config.js`) and reinstall/rebuild.
5. **Never put `apache/` or `docker/` in `.dockerignore`.** The Dockerfile
   `COPY`s from both directories. `.dockerignore` is for build-context
   slimming only.
6. **Never use the `vite:` top-level config key in `config/packages/vite.yaml`.**
   The actual extension alias is `pentatrion_vite:`. Using `vite:` will fail
   with "no extension able to load the configuration for 'vite'".
7. **Always run `npm run build` after adding/changing Twig templates with new
   Tailwind classes** (or run `npm run dev` for HMR). Tailwind's JIT only
   emits classes that appear in `content` globs — if you write
   `bg-foo-500` in a template without rebuilding, the class won't be in
   `public/build/assets/app-*.css` and the page will render unstyled.
8. **Always use full vendor/package names** in `composer require`. `twig`
   fails — use `twig/twig`. Same for any other package without an
   auto-resolved alias.
9. **Never downgrade Vite below 5** without also upgrading Node. Vite 5 is
   the last line that supports Node 18. Vite 6+ requires Node 18.18+ but is
   increasingly Node 20+ focused; Vite 8 requires Node 20.19+. The host here
   is Node 18.19.
10. **Never downgrade `doctrine/doctrine-bundle` below 3.2 or
    `doctrine-migrations-bundle` below 4.0.** Both require PHP 8.4; the
    Dockerfile is pinned to `php:8.4-apache` because of this.
11. **Never name a Doctrine entity table `user`.** It is a reserved
    word in PostgreSQL and `user` is also the built-in MariaDB
    account-management function, which means unquoted queries can break on
    either engine. Our `User` entity uses
    `#[ORM\Table(name: 'app_user')]`. The migration that creates the
    schema must use the same unquoted/quoted name. The `User` entity
    lives at `App\Entity\Administration\User` (under the Administration
    module, because admins manage it).
12. **Never define a permission string anywhere except `App\Security\Permission`.**
    The catalog is the canonical source of truth. The seed migration and
    fixtures iterate `Permission::all()`; the voter matches by exact string
    equality. A typo anywhere breaks RBAC silently. To add a permission:
    append a constant + add to `all()` + ship a new migration that calls
    `Permission::all()` in its `up()`.
13. **Never add a public homepage to the skeleton.** The skeleton is
    backoffice-only. Downstream projects that need a public homepage should
    override `App\Controller\HomeController` and add their own
    `templates/home/` directory. The skeleton's redirect is the baseline.
14. **Brand strings are downstream identity.** This project replaced the
    skeleton placeholders (`Skeleton` → `KTN Gestión`, `skeleton.local` →
    `ketten.local`, Compose/Apache names → `ktn_gestion` / `ketten-gestion`).
    When a skeleton sync conflicts on these lines, keep the Ketten values.

---

## Layout — modules, type-first

The project is organised by **module**, not by entity type. Each module owns
its own `Controller/`, `Entity/`, `Form/`, `Repository/`, etc. **under a
single top-level namespace per type** — `App\Controller\Administration\…`,
`App\Entity\Administration\…`, etc. Shared/horizontal concerns live at the
type root (`App\Controller\AbstractCrudController`, `App\Twig\UserAvatarExtension`,
etc.).

```
src/
├── Command/Dev/                       [shared] TestEmailCommand
├── Controller/
│   ├── AbstractCrudController.php     [shared horizontal] CRUD base
│   ├── HomeController.php             [shared] redirect to /login (overridable)
│   ├── Account/                       [Account module] self-service
│   │   ├── ProfileSettingsController.php
│   │   └── SecuritySettingsController.php
│   ├── Administration/                [Administration module] user/role/permission CRUD + impersonation
│   │   ├── ImpersonateController.php
│   │   ├── PermissionController.php
│   │   ├── RoleController.php
│   │   └── UserController.php
│   ├── Backoffice/                    [Backoffice module] chrome
│   │   └── DashboardController.php
│   └── Security/                      [Security module] auth flow
│       ├── AccountConfirmationController.php
│       ├── LoginController.php
│       └── LogoutController.php
├── DataFixtures/
│   ├── AppFixtures.php                [shared] orchestrator
│   ├── Administration/                PermissionFixtures, RoleFixtures, UserFixtures
├── Entity/
│   ├── Administration/                Permission, Role, User
│   └── Security/                      AccountConfirmationToken
├── Form/
│   ├── Account/                       ChangePasswordFormType
│   ├── Administration/                RoleType, UserType
│   └── Security/                      AccountPasswordFormType
├── Mailer/
│   └── Security/                      AccountMailer
├── Repository/
│   ├── Administration/                PermissionRepository, RoleRepository, UserRepository
│   └── Security/                      AccountConfirmationTokenRepository
├── Security/                          [top-level — cross-cutting]
│   ├── Permission.php                 central catalog (PERMISSION_*, all(), isValid())
│   └── PermissionVoter.php            universal voter (matches PERMISSION_*)
├── Service/
│   ├── Account/                       VerifyCurrentPassword
│   └── Security/
│       ├── UserStateChecker.php       firewall gate
│       └── UserLifecycle/             all state-machine + token services
└── Twig/
    ├── UserAvatarExtension.php        [shared]
    └── Administration/
        └── ImpersonationExtension.php

templates/                              [mirrors the module split]
├── base.html.twig                      [shared] backoffice layout
├── _avatar.html.twig                   [shared]
├── _state_pill.html.twig               [shared]
├── form/theme.html.twig                [shared] Tailwind form theme
├── shared/crud/                        [shared horizontal] CRUD partials
│   ├── _breadcrumbs.html.twig
│   ├── _delete_form.html.twig
│   ├── _empty_state.html.twig
│   ├── _form.html.twig
│   ├── _list.html.twig
│   └── _row_actions.html.twig
├── backoffice/                         [Backoffice module — chrome]
│   ├── base.html.twig
│   ├── _sidebar.html.twig
│   ├── _nav_macros.html.twig
│   └── dashboard/index.html.twig
├── account/                            [Account module — self-service]
├── administration/                     [Administration module — CRUD]
└── security/                           [Security module]
    ├── login.html.twig, confirm.html.twig, confirm_invalid.html.twig
    └── emails/                         account-lifecycle templates

config/packages/      Per-bundle configs. Symfony Flex writes via `###> pkg ###`
                      markers — **preserve them** so recipes stay idempotent on reinstall.
config/routes/        Route imports only. Controllers are auto-discovered via attributes.
migrations/           Doctrine migrations. Committed (flat — Doctrine convention).
translations/         Per-module subfolder convention
├── shared/, account/, administration/, backoffice/, security/   per-module
│   └── security/messages.{en,es}.yaml   source-of-truth translations
docker/               Dockerfile support files (opcache, apcu). NOT in .dockerignore.
apache/               vhost config. NOT in .dockerignore.
.opencode/skills/     Skills, including frontend-design aesthetic guidance.
```

Module map:

| Module | Owns |
|---|---|
| **Administration** | Backoffice CRUD for User, Role, Permission + impersonation. Includes the `User` entity itself (admin-managed). |
| **Account** | Self-service: profile view, change-password. Anything under `/account/*` that the user does for themselves. |
| **Security** | Auth flow: login, logout, account-confirmation, token issuance, state machine, login throttling. Owns the universal `PermissionVoter`, the central `Permission` catalog (at `App\Security\`), and `UserStateChecker`. |
| **Backoffice** | Backoffice chrome: dashboard landing, layout, sidebar nav, nav renderer. |
| **Shared** | Anything that spans ≥2 modules: `AbstractCrudController`, CRUD partials, `HomeController`, `UserAvatarExtension`, `Dev\TestEmailCommand`, `AppFixtures`. Lives at the type root (`src/Controller/`, `src/Twig/`, etc.) and at the top-level namespace (`App\Controller`, `App\Twig`). |

When new business-domain entities (e.g. catalog, inventory, listings) arrive
in a downstream project, each gets its own module — not a "Business" bucket.
The module name should match the domain concept.

Everything else (`bin/`, `vendor/`, `var/`, `public/index.php`, `config/bundles.php`,
`config/services.yaml`, `config/routes.yaml`, `src/Kernel.php`) is generated
or boilerplate — don't edit unless you know why.

---

## Build & run — exact commands that work

```bash
# Start the stack (rebuilds the php image if Dockerfile changed)
docker compose up -d --build

# Stop (keeps the MariaDB data volume)
docker compose down

# Reset everything including the database
docker compose down -v && docker compose up -d --build

# Symfony console
docker compose exec php bin/console <cmd>

# Composer (always via the sidecar)
docker compose --profile tools run --rm composer <cmd>

# HMR on the host (one terminal, leave running)
npm run dev

# Production build of frontend assets (writes to public/build/)
npm run build
```

URLs once running:

- App:               <http://localhost:8070>  (redirects to /login)
- Login:             <http://localhost:8070/login>
- Dashboard:         <http://localhost:8070/dashboard>  (auth required)
- Admin (CRUD):      <http://localhost:8070/admin/users>, `/admin/roles`, `/admin/permissions`
- Profile:           <http://localhost:8070/account/profile-settings>
- Mailpit (dev):     <http://localhost:8075>  (SMTP capture UI)
- Vite:              <http://localhost:5193>  (only when `npm run dev` is running)
- DB:                `127.0.0.1:33096` from host (loopback only; via `compose.override.yaml`), `database:3306` from inside containers

Test user (dev fixtures, after running the first-time seed):

- email:    `test@ketten.local`
- password: `test`
- role:     `ROLE_ADMIN` (all five permissions)

---

## Authentication, RBAC, and authorization

### Authentication

- **Form login only** at `/login`. Username is email (`_email` form field);
  password is `_password`. CSRF via the framework's stateless mechanism
  (see "CSRF gotcha" in Known Landmines).
- **Logout** at `/logout` is POST-only with CSRF (`csrf_token_id: logout`).
  The firewall intercepts — the `LogoutController` action should never
  execute; if it does, it throws because something is misconfigured.
- **Login throttling**: 5 attempts per 15 minutes per IP, via
  `symfony/rate-limiter`. Configured in `config/packages/security.yaml`.

### RBAC: User → Role → Permission

```
       PERMISSION_*  (string constants in App\Security\Permission)
            │
            │  many-to-many
            ▼
          ROLE_*    (Role entity; e.g. ROLE_ADMIN, ROLE_USER)
            │
            │  many-to-many
            ▼
          USER      (app_user table)
```

- **Permission catalog** is hardcoded in `App\Security\Permission` as
  `public const string PERMISSION_*`, grouped by owning **module** in
  `Permission::groups()` (`backoffice`, `administration` today; downstreams
  add their own). The current 5 are:
  - `backoffice`: `PERMISSION_VIEW_BACKOFFICE`
  - `administration`: `PERMISSION_MANAGE_USERS`,
    `PERMISSION_MANAGE_ROLES`, `PERMISSION_MANAGE_PERMISSIONS`,
    `PERMISSION_IMPERSONATE_USERS` — see "Impersonation" below

The module grouping is **presentation metadata only** (the role permission
picker and the read-only catalog page render checkboxes/rows under one
`<fieldset>` per module). It does not change authorization — the voter
still matches by the literal `PERMISSION_*` string. Group keys are
declared in `Permission::groups()`; the group label is read from the
`permission_group.<key>.label` translation key. Group order in the
catalog equals declaration order.
- **Roles** are rows in the `role` table. Baseline fixtures seed exactly two:
  `ROLE_ADMIN` (all catalog permissions) and `ROLE_USER` (view only).
- **Users** carry roles via the `user_role` join table. New users get
  `ROLE_USER` by default; admins reassign via `/admin/users/{id}/edit`.
- **The permission seed migration** runs `Permission::all()` and inserts one
  row per constant. The migration is idempotent (`ON CONFLICT DO NOTHING`).
- **RoleFixtures is also idempotent for new permissions**: when a new
  permission is added to `Permission::all()`, re-running `RoleFixtures`
  (via `doctrine:fixtures:load --append`) attaches it to `ROLE_ADMIN` if
  it isn't already. The hardcoded `$admin` row keeps its id; `addPermission()`
  is the no-op when the link already exists.

### How `is_granted(Permission::PERMISSION_*)` works

Two layers, by design:

1. **`User::getRoles()`** flattens: it returns
   `['ROLE_USER', <role names>, <permission names from each role>]`.
   This makes the built-in `RoleVoter` work — it sees the permission
   strings in the user's role set and grants.
2. **`App\Security\PermissionVoter`** is a backup that handles attributes
   starting with `PERMISSION_`. It checks `User::getRoles()`. (Without
   the voter, the built-in `RoleVoter` would ABSTAIN on `PERMISSION_*`
   attributes because they don't start with `ROLE_`, and the request
   would 403.)

The two layers are intentional redundancy. If you change `getRoles()` to
return only role names, the voter still saves you. If you remove the
voter, `getRoles()` still saves you. Either alone is correct.

### Adding a new permission

1. `src/Security/Permission.php`: append a constant + add to the right
   group in `groups()`. Add a new group key if the permission belongs to
   a new module (downstreams typically add `catalog`, `inventory`, etc.).
2. Generate a new migration (it must call `Permission::all()` and INSERT
   the new constant name with `ON CONFLICT DO NOTHING`).
3. Re-run migrations in any environment that needs it.
4. Run `doctrine:fixtures:load --append` to attach the new permission to
   `ROLE_ADMIN` automatically (the fixtures' `syncPermissions()` is
   idempotent for permission grants).
5. Optionally assign the permission to other roles via the backoffice.
6. Add `permission_group.<group>.label` to both translation locales
   (`translations/security/messages.<locale>.yaml`) when introducing a
   new module group.

---

## Downstream sync workflow

See `BRANCHING.md` for the full procedure. The short version:

- This project is a **downstream** of `symfony-backoffice-skeleton`
  (sibling folder `../symfony-backoffice-skeleton`, remote `skeleton` →
  `git@github.com:ortiz-facundo/symfony-backoffice-skeleton.git`).
  `origin` is `git@github.com:ortiz-facundo/ktn_gestion.git`.
- Shared code is pulled with a **tag-bounded cherry-pick**: the local
  `skeleton-sync` tag marks the last skeleton commit already accounted for;
  a sync only replays `skeleton-sync..skeleton/main`, oldest first. Never
  rebase/merge the full skeleton history.
- Skip skeleton commits carrying a `Source: ktn_gestion` trailer — they
  generalize code that already exists here.
- Domain-specific work (Ketten business modules, branding) stays here.
- Don't fix shared modules only here when the fix is generic: implement it
  here if needed, then record a skeleton proposal. Git mutations (commits,
  tags, pushes, syncs) require explicit user authorization.

---

## Skeleton proposals (engram)

Downstream work that could improve the skeleton is recorded as a
**skeleton proposal** in [engram](https://github.com/Gentleman-Programming/engram)
memory, so it can be reviewed later from the skeleton. The memory store is
local to the developer's machine (`~/.engram/`) and shared by all projects;
each proposal lives in the project it came from.

### When to save one (downstream projects)

After a change in a downstream is finished, save a proposal if it touches a
**shared module** (Account, Administration, Backoffice, Security,
`AbstractCrudController` / `CrudListQueryTrait`, `templates/shared/`, the form
theme, shared translations) or a **frontend utility** (`assets/`,
`tailwind.config.js`, `vite.config.js`, reusable Twig partials). Changes that
only touch domain modules (catalog, inventory, sales, homepage, contact, …)
are not proposals. The user can also ask for one at any time ("register this
as a skeleton proposal"). Don't save proposals for work that came *from* the
skeleton through a sync.

Save it with `mem_save`, in the downstream's own project:

- `type`: `proposal`
- `topic_key`: `skeleton-proposal/<slug>` — kebab-case feature name. Saving
  the same key again updates the proposal instead of duplicating it.
- `title`: `Skeleton proposal: <short feature name>`
- `content`:

  ```
  **State**: pending
  **What**: what the change does
  **Why generic**: which shared module / frontend utility it touches and why other projects benefit
  **Commits**: <sha …>   (or "uncommitted")
  **Where**: main files
  ```

Never put client or business data (customers, prices) in a proposal — code
facts only.

### States

| State | Meaning |
|---|---|
| `pending` | Proposed, not reviewed yet |
| `implemented (skeleton <sha>)` | Generalized into the skeleton by that commit |
| `discarded (<reason>)` | Reviewed and rejected |

Change the state with `mem_update` on the proposal's id, replacing the
`**State**:` line (`find: "**State**: pending"`, `replace: "**State**:
discarded (…)"`). Only `pending` proposals show up in reviews.

### Reviewing proposals (skeleton — not from this repo)

- **With an agent:** run `/skeleton-proposals` in a session opened in the
  skeleton repo. It lists every pending proposal from all downstreams, walks
  through them with you, and records each decision. When one is implemented,
  the skeleton commit gets the `Source: <downstream>` trailer (see
  `BRANCHING.md`).
- **From the terminal, no agent:** `engram search pending --type proposal --all`

---

## Shared Twig CRUD base

## Notifications

The Notification module uses three entities (`Notification`, `PushSubscription`,
`NotificationDelivery`) and concrete services under `src/Service/Notification/`.
Keep this deliberately simple: no Messenger, custom value objects, gateway
interfaces, or notification-definition registries without a concrete need.
`CreateNotification::execute()` stores one recipient's notification and queues
per-device delivery rows in the same transaction. Downstream business services
choose recipients, translation keys, routes, deduplication keys, and optional
permissions from the canonical `App\Security\Permission` catalog.

The bell and inbox live under `templates/notification/`; device settings are
linked from Account. The bell sits in the sidebar footer at `lg`+ and in the
mobile top bar below it. Inbox and panel rows show a Heroicon per notification
`type`: register new types in `NotificationIcon::TYPES`
(`src/Service/Notification/NotificationIcon.php`; exact type, then module prefix
before the first dot, then the bell). Rows also carry a module tag: the type's
prefix, labelled by `app.notification.module.<prefix>` (falls back to the
capitalized prefix). `NotificationInbox::present()` returns both, so Twig and
`assets/js/notifications.js` never keep their own maps. The inbox reuses the
shared CRUD chrome (`_breadcrumbs`, `_list_title` via `embed`, `_empty_state`,
`_pagination`). All visible strings are in bilingual notification catalogs.
The root-scoped `public/sw.js` handles push only, never caches backoffice pages.
Only Chrome desktop/Android endpoints are currently allowed. Explicit logout
detaches the browser subscription; impersonation blocks subscription mutations.
Native notification clicks open a new browsing context rather than navigating
an existing backoffice tab, so notification interactions do not discard forms.
Archive state is `Notification::archivedAt`: only read items can be archived,
through `NotificationInbox`. Unread rows show Mark as read, read inbox rows show
Archive, and archived rows show Restore. Archived items are excluded from the
bell/feed and inbox/unread counts. Archive cancels pending deliveries; restore
retains `readAt` and never requeues pushes. All mutations require ownership + CSRF.

`app:notifications:process` is a bounded, flock-protected cron command (single
host); `NotificationDelivery` is its persistent queue. Dev tests use
`app:dev:test-notification <email>` followed by the processor. VAPID keys belong
in `.env.local` and remain stable across deployments. See README/README_DEPLOY
for commands and cPanel's `/sw.js` alias. No automatic worker service is started.

## Shared Twig CRUD base (continued)

Every entity that needs full CRUD (list, new, edit, delete) reuses these
moving parts:

### `src/Controller/AbstractCrudController.php`

A reusable controller that implements `list`, `new`, `edit`, `delete` (the
read-write verbs). Read-only details live on a `view` route. A concrete
controller extends it and supplies a small contract:
`getEntityClass()`, `getFormType()`, `getTemplatePrefix()`,
`getRoutePrefix()`, `getListColumns()`, `getRequiredPermission()`,
`getEntityLabel()`, `getEntityDisplayName()`, `findEntity()`,
`newEntity()`.

`AbstractCrudController` itself is *not* permission-gated — each concrete
controller is gated by a class-level `#[IsGranted(Permission::...)]` and
the per-method calls inside the parent use
`denyAccessUnlessGranted($this->getRequiredPermission())` as a defence in
depth.

### `templates/shared/crud/_*.twig`

Reusable partials. Each takes a documented contract of variables. To add
a new entity with full CRUD you only need four thin templates:

- `templates/<entity>/list.html.twig` — wraps `_list.html.twig`
- `templates/<entity>/new.html.twig` — wraps `_form.html.twig`
- `templates/<entity>/edit.html.twig` — wraps `_form.html.twig`
- `templates/<entity>/view.html.twig` — read-only details page; uses the
  same `<dl>` grid pattern as the form's inputs, plus an Edit link in the
  page header

`AbstractCrudController` ships a `view()` route that renders
`{templatePrefix}/view.html.twig` and an abstract
`getEntityDisplayName(object $entity): string` hook the concrete
controller overrides to label the entity in breadcrumbs and titles
(e.g. User returns `name ?? email`; Role returns `name`).

### Impersonation ("log in as")

Admins with `PERMISSION_IMPERSONATE_USERS` see a "users" icon (Heroicons
`users`) on every User row in `/admin/users`. Clicking it POSTs to
`ImpersonateController::impersonate`, which:

- Validates a per-row CSRF token (same pattern as delete).
- Rejects **self-impersonation** and **admin-to-admin impersonation** —
  "admin" = any role with a `PERMISSION_MANAGE_*` grant. Both rejections
  log to the `audit` channel and flash an error.
- Logs the start (actor, target, IP) to `audit`, then redirects to
  `/dashboard?_switch_user=<email>`. Symfony's `SwitchUserListener` catches
  it.

Three gates: Symfony's `switch_user` firewall block (gated by the
permission via `User::getRoles()`), the controller's `#[IsGranted]`, and
per-request CSRF.

`templates/backoffice/base.html.twig` renders an **amber banner** while a
`SwitchUserToken` is active, with an "Exit impersonation" button that
POSTs to `exitImpersonation` (CSRF + audit log).

To add impersonation to a new entity: add `PERMISSION_IMPERSONATE_X` +
seed migration + fixtures; in the controller, override
`getImpersonatePath()` (return route name) and `shouldHideImpersonate($entity)`
(return `true` to suppress specific rows). `AbstractCrudController::list()`
auto-generates per-row CSRF tokens when `getImpersonatePath()` is non-null.

**Audit channel:** `monolog` channel `audit` writes to `var/log/audit.log`
in dev, JSON-on-stderr in prod. New privileged actions should reuse this
channel via the autowired `Psr\Log\LoggerInterface $auditLogger` (target
`audit`).

### User lifecycle (state machine)

Accounts have three states, stored as constant-name strings on `app_user.state`:

| Constant | Meaning |
|---|---|
| `User::USER_STATE_PENDING` | Created by admin, awaiting confirmation and password set |
| `User::USER_STATE_ACTIVE` | Password set, can log in |
| `User::USER_STATE_DISABLED` | Admin disabled, login blocked |

Constants are the source of truth — every reference in PHP must use `User::USER_STATE_*`.
The DB CHECK constraint and the migration that creates it are generated from the same list.

State transitions go through `App\Service\Security\UserLifecycle\*` services, **never directly
on the entity** (the entity is pure data; services orchestrate validations + side-effects
+ audit). The shape:

- `ChangeUserState` — abstract base. Template method `execute()` →
  `checkValidations()` → `changeStateTo()` → `logAudit()`. Subclasses declare the
  target state via `USER_CHANGE_TO` and the allowed previous states via
  `PREVIOUS_VALID_STATES`.
- `ChangeUserStateToActive` — `Pending → Active` (sets password) and `Disabled → Active`
  (keeps existing hash). Sends the "activated" email only on the Pending path.
- `ChangeUserStateToDisabled` — `Pending → Disabled` and `Active → Disabled`. Invalidates
  pending confirmation tokens and sends the "disabled" email.
- `UserStateTransitionService` — convenience dispatcher. Controllers inject this and
  call `transitionTo($user, $target, $context)` rather than picking the concrete service
  themselves. Also houses `resendConfirmation()` which rotates the user's token and
  emails a new link (called on creation and on the admin "Resend" action).
- `AccountTokenIssuer` / `AccountTokenInvalidator` / `AccountTokenValidator` —
  selector+secret tokens (`AccountConfirmationToken`), 2-day TTL, sha256-stored secret,
  constant-time comparison via `hash_equals`.

Adding a new transition = a new concrete class + a new arm in the dispatcher's `match`.
Adding a new state = the changes above plus the entity constant plus `User::USER_STATES`
plus a CHECK-constraint migration.

**Confirmation flow:**
1. Admin creates a user via `/admin/users/new`. The form has no password field.
   `onCreateSuccess` calls `UserStateTransitionService::resendConfirmation()`,
   which issues a token and emails `/account/confirm?selector=…&secret=…`.
2. User clicks → `AccountConfirmationController` → `App\Form\Security\AccountPasswordFormType`.
   Submit calls `ChangeUserStateToActive::execute($user, $plainPassword)`,
   which hashes the password, sets state, sends the "activated"
   notification, consumes the token.
3. User logs in. `App\Service\Security\UserStateChecker::checkPreAuth`
   blocks Disabled users. (Symfony's `AuthenticatorManager` converts
   `DisabledException` to a generic "Invalid credentials" message to
   prevent user enumeration — intentional.)

Email change while Pending: the `POST_SUBMIT` listener on `App\Form\Administration\UserType`
detects the email change and calls `resendConfirmation()` to invalidate old
tokens and send a fresh link to the new address.

**Password policy:** `App\Form\Security\AccountPasswordFormType` enforces `Length min: 5`
(no PasswordStrength constraint). The service layer (`ChangeUserStateToActive`)
also enforces the same minimum as defence in depth. The policy is enforced
**only on set/reset** — existing hashes (including the test user's `test`)
are grandfathered.

### Internationalization

The entire UI is translated: every visible string in a Twig template,
every flash / FormError thrown by a controller, every form label / help
/ placeholder / constraint message, the email subjects + bodies, and the
entity-derived labels (the `USER_STATE_*` pills, the permission catalog).
Spanish (`es`) is the **default** for unauthenticated traffic and for any
signed-in user whose `locale` column is `NULL`. English (`en`) is the
**source-of-truth locale** — missing keys in the Spanish catalog fall
back to the English string.

#### Resolution order

1. The authenticated user's persisted `locale` preference
   (`App\Entity\Administration\User::$locale`), if not null.
2. The hard-coded default `'es'`.

The resolver is `App\EventListener\LocaleResolver` — a
`kernel.request` listener at priority 4 (after Symfony's
`LocaleAwareListener` and the security firewall). It writes both the
request locale (`$request->setLocale()`) AND the translator's cached
locale (`$translator->setLocale()` via the `LocaleAwareInterface`) —
the latter is necessary because the Translator caches `getLocale()`
on first read; if any service called `trans()` before this listener
ran, the cache would stick on `default_locale`.

#### Translation catalogs

Per-module subfolder convention (matches existing `translations/`
layout). All new files use the `messages` domain; validator messages
use `validators`. Keys are namespaced to avoid collisions.

| File | Purpose |
|---|---|
| `translations/<module>/messages.<locale>.yaml` | UI strings, organized by module (`security/`, `backoffice/`, `account/`, `administration/`, `shared/`) |
| `translations/<module>/validators.<locale>.yaml` | Constraint messages for forms in that module |

`en` files are the **source of truth**. `es` files are the
translations. Missing `es` keys fall back to `en` automatically
(Symfony's `fallback_locale: en`).

**The `app/` translation domain is intentionally absent** in the skeleton.
It is reserved for downstream projects that ship a public homepage (e.g.
`web-nova-argentum` uses `translations/app/`) and is the deliberate
exception to the per-module convention: marketing copy is shared across
the public pages and does not belong to a single module.

#### Adding a new string

1. Add the key to `translations/<module>/messages.en.yaml` first.
2. Mirror it in `translations/<module>/messages.es.yaml`.
3. Reference it from Twig with `{{ 'app.<module>.<key>'|trans }}` or
   from PHP with `$translator->trans('app.<module>.<key>')`.
4. Run `docker compose exec php bin/console cache:clear`.

#### Adding a new permission

1. Append the constant + add to the right group in
   `App\Security\Permission::groups()`. Add a new group key if the
   permission belongs to a new module.
2. Add a `permission.<NAME>.label` and `permission.<NAME>.description`
   key to `translations/security/messages.<locale>.yaml` (both locales).
   The role form's info-icon tooltip and the permission catalog both
   read from those keys.
3. The role/permission admin form (`RoleType::permissions` and the
   permission catalog page) reads from those keys via the
   `permission.<NAME>.label` lookup.
4. When you introduce a new module group, also add a
   `permission_group.<group>.label` key in both locales — the grouped
   fieldset legend in the role form and the row-group header in the
   catalog page render from it.

#### Locale switcher

`/account/profile-settings/locale` (controller
`App\Controller\Account\LocaleController`, form
`App\Form\Account\LocaleType`, template
`templates/account/locale.html.twig`). The switcher mutates the signed-in
User's `locale` column and redirects back; no cookie is written, so
anonymous traffic always falls back to Spanish.

#### Spanish tone

Tuteo (tú / tienes / puedes). No voseo. Examples:
"Define tu contraseña" / "Puedes iniciar sesión" / "Elige una nueva
contraseña".

#### Validation commands

```bash
docker compose exec php bin/console lint:translations --locale=en
docker compose exec php bin/console lint:translations --locale=es
docker compose exec php bin/console lint:yaml translations/
docker compose exec php bin/console lint:twig templates/
```

### Dev mail capture (Mailpit)

[Mailpit](https://github.com/axllent/mailpit) is the dev SMTP catcher.
Configured in `compose.override.yaml` as the `mailer` service: SMTP on
`mailer:1025`, web UI on `http://localhost:8075`. Auth accepts anything
(`MP_SMTP_AUTH_ACCEPT_ANY=1`), so no credentials needed.

- `.env.dev` defaults `MAILER_DSN=smtp://mailer:1025`. Override per-dev
  in `.env.local` with a real SMTP only for prod-realistic delivery testing.
- Smoke test: `docker compose exec php bin/console app:dev:test-email <addr>`
  (the command refuses to run in `APP_ENV=prod`).
- For real delivery, point `.env.local` at Mailgun/SES/your relay and
  capture in a throwaway mailbox. Mailtrap/MailHog don't exercise DNS,
  SPF, DKIM, or spam-folder routing — don't depend on them for prod checks.

### Deploy tooling

The skeleton ships `bin/deploy.php`, `scripts/build-cpanel-zip.sh`,
`README_DEPLOY.md`, and `src/Command/Deploy/SeedProdCommand.php` — a
cPanel/FTP-only deploy pipeline used by both downstream projects. See
`README_DEPLOY.md` for the full procedure. Downstream projects override
the cPanel hostname, FTP credentials, and the local repo path in
`.env.local` (gitignored).

### Form theme

`templates/form/theme.html.twig` overrides `form_div_layout.html.twig`
blocks to add Tailwind utility classes to the standard Symfony form
widgets. To add a new widget type, add a block override in this file
using the existing patterns (`form_label`, `form_widget_simple`,
`choice_widget_expanded`, `checkbox_widget`, `textarea_widget`,
`form_row`, `form_errors`, `form_label_content`,
`permission_picker_widget`, `phone_input_widget`, `tags_input_widget`,
`select_text_widget`). The `form_row_render` block places errors after
the field. `color_widget` styles the native HTML5 colour picker; downstream
entity forms named `Color` should use a distinct block prefix to avoid
colliding with this Symfony block.

**Reusable inputs and collection rows:** `App\Form\Type\PhoneInputType`
stores an international digit string and formats via the Twig
`format_phone_intl()` helper. `App\Form\Type\SelectTextType` renders a
text-backed `<select>` for page-specific JS to populate at runtime.
`App\Form\Type\TagsInputType` maps a list of
strings to a pipe-delimited chip input (tags cannot contain `|`).
`templates/shared/crud/_collection_table.html.twig` renders an editable
Symfony `CollectionType`: pass `form`, `collection`, `headers`, and
`row_template` (a single `<tr data-ctf-row>`). The form collection must set
`allow_add`, `allow_delete`, and `prototype` where applicable. Row templates
may use `ctf-remove-row`, `ctf-move-row-up/down`, `.ctf-drag-handle` and
quantity buttons; JS initializes them from `assets/app.js` and emits
`ctf:row-added/removed/moved` events. The row order defines submitted
positions. The shared CustomSelect supports `data-select-filter` on a form
field's `attr` and `data-option-title` / `data-option-icon` on its
`choice_attr`. Icons are text (e.g. emoji), not raw SVG markup. Shared
dismissible cards use `data-controller="dismissible-hint"` and
`data-action="dismiss-hint"`; an optional stable `data-hint-key` remembers
the dismissal in localStorage.

**Runtime timezone:** PHP defaults to `America/Argentina/Buenos_Aires`
via `docker/php/timezone.ini` in the image. Use explicit zones where
calendar business rules depend on one; rebuild the PHP image after changing
the ini. This affects implicit `DateTime` creation and Doctrine DATETIME
hydration, but not the MariaDB server timezone.

**Important gotcha:** the default `form_div_layout.html.twig` does NOT
define a `text_widget` block. Text-like inputs (text, email, password,
tel, url, search, number, etc.) all go through `form_widget_simple`.
Override that block to restyle all of them in one go.

**Permission picker (`permission_picker_widget`):** the role form's
permission checkboxes render through this block. It groups choices by
the permission's module (from `Permission::groups()`), renders a
`<fieldset><legend>` per group, and pairs each checkbox with a
focusable info button whose tooltip exposes the permission description
on hover and focus. The block is field-specific — it is only invoked
when the form field declares `block_prefix: 'permission_picker'`
(see `App\Form\Administration\RoleType`).

---

## Adding a new page

The skeleton ships without a public homepage. To add one (in a downstream
project):

1. Override `src/Controller/HomeController.php`. Replace the redirect with
   a render call to a new template.
2. Add `templates/home/index.html.twig` (and `templates/home/*.twig`
   partials) extending `base.html.twig`.
3. Add `translations/app/messages.<locale>.yaml` for the public-domain
   strings.
4. Add any new Vite entry to `vite.config.js` and render via
   `{{ vite_entry_link_tags('entry') }}` / `{{ vite_entry_script_tags('entry') }}`.
5. Run `npm run build` to pick up new Tailwind classes.

For a non-CRUD backoffice page: place the controller in the relevant
module's `Controller/` folder, extend `AbstractController`, add
`#[Route('/foo', name: '<module>_foo')]`, and a thin
`templates/<module>/foo/index.html.twig` that extends
`backoffice/base.html.twig`. Then `npm run build` (or have `npm run dev`
running) so any new Tailwind classes pick up. Verify the route with
`docker compose exec php bin/console debug:router | grep <module>_foo`.

For a new entity with full CRUD, follow "Shared Twig CRUD base" above and
use the `Role` / `templates/administration/role/` pattern as the canonical
reference.

---

## Adding a new Vite entry

Edit `vite.config.js` `build.rollupOptions.input`:

```js
input: {
  app: './assets/app.js',
  admin: './assets/admin.js',
}
```

In the relevant template:

```twig
{{ vite_entry_link_tags('admin') }}
{{ vite_entry_script_tags('admin') }}
```

---

## Database operations

```bash
# New entity
docker compose exec php bin/console make:entity

# Generate a migration (review the generated file before committing)
docker compose exec php bin/console make:migration

# Apply pending migrations
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction

# Load fixtures (dev only — wipes data first unless --append)
docker compose exec php bin/console doctrine:fixtures:load --append

# Drop + recreate the database (dev only)
docker compose exec php bin/console doctrine:database:drop --force
docker compose exec php bin/console doctrine:database:create

# Inspect the database directly (MariaDB)
docker compose exec php bin/console dbal:run-sql "SELECT id, name FROM role"
# → table `role` (the table name is a SQL keyword in some engines; Doctrine
#   always quotes identifiers, but use it consciously)
```

Entity mapping is by PHP 8 attribute (not XML/YAML). MariaDB uses
`AUTO_INCREMENT` for identity columns by default — no special Doctrine
configuration is required.

---

## Conventions

- **Namespaces:** `App\*` PSR-4 from `src/`. `App\Tests\*` from `tests/`.
- **Doctrine:** attribute mapping. Column names use `naming_strategy:
  doctrine.orm.naming_strategy.underscore` (set in `config/packages/doctrine.yaml`).
- **Symfony Flex:** config files have `###> vendor/pkg ###` … `###<
  vendor/pkg ###` markers that delimit Flex-managed sections. **Preserve them**
  so recipes stay idempotent on reinstall.
- **Twig:** use blocks from `base.html.twig` (`title`, `body`, `stylesheets`,
  `javascripts`) for backoffice pages. For account/profile pages, extend
  `account/base.html.twig`. The backoffice chrome itself extends
  `backoffice/base.html.twig`, which extends `base.html.twig`.
- **Tailwind:** utility classes only. Don't add a CSS file outside
  `assets/styles/app.css`. Extend the theme in `tailwind.config.js`, not with
  custom CSS.
- **Routing:** PHP attributes on controller methods (`#[Route('/path',
  name: '<module>_<verb>')]`). Internal route names use the full module
  word (e.g. `administration_user_list`, `security_login`); URL paths
  use a shorter conventional prefix where appropriate (`/admin/users`,
  `/login`). `config/routes.yaml` is for imports only.
- **Twig imports:** if you need new entry tags, use the `pentatrion_vite`
  functions (`vite_entry_link_tags`, `vite_entry_script_tags`,
  `vite_entry_asset`, `vite_mode`).
- **Env vars:** add new ones to `.env` (committed defaults) and
  `.env.local` (personal overrides, gitignored).
- **Permission names:** UPPER_SNAKE_CASE prefixed with `PERMISSION_`.
  Role names: UPPER_SNAKE_CASE prefixed with `ROLE_`. Both names live in the
  `name` column of their respective tables; uniqueness is enforced
  by a unique index.
- **Translation catalogs:** per-module subfolder
  (`translations/<module>/messages.<locale>.yaml`). The `app` domain is
  reserved for downstream projects with a public homepage.

---

## Design guidance — the project's living rulebook

This is the skeleton's evolving design rulebook. **Add new rules here as
the skeleton's brand matures.** The full aesthetic philosophy is in
`.opencode/skills/frontend-design/SKILL.md` — read it before any UI work.

### Anti-patterns to avoid (from the skill)

- Generic sans-serifs (Inter, Roboto, Arial, system-ui defaults).
- Purple-on-white gradient clichés.
- Cookie-cutter, predictable component shapes and layouts.

### Backoffice visual language

- **Layout**: two-column — fixed `w-64` sidebar on the left, scrollable
  content area on the right. The sidebar lives in
  `templates/backoffice/_sidebar.html.twig` and is shared across every
  backoffice page via `backoffice/base.html.twig`.
- **Sidebar items are permission-gated** via a single config-driven
  macro; see the "Backoffice sidebar nav" subsection below. A
  `ROLE_USER` (view only) sees only Dashboard + Sign out — the
  Administration group collapses away entirely.
- **Palette**: stone (neutral grays) for chrome, amber-600/700 as the
  single accent. No purple, no blue, no gradients. Replace with the
  real brand palette when it's chosen.
- **Typography**: `font-serif` (Georgia) for page titles, default
  sans-serif for everything else. Replace when the brand picks a font pair.
- **Buttons**: `bg-stone-900` for primary, `text-rose-700` for destructive,
  `text-amber-700` for in-row edit links. All in `rounded-md`.
- **User chip**: the sidebar footer shows an initials-avatar (deterministic
  colour from `crc32(email) % palette`, see `UserAvatarExtension`) wrapping
  a name+email link to `/account/profile`. Sign-out is a separate small
  button below the chip. The same partial (`templates/_avatar.html.twig`)
  is reused at `size: 'lg'` on the profile page.

### Backoffice sidebar nav

The sidebar nav is **data-driven from one Twig file**, with a small
renderer macro beside it. Adding a new entity to the sidebar is a
single-row edit.

| File | Role |
|---|---|
| `templates/backoffice/_sidebar.html.twig` | Declares `nav_groups_declared` (pure data — per-group `position`/`label`/`items`, with item fields `label`, `route`, `route_prefix`, `permission`, `icon`). Sorts the declared array by `position` into the rendered `nav_groups`. Owns the outer chrome (logo, footer chip, sign-out). Imports the macros and iterates the groups. |
| `templates/backoffice/_nav_macros.html.twig` | **Renderer.** Three macros — `nav_item`, `nav_group`, `icon_d` (last is private to the renderer; exposes the Heroicons `d=` strings). No data here. |

**Item schema.** `permission` is the `App\Security\Permission::*` constant
*name without the class prefix* (e.g. `'PERMISSION_MANAGE_USERS'`) or
`null` for "always visible". The macro prefixes `App\\Security\\Permission::`
and calls `is_granted(constant(...))`. `icon` is a key into the map
inside the `icon_d` macro; current keys are `dashboard`, `users`,
`shield`, `key`.

**Group schema.** Every group has two optional fields:

- `position` (`'first'` | `'last'` | omitted/null) — render-order anchor.
  - `'first'` → always rendered first (declared order if multiple).
  - `'last'` → always rendered last (declared order if multiple).
  - omitted/null → standalone, rendered in declared order between the
    `'first'` and `'last'` tiers.
- `label` (`string` | `null`) — group heading. `null` means un-grouped
  (no `<h2>`, no rule). Used for Dashboard. Non-null groups render under
  a small uppercase stone-400 heading with a hairline rule.

**Render order (general rule — applies to skeleton and every downstream).**
The sidebar iterates three tiers in this canonical order:

```
position: 'first'  →  standalone (no position)  →  position: 'last'
```

Within each tier, the declared order is preserved. The skeleton's own
groups are pinned: Dashboard declares `position: 'first'`, Administration
declares `position: 'last'`. Downstream projects that add a new module
declare it as standalone (omit `position`) and it slots between the
pinned `'first'` and `'last'` groups automatically — no re-ordering of
the declared array needed. To pin a downstream module of your own, set
its `position` to `'first'` or `'last'` explicitly; only one group per
tier is expected, but the sort tolerates multiple by preserving
declared order within the tier.

**Graceful collapse.** `nav_group` filters its items to the ones the
current user is granted and renders nothing — heading and rule
included — when no items remain. This is how a `ROLE_USER` ends up
seeing no Administration group at all. A pinned group with all items
filtered out (e.g. an admin user who lost all admin permissions) is
still dropped from the rendered output.

**To add a new entity to the sidebar:** append one row to the relevant
group's `items` in `_sidebar.html.twig`. To add a new icon, append
its Heroicons `d=` string to the `icon_d` macro in
`_nav_macros.html.twig` and reference the key from the config.
Backoffice nav items reference `administration_*` route names today;
add a `permission_class` field per item if you need to span modules.

**To add a whole new module to the sidebar:** append a new entry to
`nav_groups_declared` with its own `label` and `items`. Omit `position`
unless you want it pinned (see "Render order" above).

### Technical conventions

- **Tailwind utility-first.** No custom CSS files outside
  `assets/styles/app.css`. If you need a design token, add it to
  `tailwind.config.js` under `theme.extend`.
- **No dark mode configured yet.** Add via `darkMode: 'class'` in
  `tailwind.config.js` if/when needed.
- **No homepage shipped.** Downstream projects add their own.

### Typography

The skeleton uses Georgia (`font-serif`) for page titles and the default
sans-serif for everything else. When a downstream project picks a brand
font pair, both surfaces (backoffice + the project's public homepage)
must move together. The skel-shipped Georgia is a deliberately
un-branded placeholder.

### Color palette

**Backoffice only in the skeleton.** Downstream projects that add a
public homepage register their own brand palette in `tailwind.config.js`
`theme.extend.colors` (e.g. `primary`, `accent`, `background`,
`foreground`). The skeleton's stone + amber is the backoffice baseline
and must not be changed by downstream projects that ship a public
homepage — it would muddy the dichotomy between the two surfaces.

### Iconography

**Library: Heroicons 24/outline** (MIT). No JS dependency, no build step
beyond what Vite already does. Icons are inlined as SVG in the template
that uses them, sized `h-4 w-4` and color-tinted via `stroke="currentColor"`
plus a `text-*` utility. New CRUD entities should use the canonical row
actions in `templates/shared/crud/_row_actions.html.twig` — adding a new
per-row action means either (a) extending the partial or (b) overriding
the `crud_list_actions` block in the entity's `list.html.twig`. Don't
introduce a second icon set.

| Action       | Icon            | Color (Tailwind)  | Used as                                |
|--------------|-----------------|-------------------|----------------------------------------|
| view         | `eye`           | `text-sky-700`    | inline `<a>` to view route             |
| edit         | `pencil-square` | `text-amber-700`  | inline `<a>` to edit route             |
| impersonate  | `users`         | `text-violet-700` | inline `<form>` POST (User only)       |
| delete       | `trash`         | `text-rose-700`   | inline `<form>` POST                   |

The sidebar uses Heroicons (see "Backoffice sidebar nav" above for
the icon map location).

---

## Known landmines

- **Vite 8 requires Node 20.19+.** Host is Node 18.19. Don't bump Vite past 5
  without also upgrading Node.
- **`composer` service runs as `${UID:-1000}:${GID:-1000}`.** Don't try to
  make vendor/ a named volume — that re-creates the root-owned-volume
  problem we already fixed.
- **Flex recipe state can desync** if `composer require` fails mid-install.
  If `bin/console` complains about a class for a package that's clearly
  in `vendor/`, see `INFRASTRUCTURE_PLAN.md` §"Step 2 deviations".
- **Latest `doctrine/doctrine-bundle` and `doctrine-migrations-bundle`
  require PHP 8.4.** The Dockerfile is `php:8.4-apache` for this reason.
  Don't downgrade those packages.
- **`/docker/` and `/apache/` are in the build context.** They MUST NOT be
  in `.dockerignore`. This is the most easily-forgotten rule.
- **MariaDB DSN host differs by environment.** `database:3306` from
  inside containers (Docker service name), `127.0.0.1:33096` from the host
  (mapped via `compose.override.yaml` so a host MariaDB on `:3306` won't
  collide). The DSN in `.env` uses `database` because Symfony only reads
  it from inside the container; from the host use `127.0.0.1:33096`.
- **CSRF gotcha.** Symfony's `SameOriginCsrfTokenManager` is enabled
  by default. The HTML the server renders contains the literal string
  `value="csrf-token"` in the hidden CSRF input — **this is the
  placeholder, not a real token.** Real validation is done via `Origin`
  / `Referer` headers and a cookie double-submit. If you write a curl
  test for a POST, send `-H "Origin: http://localhost:8070" -H
  "Referer: http://localhost:8070/login"`, otherwise the firewall will
  reject the request. A regular browser sends these headers automatically.
- **`User` table is `app_user`, not `user`.** Both PostgreSQL (the
  original target) and MariaDB (`user` is the built-in account-management
  function) treat `user` specially, which means unquoted queries can break
  on either engine. Renaming the entity to use `user` later will break
  unquoted queries. See hard rule #11.
- **Form field `roles` on the User entity is shadowed by `User::getRoles()`.**
  `getRoles()` returns a `string[]` of role names; the underlying property
  is a `Collection<Role>`. Binding a Symfony form to a `roles` field with
  reflection-based getter resolution will read the string array, not the
  collection, and the IdReader will choke. The current `UserType` works
  around this via a `roleEntities` field with `mapped: false` + a
  `POST_SUBMIT` listener that diffs the chosen roles. Follow the same
  pattern for new forms that bind to User's roles.
- **`/account/confirm` link is generated against `APP_URL`.** In `.env.local`
  the dev value is `http://localhost:8070`. If you change the host port
  (e.g. for sci_gestion's `+10` port shift), update `APP_URL` accordingly,
  otherwise the confirmation emails will point at the wrong port.
- **Skeleton identity placeholders** — `Skeleton`, `skeleton.local`,
  `no-reply@skeleton.local`, etc. — must remain in the skeleton's source
  of truth. Downstream projects replace them after forking (see
  `README.md` §"Forking the skeleton"). Do not rename them in the skeleton
  itself.

---

## When the user asks you to "add a feature," default checklist

1. Is it a UI change? Read `.opencode/skills/frontend-design/SKILL.md` and
   the "Design guidance" section above first.
2. Is it a backend change? Identify the controller, entity, or service that
   owns the area. Add the route via `#[Route]` attribute.
3. Does it need a database change? Edit the entity, run `make:migration`,
   review the generated migration, run `migrations:migrate`.
4. Does it need a new Composer package? Use the sidecar, never the host.
5. Does it need a new permission? Edit `App\Security\Permission`, write a
   migration that inserts the new constant, run migrations, assign the
   permission to the relevant roles via the backoffice.
6. Does it add Tailwind classes the build doesn't know about? `npm run
   build` (or rely on `npm run dev` for HMR).
7. **Does it belong in the skeleton or in a downstream?** If the change
   is generic (RBAC, auth, i18n, CRUD base, deploy tooling), it belongs in
   the skeleton. If it's domain-specific (a public homepage, a contact
   form, catalog, inventory, production, sales, HR, accounting), it
   belongs in a downstream. Stop and ask the user if you're unsure.
   In a downstream, if it touches a shared module or a frontend utility,
   save a skeleton proposal in engram (see "Skeleton proposals (engram)"
   above).
8. **Do not commit.** Stop, summarize what you did, and let the user commit.
