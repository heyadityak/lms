#!/usr/bin/env bash
# Mirror docs/ (+ repo-root AGENTS.md) into a flat GitHub wiki checkout.
# docs/ is the source of truth; every *.md at the top of <out_dir> is regenerated.
# Usage: scripts/docs-to-wiki.sh <docs_dir> <out_dir>
set -euo pipefail

if [ "$#" -ne 2 ] || [ ! -d "$1" ]; then
    echo "usage: $0 <docs_dir> <out_dir>" >&2
    exit 64
fi
mkdir -p "$2"

exec python3 -I - "$1" "$2" <<'PY'
import os, re, sys

REPO = os.environ.get("WIKI_REPO", "heyadityak/lms")
BRANCH = os.environ.get("WIKI_BRANCH", "main")
BLOB = f"https://github.com/{REPO}/blob/{BRANCH}/"
RAW = f"https://raw.githubusercontent.com/{REPO}/{BRANCH}/"

docs = os.path.abspath(sys.argv[1])
out = os.path.abspath(sys.argv[2])
root = os.path.dirname(docs)
docs_rel = os.path.relpath(docs, root)  # normally "docs"


def page_name(rel):
    """docs-relative path -> wiki page name (Title-Case, dash-joined)."""
    parts = rel[:-3].split("/")
    if parts[-1].upper() == "README" and len(parts) > 1:
        parts = parts[:-1]  # diagrams/README.md -> Diagrams
    words = [w for p in parts for w in re.split(r"[_\-\s]+", p) if w]
    return "-".join(w[:1].upper() + w[1:].lower() for w in words)


# repo-relative source path -> page name
pages = {}
for d, dirs, files in os.walk(docs):
    dirs[:] = sorted(x for x in dirs if not x.startswith("."))
    for f in sorted(files):
        if f.endswith(".md"):
            rel = os.path.relpath(os.path.join(d, f), docs)
            pages[os.path.join(docs_rel, rel)] = page_name(rel)
if os.path.isfile(os.path.join(root, "AGENTS.md")):
    pages["AGENTS.md"] = "Agents"

reserved = {"Home", "_Sidebar", "_Footer"}
names = list(pages.values())
dupes = {n for n in names if names.count(n) > 1} | (set(names) & reserved)
if dupes:
    sys.exit(f"docs-to-wiki: page name collision: {sorted(dupes)}")

LINK = re.compile(r'(!?)\[([^\]]*)\]\(<?([^)\s>]+)>?(\s+"[^"]*")?\)')
FENCE = re.compile(r"^\s*(```|~~~)")


def rewrite(src, text):
    base = os.path.dirname(src)

    def sub(m):
        bang, label, target, title = m.group(1), m.group(2), m.group(3), m.group(4) or ""
        if re.match(r"^[a-z][a-z0-9+.-]*:|^#|^/", target, re.I):
            return m.group(0)  # absolute URL, mailto:, in-page anchor
        path, _, anchor = target.partition("#")
        path = os.path.normpath(os.path.join(base, path))
        if path.startswith(".."):
            return m.group(0)  # points outside the repo; leave as-is
        frag = "#" + anchor if anchor else ""
        if not bang and path in pages:
            return f"[{label}]({pages[path]}{frag})"
        url = (RAW if bang else BLOB) + path + frag
        return f"{bang}[{label}]({url}{title})"

    lines, fenced = [], None
    for line in text.splitlines(keepends=True):
        m = FENCE.match(line)
        if fenced:
            if m and m.group(1) == fenced:
                fenced = None
        elif m:
            fenced = m.group(1)
        else:
            line = LINK.sub(sub, line)
        lines.append(line)
    return "".join(lines)


def title_of(src, name):
    with open(os.path.join(root, src), encoding="utf-8") as fh:
        for line in fh:
            if line.startswith("# "):
                return line[2:].strip()
    return name.replace("-", " ")


# Idempotent: drop every top-level page, then regenerate (.git and non-md files untouched).
for f in os.listdir(out):
    if f.endswith(".md") and os.path.isfile(os.path.join(out, f)):
        os.remove(os.path.join(out, f))


def write(name, body):
    with open(os.path.join(out, name + ".md"), "w", encoding="utf-8") as fh:
        fh.write(body if body.endswith("\n") else body + "\n")


for src, name in pages.items():
    with open(os.path.join(root, src), encoding="utf-8") as fh:
        write(name, rewrite(src, fh.read()))

GROUPS = ["Overview", "Design", "API", "Diagrams", "Agents/Process"]


def group(src):
    if src == "AGENTS.md":
        return "Agents/Process"
    rel = os.path.relpath(src, docs_rel)
    if rel.startswith("diagrams/"):
        return "Diagrams"
    if "API" in rel.upper().split("/")[0] or rel.startswith("api/"):
        return "API"
    if rel in ("README.md", "USE_CASES.md"):
        return "Overview"
    return "Design"


grouped = {g: [] for g in GROUPS}
for src, name in sorted(pages.items(), key=lambda kv: kv[1]):
    grouped[group(src)].append((name, title_of(src, name)))
grouped["Overview"].insert(0, ("Home", "Home"))
grouped["API"].append((f"{BLOB}{docs_rel}/api/openapi.yaml", "OpenAPI spec (openapi.yaml)"))

sidebar = ["**[Home](Home)**", ""]
for g in GROUPS:
    if not grouped[g]:
        continue
    sidebar.append(f"**{g}**")
    sidebar.append("")
    for name, title in grouped[g]:
        if name == "Home":
            continue
        sidebar.append(f"- [{title}]({name})")
    sidebar.append("")
write("_Sidebar", "\n".join(sidebar))

home = [
    "# Leave Management System",
    "",
    "A white-label, single-tenant Leave Management System (Laravel 12, PostgreSQL, Redis, Keycloak).",
    "Employees apply for leave, managers approve or reject, and HR admins manage users, leave types,",
    "holidays, branding and modules. Balances live in an append-only ledger.",
    "",
    f"Source code: https://github.com/{REPO}",
    "",
    "## Pages",
    "",
]
for g in GROUPS:
    entries = [(n, t) for n, t in grouped[g] if n != "Home"]
    if not entries:
        continue
    home += [f"### {g}", ""]
    home += [f"- [{t}]({n})" for n, t in entries]
    home.append("")
write("Home", "\n".join(home))

write("_Footer", f"Generated from docs/ — edit there, not here: {BLOB.replace('/blob/', '/tree/')}{docs_rel}")
print(f"docs-to-wiki: wrote {len(pages) + 3} pages to {out}")
PY
