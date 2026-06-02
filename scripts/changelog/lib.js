/**
 * Shared helpers for the changelog → docs pipeline (WooCommerce plugin).
 *
 * This repo ships to TWO public surfaces per release:
 *   - docs.y.uno (yuno-docs/changelog/source/woocommerce.json) via the GitHub
 *     Release webhook — curated, merchant-facing, OPTIONAL (internal releases
 *     skip it).
 *   - wordpress.org/plugins/yuno-payment-gateway via readme.txt + the SVN
 *     release — REQUIRED for every version (WordPress shows it to merchants,
 *     who update manually).
 *
 * The plugin version lives in FIVE places that must always agree:
 *   - yuno-payment-gateway/package.json            "version"  (canonical; the
 *                                                   one the yuno-docs webhook reads)
 *   - package.json (repo root)                     "version"
 *   - yuno-payment-gateway/readme.txt              "Stable tag:"
 *   - yuno-payment-gateway/yuno-payment-gateway.php  "* Version:" header
 *   - yuno-payment-gateway/yuno-payment-gateway.php  YUNO_WC_VERSION constant
 *
 * No third-party dependencies on purpose: these scripts must run from a bare
 * terminal and inside CI without `npm install`.
 */

const fs = require('node:fs')
const path = require('node:path')

const ROOT = path.resolve(__dirname, '..', '..')

const PLUGIN_PKG = 'yuno-payment-gateway/package.json'
const ROOT_PKG = 'package.json'
const README = 'yuno-payment-gateway/readme.txt'
const PHP = 'yuno-payment-gateway/yuno-payment-gateway.php'

// CHANGELOG.md section heading → docs schema type.
const SECTION_TO_TYPE = {
  Added: 'ADDED',
  Changed: 'CHANGED',
  Deprecated: 'DEPRECATED',
  Removed: 'REMOVED',
  Fixed: 'FIXED',
  Security: 'SECURITY',
}

const VALID_TYPES = new Set([...Object.values(SECTION_TO_TYPE), 'BREAKING'])

const VERSION_RE = /^\d+\.\d+\.\d+$/

function read(rel) {
  return fs.readFileSync(path.join(ROOT, rel), 'utf8')
}

/** The canonical plugin version (what the yuno-docs webhook reads at the tag). */
function readPluginVersion(root = ROOT) {
  return JSON.parse(fs.readFileSync(path.join(root, PLUGIN_PKG), 'utf8')).version
}

/**
 * All five version surfaces, as { surface label → version|null }. null means
 * the surface was present but the version couldn't be parsed.
 */
