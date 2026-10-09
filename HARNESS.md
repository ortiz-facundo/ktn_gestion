# HARNESS.md — Planner/Builder Dev Harness

> How the two-agent Herdr workflow (planner + builder) operates in this
> repo. Read `AGENTS.md` first — this file assumes it and does not repeat
> its rules.

---

## Layout

This repo's Herdr workspace has a tab with a 50/50 left/right split, both
panes cwd'd into this repo:

- **Left — `ktn-planner`** (Claude Code)
- **Right — `ktn-builder`** (opencode)

The names are project-scoped on purpose: agent names in Herdr must be
unique session-wide, not just per-project, and multiple `claude`/`opencode`
agents typically run concurrently across other projects on this machine.
Address panes by these names, not by kind (`claude`/`opencode`).

### Known issue: keep the builder pane wide (≥ ~50 columns)

Launching plain `opencode` (its full TUI) in a **narrow** Herdr pane
(observed: crashes at 20-27 columns) crashes outright with a Bun SIGILL
(`Illegal instruction (core dumped)`, `bun.report/...`). This lines up
with an open upstream bug — [opencode
#41483](https://github.com/anomalyco/opencode/issues/41483): opencode's
TUI sends a 7-query terminal-capability handshake at startup, and
terminals that answer only *some* of those queries (Herdr does this)
crash the process. Whatever the exact mechanism, in practice this repo's
harness pane has been stable at 45-56 columns and crashed consistently
below ~27, across a fresh pane, an unrelated project, and repeated
restarts — so it's not a stale-session or project-specific issue.

**Keep each split pane at roughly 50+ columns** (i.e. don't squeeze the
Herdr window too narrow) and launch the builder normally:

```bash
herdr agent start ktn-builder --kind opencode --pane <pane-id>
```

If the builder pane crashes right after starting, first check its actual
width (`herdr pane layout --pane <pane-id>`) before assuming something
else is wrong — widening the terminal has resolved it every time so far.
Two narrower-pane fallbacks exist if you ever can't widen the terminal:
`opencode run "<task>" --continue --thinking` (one-shot calls instead of
a persistent TUI — verified to never hit this crash regardless of width,
but loses Herdr's native `idle`/`working`/`blocked` agent tracking), or
wrapping `opencode` in `tmux` (untested for real in this repo).

Claude Code only auto-loads `CLAUDE.md`, not `AGENTS.md`, and only once at
session start. This repo's root `CLAUDE.md` imports both `AGENTS.md` and
this file (`@AGENTS.md` / `@HARNESS.md`), so any **new** planner session
picks them up automatically — but a session already running when either
file changes will not see the update until it's restarted.

---

## Enforcement (hard block, not just advisory docs)

The responsibilities below are advisory on their own — nothing stops a
normal Claude Code session from just editing files itself, doc or no doc.
To make the planner actually unable to do that, launch it with the extra
settings file in this repo, which wires in a `PreToolUse` hook
(`.claude/hooks/planner-guard.sh`) that blocks `Edit`/`Write`/`NotebookEdit`
outright and restricts `Bash` to read-only inspection plus `herdr agent
...`, returning an explanatory message so the model self-corrects to
delegating instead of silently failing:

```bash
herdr agent rename w4:p1 ktn-planner   # if not already renamed
claude --settings .claude/planner.settings.json --resume <session-id>
```

`.claude/planner.settings.json` is deliberately **not** named
`settings.json`/`settings.local.json` — Claude Code does not auto-load it,
so it only applies to a session explicitly launched with `--settings`.
Other Claude Code sessions opened in this repo (ad hoc solo work, not the
harness) are unaffected. Use `--resume <session-id>` to keep the existing
conversation's history when restarting a live planner pane; omit it to
start fresh.

The hook's `Bash` allowlist is intentionally narrow (`herdr *`, `git
status`, `git diff`, `git log`, `git show`, and basic read-only shell
commands). Widen `.claude/hooks/planner-guard.sh` if the planner
legitimately needs another read-only command.

---

## Planner responsibilities (left pane, Claude Code)

Do:

- Read `AGENTS.md`'s "Mandatory reading" list and any relevant code before
  acting.
- Break the user's request into concrete, scoped tasks.
- Write the task description handed to the builder — specific enough to
  implement without guessing: files, entities, routes, expected behavior,
  an acceptance check.
- Decide skeleton-vs-downstream scope per `AGENTS.md`'s closing "add a
  feature" checklist. If unsure, stop and ask the human — not the builder.
- Review the builder's diff and any command output (tests, build,
  migrations) after each handoff.
- Be the sole point of contact with the human: ask clarifying questions,
  report status, and give the final summary once builder work is reviewed.
- Own reacting to a blocked/approval-seeking builder (see Handoff recipe).

Do not:

- Edit application code directly.
- Run `docker compose`, `composer`, `npm`, or `git` write commands as part
  of implementing a task. Read-only inspection (`git status`, `git diff`,
  `cat`) is fine for review.
- Commit, push, or otherwise touch git history — inherited from
  `AGENTS.md`'s hard rules, not restated here.

## Builder responsibilities (right pane, opencode)

Do:

- Implement exactly the task handed to it by the planner — no scope
  expansion.
- Follow every hard rule in `AGENTS.md` by reference: composer only via
  the tools-profile sidecar, npm on the host (never inside the php
  container), never edit `vendor/`/`var/`/`node_modules/`/`public/build/`,
  permission strings only in `App\Security\Permission`, etc.
- Run the actual build/test/migration commands needed to implement and
  verify the task (`docker compose exec php bin/console ...`,
  `docker compose --profile tools run --rm composer ...`, `npm run build`,
  per `AGENTS.md`'s "Build & run" section).
- Report back to the planner what changed and the result of any
  verification commands it ran.

Do not:

- Decide whether something belongs in the skeleton vs. a downstream —
  that's the planner's call, escalated to the human.
- Invent requirements not present in the task the planner gave it. If the
  task is ambiguous or needs a decision outside its scope, say so back to
  the planner instead of guessing.
- Commit, push, or amend git history — same inherited rule.

---

## Handoff recipe (planner → builder)

Any pane running under Herdr already has the `herdr` CLI in `PATH`. The
planner hands off work like this:

```bash
# 1. Hand off a task and block until the builder settles
herdr agent prompt ktn-builder "<concrete task description>" --wait --timeout 120000

# 2. Read what the builder actually did/said
herdr agent read ktn-builder --source recent-unwrapped --lines 200

# 3. If it looks stuck, confirm state explicitly rather than guessing
#    from --wait timing out:
herdr agent wait ktn-builder --until blocked --timeout 30000
```

If the builder is `blocked` on an approval or confirmation prompt: the
planner inspects what it's asking via `agent read`, then surfaces that to
the human and waits for an answer. The planner never auto-approves or
sends a blind confirmation via `agent send-keys`.

Reverse handoff (builder → planner, e.g. the builder hits a scope
question) uses the same pattern with `ktn-planner` as the target,
run from the builder's own pane.

`--timeout` values above are starting points — size them to the task. A
migration plus a test run needs longer than a one-line edit.

---

## Git/commit boundary

Both agents are bound by `AGENTS.md`'s "never commit, never push, never
amend" rule — not restated here. To avoid the human-facing summary being
done twice or dropped: the **builder** reports raw results (diff summary,
command output) to the planner; the **planner** alone produces the single
final summary to the human and says explicitly "ready for you to
review/commit."

---

## Known landmines (Herdr specifics)

- Agent names must be unique **session-wide**, not just per-project. A
  generic `planner`/`builder` pair will collide with another project's
  harness running at the same time — that's why these are named
  `ktn-planner`/`ktn-builder`.
- `herdr agent prompt` rejects a send into an agent that's currently
  `blocked`. Check `herdr agent get <target>` first, or wait for `idle`.
- If the builder ever launches a full-screen/alternate-screen TUI (an
  interactive migration tool, `psql`, a pager), normal `agent read`
  capture may not reflect it faithfully. Fall back to redirecting the
  command's output to a file and reading the file directly.

---

## Downstream sync

Changes made through this harness are ordinary skeleton commits once the
human commits them — they still flow through `BRANCHING.md`'s human-gated,
tag-bounded cherry-pick to `web-nova-argentum` and `sci_gestion`. This
harness does not change or automate that process.
