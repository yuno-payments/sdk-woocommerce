#!/usr/bin/env node
/**
 * Seed the per-release artifacts from the CHANGELOG.md block:
 *   1. changelog/{version}.json  — the yuno-docs (docs.y.uno) object. Curate it
 *      afterwards (merchant wording, `group`); committed to git and read by the
 *      yuno-docs webhook at the tag. OPTIONAL — omit it for internal releases.
 *   2. readme.txt                — inserts a `= {version} =` block under both
 *      `== Changelog ==` and `== Upgrade Notice ==` (WordPress.org). REQUIRED on
 *      every release; curate the wording (readme is the friendliest surface).
 *
 * Both seeds are starting points — review and polish before committing.
 * Existing entries are never clobbered (re-running is safe).
 *
 * Usage:
 *   node scripts/changelog/generate.js                 # newest released block
 *   node scripts/changelog/generate.js 1.0.2           # a specific version
 *   node scripts/changelog/generate.js --stdout        # print the JSON, write nothing
 *   node scripts/changelog/generate.js 1.0.2 --force   # overwrite an existing JSON
 *   node scripts/changelog/generate.js --no-readme     # skip the readme.txt seed
 */

const fs = require('node:fs')
const path = require('node:path')
const {
  ROOT,
  README,
  readPluginVersion,
  getReleaseBlock,
  toDocsRelease,
  changelogJsonPath,
  readmeVersionsIn,
} = require('./lib')

const args = process.argv.slice(2)
const stdout = args.includes('--stdout')
const force = args.includes('--force')
const noReadme = args.includes('--no-readme')
const versionArg = args.find((a) => !a.startsWith('--'))

const md = fs.readFileSync(path.join(ROOT, 'CHANGELOG.md'), 'utf8')

let targetVersion = versionArg
if (!targetVersion) {
  const pluginVersion = readPluginVersion()
  targetVersion = getReleaseBlock(md, pluginVersion) ? pluginVersion : undefined
}

const block = getReleaseBlock(md, targetVersion)
if (!block) {
  console.error(
    targetVersion
      ? `✖ No CHANGELOG.md block found for version ${targetVersion}.`
      : '✖ No released CHANGELOG.md block found (only [Unreleased]?).'
  )
  process.exit(1)
}
if (block.entries.length === 0) {
  console.error(`✖ CHANGELOG block ${block.version} has no entries.`)
  process.exit(1)
}

const release = toDocsRelease(block)
const json = JSON.stringify(release, null, 2) + '\n'

if (stdout) {
  process.stdout.write(json)
  process.exit(0)
}

// 1. changelog/{version}.json (yuno-docs)
const outFile = changelogJsonPath(release.version)
if (fs.existsSync(outFile) && !force) {
  console.log(
    `• ${path.relative(ROOT, outFile)} already exists — leaving it (use --force to overwrite).`
  )
} else {
  fs.mkdirSync(path.dirname(outFile), { recursive: true })
  fs.writeFileSync(outFile, json)
  console.log(
    `✅ Wrote ${path.relative(ROOT, outFile)} (${release.entries.length} entr` +
      `${release.entries.length === 1 ? 'y' : 'ies'}). Curate it before committing.`
  )
}

// 2. readme.txt (WordPress.org)
if (!noReadme) seedReadme(release.version, block)

function seedReadme(version, block) {
  const readmePath = path.join(ROOT, README)
  let text = fs.readFileSync(readmePath, 'utf8')

  const inChangelog = readmeVersionsIn(text, 'Changelog').has(version)
  const inUpgrade = readmeVersionsIn(text, 'Upgrade Notice').has(version)
  if (inChangelog && inUpgrade) {
    console.log(`• readme.txt already has = ${version} = in both sections — leaving it.`)
    return
  }

  // Changelog block: one `* description.` per entry (friendlier than CHANGELOG).
  const changelogBlock =
    `= ${version} =\n` + block.entries.map((e) => `* ${e.description || e.title}`).join('\n') + '\n\n'
  // Upgrade Notice: a short seed from the first entry — curate it.
  const upgradeBlock = `= ${version} =\n${block.entries[0].description || block.entries[0].title}\n\n`

  if (!inChangelog) text = insertUnderSection(text, 'Changelog', changelogBlock)
  if (!inUpgrade) text = insertUnderSection(text, 'Upgrade Notice', upgradeBlock)

  fs.writeFileSync(readmePath, text)
  console.log(
    `✅ Seeded readme.txt = ${version} = under Changelog${inUpgrade ? '' : ' + Upgrade Notice'}. ` +
      'Polish the wording (readme is the merchant-facing WordPress.org page).'
  )
}

/** Insert `blockText` right after the `== Section ==` header line. */
function insertUnderSection(text, sectionName, blockText) {
  const re = new RegExp(`(^==\\s*${sectionName}\\s*==\\s*$\\n+)`, 'm')
  if (!re.test(text)) {
    console.warn(`⚠ readme.txt has no "== ${sectionName} ==" section — skipped its seed.`)
    return text
  }
  return text.replace(re, `$1${blockText}`)
}