function readVersionSurfaces() {
  const pluginPkg = JSON.parse(read(PLUGIN_PKG)).version || null
  const rootPkg = JSON.parse(read(ROOT_PKG)).version || null
  const readme = read(README)
  const php = read(PHP)
  const stableTag = (readme.match(/^Stable tag:\s*(\S+)/m) || [])[1] || null
  const phpHeader = (php.match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1] || null
  const phpConst =
    (php.match(/define\(\s*['"]YUNO_WC_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)/) || [])[1] || null
  return {
    'yuno-payment-gateway/package.json': pluginPkg,
    'package.json (root)': rootPkg,
    'readme.txt (Stable tag)': stableTag,
    'yuno-payment-gateway.php (Version header)': phpHeader,
    'yuno-payment-gateway.php (YUNO_WC_VERSION)': phpConst,
  }
}

/** Numeric semver compare for the X.Y.Z scheme this repo uses. */
function compareVersions(a, b) {
  const pa = String(a).replace(/^v/, '').split('.').map(Number)
  const pb = String(b).replace(/^v/, '').split('.').map(Number)
  for (let i = 0; i < 3; i++) {
    const d = (pa[i] || 0) - (pb[i] || 0)
    if (d !== 0) return d
  }
  return 0
}

/**
 * Parse a CHANGELOG bullet `- **Title** — Description.`. The separator is an
 * em-dash with surrounding spaces; descriptions may contain em-dashes, so we
 * split on the FIRST ` — ` after the bold title only.
 */
function parseBullet(line) {
  const m = line.match(/^-\s+\*\*(.+?)\*\*\s*—\s*(.+?)\s*$/)
  if (m) return { title: m[1].trim(), description: m[2].trim() }
  const titleOnly = line.match(/^-\s+\*\*(.+?)\*\*\s*$/)
  if (titleOnly) return { title: titleOnly[1].trim(), description: '' }
  const plain = line.match(/^-\s+(.+?)\s*$/)
  if (plain) return { title: plain[1].trim(), description: '', malformed: true }
  return null
}

/** Parse CHANGELOG.md released blocks (skips `[Unreleased]`), newest first. */
function parseChangelog(md) {
  const lines = md.split('\n')
  const blocks = []
  let current = null
  let currentType = null

  for (const raw of lines) {
    const line = raw.replace(/\s+$/, '')

    const released = line.match(/^##\s+\[(\d+\.\d+\.\d+)\]\s+-\s+(\d{4}-\d{2}-\d{2})\s*$/)
    if (released) {
      current = { version: released[1], date: released[2], entries: [] }
      blocks.push(current)
      currentType = null
      continue
    }
    if (/^##\s+/.test(line)) {
      current = null
      currentType = null
      continue
    }
    if (!current) continue

    const section = line.match(/^###\s+(.+?)\s*$/)
    if (section) {
      currentType = SECTION_TO_TYPE[section[1].trim()] || section[1].trim().toUpperCase()
      continue
    }

    if (line.startsWith('- ') && currentType) {
      const bullet = parseBullet(line)
      if (bullet) current.entries.push({ type: currentType, ...bullet })
    }
  }

  return blocks
}

/** Find a specific released block, or the newest one when version is omitted. */
function getReleaseBlock(md, version) {
  const blocks = parseChangelog(md)
  if (version) return blocks.find((b) => b.version === version) || null
  return blocks[0] || null
}

/** Build the merchant-facing docs object (yuno-docs) from a CHANGELOG block. */
function toDocsRelease(block) {
  return {
    version: block.version,
    release_date: block.date,
    upgrade: {
      snippet: `wp plugin install yuno-payment-gateway --version=${block.version} --activate`,
      language: 'bash',
    },
    entries: block.entries.map((e) => ({
      type: VALID_TYPES.has(e.type) ? e.type : 'CHANGED',
      title: e.title,
      description: e.description,
      group: null,
      migration_guide: null,
      links: [],
    })),
  }
}

function changelogJsonPath(version, root = ROOT) {
  return path.join(root, 'changelog', `${version}.json`)
}

// ---- readme.txt (WordPress.org) helpers ----

/** Return the raw text of a `== Section ==` block (until the next `== … ==`). */
function readmeSection(readme, sectionName) {
  const re = new RegExp(`^==\\s*${sectionName}\\s*==\\s*$`, 'm')
  const start = readme.search(re)
  if (start < 0) return null
  const after = readme.slice(start)
  const nextHeader = after.slice(1).search(/^==\s*.+?\s*==\s*$/m)
  return nextHeader < 0 ? after : after.slice(0, nextHeader + 1)
}

/** Versions (`= X.Y.Z =`) present inside a readme section. */
function readmeVersionsIn(readme, sectionName) {
  const section = readmeSection(readme, sectionName)
  if (!section) return new Set()
  const versions = new Set()
  for (const m of section.matchAll(/^=\s*(\d+\.\d+\.\d+)\s*=\s*$/gm)) versions.add(m[1])
  return versions
}

module.exports = {
  ROOT,
  PLUGIN_PKG,
  ROOT_PKG,
  README,
  PHP,
  SECTION_TO_TYPE,
  VALID_TYPES,
  VERSION_RE,
  read,
  readPluginVersion,
  readVersionSurfaces,
  compareVersions,
  parseBullet,
  parseChangelog,
  getReleaseBlock,
  toDocsRelease,
  changelogJsonPath,
  readmeSection,
  readmeVersionsIn,
}
