# 2026-11-19 — platform — docs → GitHub wiki mirror

## Goal
Publish `docs/` (+ `AGENTS.md`) to the `heyadityak/lms` GitHub wiki as a generated mirror; `docs/` stays the source of truth. The rest of the build stays paused, so this run made no Dockerfile, compose or app changes.

## What I did (files)
- `scripts/docs-to-wiki.sh` (new): usage `scripts/docs-to-wiki.sh <docs_dir> <out_dir>`. Bash wrapper with embedded Python 3 (stdlib, `-I`). Page names: `SYSTEM_DESIGN.md`→`System-Design`, `diagrams/03-class-diagram.md`→`Diagrams-03-Class-Diagram`, `<dir>/README.md`→`<Dir>`, `AGENTS.md`→`Agents`. It fails if two page names collide. Rewrites links outside fenced code blocks. Generates `Home.md`, `_Sidebar.md` (Overview / Design / API / Diagrams / Agents/Process) and `_Footer.md`. Every top-level `*.md` in out_dir is deleted and then regenerated, so stale pages disappear; `.git` and non-md files are not touched. `WIKI_REPO`/`WIKI_BRANCH` env vars override `heyadityak/lms`/`main`.
- `.github/workflows/wiki.yml` (new): push to `main` (paths `docs/**`, `AGENTS.md`, the script, the workflow) + `workflow_dispatch`; `contents: write`; `concurrency: wiki-sync` (not cancelled); clones the wiki with `GITHUB_TOKEN`; commits only if `git diff --cached` is non-empty; `git push origin HEAD` (wiki default branch is `master`).
- `AGENTS.md` §10: one-line "Wiki" note (`docs/README.md` does not exist).
- `.logs/decisions.md`: D-28.

## Commands run + results
- Cloned the real wiki (read-only) into the scratchpad `wiki-out`: branch `master`, contains placeholder `Home.md`.
- Ran the script twice into it with a planted `Old-Page.md`: 12 pages written, the stale page was removed, no leftover relative `](*.md` links, every `](Page-Name)` target exists, all sidebar entries resolve, and mermaid blocks are byte-identical to the source.
- Idempotency: on the live tree one page differed between runs because the architect edited `docs/diagrams/01-system-design.md` in between. Against a frozen copy of docs, two runs gave identical hashes.
- `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -no-color`: clean.

## Decisions
- D-28 (wiki mirror).

## Bugs found / open issues
- `docs/SYSTEM_DESIGN.md` links `diagrams/README.md`, which does not exist yet; it is currently rewritten to a GitHub blob URL (404). Once the architect adds that file, it becomes the `Diagrams` wiki page automatically.
- Links to `.logs/decisions.md` point to the GitHub blob on `main`, so they 404 until `.logs/` is pushed.
- The wiki checkout must allow pushes from `GITHUB_TOKEN`. If the repo restricts wiki editing to collaborators, that is still fine for Actions with `contents: write`.

## Next steps
- Orchestrator: commit `scripts/docs-to-wiki.sh`, `.github/workflows/wiki.yml`, `AGENTS.md`, `.logs/*`, then do the first publish (push to `main` or run `workflow_dispatch`).
