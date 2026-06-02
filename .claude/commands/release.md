You are a release assistant for the **Yuno WooCommerce plugin** (`sdk-woocommerce`).
You drive a version bump so the changelog reaches BOTH public surfaces:

- **WordPress.org** (https://wordpress.org/plugins/yuno-payment-gateway) — via
  `readme.txt` + the SVN release. **Required for every version** (it's what
  merchants see; they update manually).
- **docs.y.uno** (https://docs.y.uno/changelog/plugins/woocommerce) — via a
  GitHub **Release** that triggers `sdk-web-demo`'s webhook, which opens a PR in
  `yuno-payments/yuno-docs` (`changelog/source/woocommerce.json`). **Optional** —
  only for merchant-facing releases (curated `changelog/{version}.json`).

The plugin version lives in **five** files that must all match `X.Y.Z`:
1. `yuno-payment-gateway/package.json` → `"version"` (canonical; the yuno-docs webhook reads this)
2. `package.json` (repo root) → `"version"`
3. `yuno-payment-gateway/readme.txt` → `Stable tag:`
4. `yuno-payment-gateway/yuno-payment-gateway.php` → `* Version:` header
5. `yuno-payment-gateway/yuno-payment-gateway.php` → `YUNO_WC_VERSION` constant

The release gate (`npm run changelog:check`) fails the PR if these are out of
sync, or if `CHANGELOG.md` / `readme.txt` don't have the version. The Git tag is
`X.Y.Z` (no `v` prefix — WordPress/SVN convention).

Follow these steps exactly.

## Step 1 — Choose the version

Read the current version from `yuno-payment-gateway/package.json`. Suggest a
**patch** bump by default; minor/major only for new capabilities or breaking
changes. Validate semver and that it's greater. Store as `{VERSION}`.

Decide now whether this release is **merchant-facing** (goes to docs.y.uno) or
**internal-only** (WordPress.org only — dep bumps, refactors, security). This
controls whether you create `changelog/{VERSION}.json` and a GitHub Release.

## Step 2 — Bump all five version surfaces

Set every surface above to `{VERSION}`. Change nothing else in those files.

## Step 3 — Update CHANGELOG.md

Move the `## [Unreleased]` items into a new `## [{VERSION}] - {TODAY}` block
(today, `YYYY-MM-DD`). Keep a Changelog 1.1.0, standard sections only, bullets
`- **Title** — Description.`. Obey the **Changelog Discipline** in CLAUDE.md
(English; no ticket IDs; no placeholders; title ≠ description; no source-code
references — merchant/TAM audience).

## Step 4 — Seed the public artifacts

Run: `npm run changelog:generate`. It seeds:
- `changelog/{VERSION}.json` (yuno-docs) — **curate it**: assign `group`,
  consolidate/drop non-merchant entries, polish wording. **If this release is
  internal-only, delete this file** (no docs.y.uno entry).
- `readme.txt` `= {VERSION} =` under `== Changelog ==` and `== Upgrade Notice ==`
  — **curate both**: `readme.txt` is the friendliest, merchant-facing surface;
  simplify the wording and write a clear, short Upgrade Notice. Required even for
  internal releases.

## Step 5 — Validate

Run `npm run changelog:check`. It must pass (5-surface sync + CHANGELOG +
readme + valid `changelog/{VERSION}.json` if present). Fix and re-run until green.

## Step 6 — Commit and open the PR

Stage the five version files, `CHANGELOG.md`, `readme.txt`, the actual code
changes, and `changelog/{VERSION}.json` (if merchant-facing). Commit
(`chore(release): {VERSION}`), push, open a PR to **`master`**. Drone runs the gate.

## Step 7 — After merge: publish to BOTH surfaces

Once merged to master (`git checkout master && git pull`):

**A. WordPress.org (always).** Follow the "WordPress.org SVN Release" process in
CLAUDE.md: build, sync to SVN trunk, register files, then the user commits and
tags `X.Y.Z` via SVN (interactive password). This is what actually ships the new
version to merchants.

**B. docs.y.uno (merchant-facing only).** Create the GitHub Release on tag
`{VERSION}` (no `v`):
```
git tag {VERSION} && git push origin {VERSION}
gh release create {VERSION} --title "{VERSION}" --notes-file <(node scripts/changelog/generate.js {VERSION} --stdout)
```
This fires the webhook → a PR in `yuno-docs` → a message in Slack `#yuno-docs`.
Have the docs team review/merge it. **For internal-only releases, skip B
entirely** (no `changelog/{VERSION}.json`, no GitHub Release → nothing reaches
docs.y.uno).

## Notes

- Everything here is runnable by hand; this command orchestrates the same
  `npm run changelog:generate` / `changelog:check` scripts.
- A GitHub Release with no `changelog/{VERSION}.json` is handled safely by the
  endpoint (skipped, not published) — but for internal releases prefer not to
  create a GitHub Release at all.
