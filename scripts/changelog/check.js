#!/usr/bin/env node
/**
 * Release gate. Runs on every PR to master (CI) and can be run locally.
 *
 * Git-free coherence invariant for the CURRENT plugin version (read from
 * yuno-payment-gateway/package.json):
 *
 *   1. The version is valid semver and IDENTICAL across all five surfaces:
 *      plugin package.json, root package.json, readme.txt Stable tag, the .php
 *      "Version:" header, and the YUNO_WC_VERSION constant.
 *   2. CHANGELOG.md's newest released block is that version, with >=1 entry,
 *      each `- **Title** — Description.` (a title AND a non-empty description).
 *   3. readme.txt (WordPress.org) has a `= {version} =` block under BOTH
 *      `== Changelog ==` and `== Upgrade Notice ==`. ALWAYS required — every
 *      version is published to WordPress.org and shown to merchants.
 *   4. changelog/{version}.json (yuno-docs) is OPTIONAL:
 *        - Absent  ⇒ internal release: not published to docs.y.uno (no GitHub
 *          Release should be created for it).
 *        - Present ⇒ strictly validated (version match, upgrade.snippet, and a
 *          non-empty entries array with valid type / title / description).
 *
 * Git-free because the CI container has no git: it validates the current
 * snapshot rather than diffing against the base branch.
 *
 * Exit code 0 = pass, 1 = fail.
 */

const fs = require('node:fs')
const path = require('node:path')
const {
  ROOT,
  README,
  VALID_TYPES,
  VERSION_RE,
  read,
  readPluginVersion,
  readVersionSurfaces,
  getReleaseBlock,
  changelogJsonPath,
  readmeVersionsIn,
} = require('./lib')

const errors = []
const fail = (msg) => errors.push(msg)

const version = readPluginVersion()

// 1. Valid semver + all five surfaces in sync.
if (!VERSION_RE.test(version)) {
  fail(`yuno-payment-gateway/package.json version "${version}" is not valid semver (X.Y.Z).`)
}
const surfaces = readVersionSurfaces()
for (const [label, v] of Object.entries(surfaces)) {
  if (v !== version) {
    fail(`Version mismatch: ${label} is "${v ?? '(unreadable)'}", expected "${version}".`)
  }
}

// 2. CHANGELOG.md newest released block matches the version.
const md = read('CHANGELOG.md')
const top = getReleaseBlock(md)
if (!top) {
  fail('CHANGELOG.md has no released version block.')
} else if (top.version !== version) {
  fail(
    `CHANGELOG.md newest released block is ${top.version} but the plugin version is ` +
      `${version}. Move [Unreleased] into a \`## [${version}] - YYYY-MM-DD\` block.`
  )
} else if (top.entries.length === 0) {
  fail(`CHANGELOG.md block ${version} has no entries.`)
} else {
  const bad = top.entries.filter((e) => e.malformed || !e.description)
  if (bad.length) {
    fail(
      `CHANGELOG.md block ${version} has ${bad.length} bullet(s) not in ` +
        '`- **Title** — Description.` format (a title and a non-empty description are both required).'
    )
  }
}

// 3. readme.txt — ALWAYS required, both sections.
const readme = read(README)
if (!readmeVersionsIn(readme, 'Changelog').has(version)) {
  fail(`readme.txt is missing a \`= ${version} =\` block under \`== Changelog ==\`.`)
}
if (!readmeVersionsIn(readme, 'Upgrade Notice').has(version)) {
  fail(`readme.txt is missing a \`= ${version} =\` block under \`== Upgrade Notice ==\`.`)
}

// 4. changelog/{version}.json — OPTIONAL (absent = internal release), strict when present.
const jsonPath = changelogJsonPath(version)
const rel = path.relative(ROOT, jsonPath)
if (!fs.existsSync(jsonPath)) {
  console.log(
    `ℹ changelog:check — no ${rel}; treating ${version} as an internal release ` +
      '(published to WordPress.org via readme.txt, but NOT to docs.y.uno). ' +
      'Do not create a GitHub Release for it.'
  )
} else {
  let doc
  try {
    doc = JSON.parse(fs.readFileSync(jsonPath, 'utf8'))
  } catch (e) {
    fail(`${rel} is not valid JSON: ${e.message}`)
  }
  if (doc) {
    if (doc.version !== version) fail(`${rel} .version is "${doc.version}", expected "${version}".`)
    if (!doc.upgrade || !doc.upgrade.snippet) fail(`${rel} is missing upgrade.snippet.`)
    if (!Array.isArray(doc.entries) || doc.entries.length === 0) {
      fail(`${rel} must have a non-empty "entries" array.`)
    } else {
      doc.entries.forEach((e, i) => {
        if (!e || typeof e !== 'object') return fail(`${rel} entries[${i}] is not an object.`)
        if (!VALID_TYPES.has(e.type)) {
          fail(`${rel} entries[${i}].type "${e.type}" is invalid (${[...VALID_TYPES].join(', ')}).`)
        }
        if (!e.title || typeof e.title !== 'string') {
          fail(`${rel} entries[${i}].title is missing or not a string.`)
        }
        if (!e.description || typeof e.description !== 'string') {
          fail(`${rel} entries[${i}].description must be a non-empty string.`)
        }
      })
    }
  }
}

if (errors.length) {
  console.error(`\n✖ changelog:check failed for version ${version}:`)
  for (const e of errors) console.error(`  • ${e}`)
  console.error('\nSee the release flow in CLAUDE.md / .claude/commands/release.md.')
  process.exit(1)
}

console.log(`✅ changelog:check passed for ${version}.`)
