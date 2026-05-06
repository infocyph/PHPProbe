# PHPProbe

[![Security & Standards](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml/badge.svg)](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/PHPProbe?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FPHPProbe)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/PHPProbe)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/PHPProbe/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/PHPProbe)

Standalone PHP checker for syntax validation, duplicate-code detection, public API snapshot checks and comment policy checks.

PHPProbe is the checker runtime. It can be used directly as `phpprobe`, required by tool-combiner packages such as PHPForge, or called from PHP code through the public gateway classes.

## Requirements

- PHP `>=8.2`
- `nikic/php-parser` `>=5.0 <6.0`

Install it as a Composer tool dependency:

```bash
composer require --dev infocyph/phpprobe
```

The package ships a Composer binary:

```bash
php vendor/bin/phpprobe
```

## Commands

```bash
php vendor/bin/phpprobe syntax [options] [paths...]
php vendor/bin/phpprobe duplicates [options] [paths...]
php vendor/bin/phpprobe api [options] [paths...]
php vendor/bin/phpprobe comments [options] [paths...]
php vendor/bin/phpprobe check [options] [paths...]
php vendor/bin/phpprobe config validate [options]
php vendor/bin/phpprobe init [options]
php vendor/bin/phpprobe presets
php vendor/bin/phpprobe preset <name>
```

Unknown commands print the top-level usage and exit `0`. There is no separate `--version` command.
For checker subcommands (`syntax`, `duplicates`, `api`, `comments`, `check`), unknown options fail with exit `2`.

## Onboarding (5 Minutes)

Use this flow for a fresh project:

1. Initialize config:

```bash
php vendor/bin/phpprobe init --preset=standard
```

2. Run all 4 tools together:

```bash
php vendor/bin/phpprobe check src tests
```

3. Add CI reports (optional):

```bash
php vendor/bin/phpprobe check --preset=standard --report-dir=build/reports src tests
```

4. If your project is a library, start API baseline flow:

```bash
php vendor/bin/phpprobe api --write-baseline=.phpprobe-api-baseline.json src
php vendor/bin/phpprobe api --baseline=.phpprobe-api-baseline.json src
```

## Tool Map

| Tool | Purpose | Typical command |
| --- | --- | --- |
| `syntax` | PHP parse/lint errors | `php vendor/bin/phpprobe syntax src` |
| `duplicates` | clone and copy-paste detection | `php vendor/bin/phpprobe duplicates src` |
| `api` | public API drift / BC checks | `php vendor/bin/phpprobe api --baseline=.phpprobe-api-baseline.json src` |
| `comments` | TODO/FIXME markers and commented-out-code policy | `php vendor/bin/phpprobe comments src` |
| `check` | run all four together | `php vendor/bin/phpprobe check src tests` |

## Quick Start

Common examples:

```bash
php vendor/bin/phpprobe syntax
php vendor/bin/phpprobe syntax --format=markdown --parallel=4 src
php vendor/bin/phpprobe duplicates
php vendor/bin/phpprobe duplicates --json
php vendor/bin/phpprobe duplicates --summary-json=build/duplicates-summary.json src
php vendor/bin/phpprobe duplicates --preset=strict --json src
php vendor/bin/phpprobe api --write-baseline=.phpprobe-api-baseline.json src
php vendor/bin/phpprobe api --baseline=.phpprobe-api-baseline.json src
php vendor/bin/phpprobe api --fail-on=error --format=markdown --baseline=.phpprobe-api-baseline.json src
php vendor/bin/phpprobe comments --fail-on=warning src
php vendor/bin/phpprobe comments --strict --json src
php vendor/bin/phpprobe comments --policy=strict --format=markdown src
php vendor/bin/phpprobe check --preset=standard --report-dir=build/reports src tests
php vendor/bin/phpprobe config validate --config=phpprobe.json
php vendor/bin/phpprobe init --preset=standard --with-ci
php vendor/bin/phpprobe presets
php vendor/bin/phpprobe preset standard
```

Detailed options and output contracts for each tool are documented in the sections below.

## Public API

The package-facing checker gateways live directly under `src/`:

- `Infocyph\PHPProbe\SyntaxChecker`
- `Infocyph\PHPProbe\DuplicateChecker`
- `Infocyph\PHPProbe\ApiSnapshotChecker`
- `Infocyph\PHPProbe\CommentChecker`

All expose:

```php
public function run(array $args): int
```

`$args` is the same argument list that follows the CLI subcommand. For example:

```php
use Infocyph\PHPProbe\ApiSnapshotChecker;
use Infocyph\PHPProbe\CommentChecker;
use Infocyph\PHPProbe\DuplicateChecker;
use Infocyph\PHPProbe\SyntaxChecker;

$syntaxCode = (new SyntaxChecker())->run(['--config=phpprobe.json', 'src']);
$duplicateCode = (new DuplicateChecker())->run(['--preset=strict', '--json', 'src']);
$apiCode = (new ApiSnapshotChecker())->run(['--baseline=.phpprobe-api-baseline.json', 'src']);
$commentCode = (new CommentChecker())->run(['--strict', '--fail-on=warning', 'src']);
```

Everything else is internal implementation detail, grouped by role:

| Namespace | Purpose |
| --- | --- |
| `Api` | Public API snapshot extraction from parser ASTs. |
| `Console` | CLI dispatch for `bin/phpprobe`. |
| `Config` | Config lookup, preset lookup, JSON parsing, config merging and shared CLI option handling. |
| `Detection` | Duplicate-code token indexing, AST block indexing, scoring, grouping and pruning. |
| `Filesystem` | Git-aware PHP file discovery and path exclusion. |
| `Process` | Small `proc_open` runner wrappers. |
| `Util` | Narrow shared helpers. |

## Config Lookup

The default config filename is `phpprobe.json`.

When a checker needs a config file and `--config` was not passed, PHPProbe resolves it in this order:

1. `phpprobe.json` in the current project root, meaning the current working directory.
2. `vendor/infocyph/phpprobe/resources/phpprobe.json` under the current project root.
3. `resources/phpprobe.json` only when the current project itself is `infocyph/phpprobe`.

If no config can be found, PHPProbe throws a runtime config error.

Preset files are bundled resources. They are resolved from:

1. `vendor/infocyph/phpprobe/resources/presets/<name>.json`.
2. `resources/presets/<name>.json` only while developing `infocyph/phpprobe` itself.

Project-root preset files are not looked up automatically.

When `--config=FILE` is passed explicitly and that file is missing, unreadable, empty, or invalid JSON, PHPProbe treats it as an empty config and continues with internal defaults plus any CLI options.

## Config Format

The bundled `resources/phpprobe.json` is intentionally small:

```json
{
  "preset": "standard"
}
```

A full project config may override any part of the selected preset:

```json
{
  "preset": "standard",
  "syntax": {
    "paths": ["src"],
    "exclude": ["src/generated"]
  },
  "duplicates": {
    "paths": ["src"],
    "exclude": ["src/generated"],
    "mode": "audit",
    "normalize": true,
    "fuzzy": true,
    "near_miss": true,
    "min_lines": 5,
    "min_tokens": 90,
    "min_statements": 4,
    "min_similarity": 0.85,
    "baseline": "",
    "write_baseline": "",
    "ignore_fingerprints": [],
    "json": false
  },
  "api": {
    "paths": ["src"],
    "exclude": ["src/generated"],
    "include_protected": true,
    "baseline": "",
    "write_baseline": "",
    "json": false
  },
  "comments": {
    "paths": ["src"],
    "exclude": ["src/generated"],
    "scan_markers": true,
    "fail_on": "error",
    "rules": {
      "comment_marker": {
        "enabled": true
      },
      "commented_out_code_with_weak_reason": {
        "severity": "warning"
      }
    }
  }
}
```

Config keys accept snake case, kebab case and camel case. For example, `min_tokens`, `min-tokens` and `minTokens` are equivalent. Excludes can be configured as either `exclude` or `exclude_paths`.
`duplicates.ignore_fingerprints` suppresses known clone fingerprints without a baseline file. `comments.rules` lets you toggle rule `enabled` and override per-rule `severity`.

Internal duplicate defaults, before any preset is applied, are `mode=gate`, `normalize=true`, `fuzzy=false`, `near_miss=false`, `min_lines=5`, `min_tokens=70`, `min_statements=4`, `min_similarity=0.85`, no baseline, no JSON output and no configured paths or excludes.

Internal API defaults are `include_protected=true`, no baseline, no JSON output and no configured paths or excludes.

Config merge order is:

1. Internal checker defaults.
2. Config-file `preset`, when present.
3. Explicit values in the config file.
4. CLI `--preset=NAME`, when present.
5. Explicit CLI flags and CLI paths.

Local config values override the config-file preset. CLI `--preset` is a run-level override and can override config-file values. Explicit CLI flags still win after that.

## Presets

Preset templates live in `resources/presets/` and are loaded by `Infocyph\PHPProbe\Config\PresetRepository`.

Available presets:

| Preset | Duplicate policy | API policy | Comment policy |
| --- | --- | --- | --- |
| `default` | Raw engine defaults. `gate` mode, normalized tokens, no fuzzy identifiers, no near-miss matching, `min_lines=5`, `min_tokens=70`, `min_statements=4`, `min_similarity=0.85`. | Includes protected members. | `policy=standard`, baseline thresholds (`min_reason_length=12`, `max_allowed_block_lines=10`, `require_issue_for_blocks_longer_than=3`). |
| `standard` | Recommended balanced preset. `audit` mode, normalized tokens, fuzzy identifiers, near-miss matching, `min_lines=5`, `min_tokens=90`, `min_statements=4`, `min_similarity=0.85`. | Includes protected members. | `policy=standard`, baseline thresholds (`12`, `10`, `3`). |
| `ci` | Quieter CI gate. `gate` mode, normalized tokens, fuzzy identifiers, no near-miss matching, `min_lines=6`, `min_tokens=100`, `min_statements=5`, `min_similarity=0.9`. | Includes protected members. | `policy=standard`, baseline thresholds (`12`, `10`, `3`). |
| `strict` | Sensitive audit. `audit` mode, normalized tokens, fuzzy identifiers, near-miss matching, `min_lines=4`, `min_tokens=70`, `min_statements=3`, `min_similarity=0.8`. | Includes protected members. | `policy=strict`, strict thresholds (`16`, `6`, `2`). |

The `standard`, `ci`, and `strict` presets include the same default syntax, duplicate, API and comment excludes:

```text
tests, vendor, node_modules, .git, .idea, .vscode, coverage,
.phpunit.cache, .psalm-cache, build, dist, tmp, .tmp, storage,
bootstrap/cache, var/cache
```

Their duplicate sections also exclude `storage/framework/views`.

Preset commands:

```bash
php vendor/bin/phpprobe presets
php vendor/bin/phpprobe preset standard
```

`presets` prints one preset name per line. `preset <name>` prints the bundled JSON template. Unknown preset names print an error and exit `2`. Legacy alias `phpstorm` is still accepted and resolves to `standard`.

## Combined Check Command

`check` runs `syntax`, `duplicates`, `api`, and `comments` in sequence and returns a combined exit code.

```bash
php vendor/bin/phpprobe check [options] [paths...]
```

Options:

| Option | Form | Meaning |
| --- | --- | --- |
| `--config` | `--config=FILE` | Read checker settings from a specific config file. |
| `--preset` | `--preset=NAME` | Apply `default`, `standard`, `ci`, or `strict`. |
| `--format` | `--format=text|json|markdown|sarif|github` | Combined output format. |
| `--summary-json` | `--summary-json=FILE` | Write combined run summary JSON. |
| `--report-dir` | `--report-dir=DIR` | Write per-checker `text/json/markdown/sarif` reports plus `summary.json`. |
| `--changed-only` | flag | Scan only changed PHP files from Git diff. |
| `--changed-base` | `--changed-base=REF` | Base ref used with `--changed-only`. |
| `--fail-on` | `--fail-on=error|warning|info` | Passed to duplicates, api, and comments. |
| `--help` | flag | Print command help. |

Exit behavior:

- If any checker exits `2`, combined exit is `2`.
- Else if any checker exits non-zero, combined exit is `1`.
- Otherwise combined exit is `0`.

## Config Validate Command

```bash
php vendor/bin/phpprobe config validate [options]
```

Options:

- `--config=FILE` (default `./phpprobe.json`)
- `--json` (machine-readable result)
- `--help`

Returns `0` for valid config, `1` for schema/key/type validation errors, and `2` for missing/unreadable/invalid JSON files.

## Init Command

```bash
php vendor/bin/phpprobe init [options]
```

Options:

- `--preset=NAME` (`default`, `standard`, `ci`, `strict`; default `standard`)
- `--path=FILE` (default `./phpprobe.json`)
- `--with-ci` (also writes `.github/workflows/phpprobe.yml`)
- `--force` (overwrite existing files)
- `--help`

## Syntax Checker

The syntax checker discovers PHP files, then runs PHP's native lint command against each file:

```bash
php -d display_errors=1 -l <file>
```

Command:

```bash
php vendor/bin/phpprobe syntax [options] [paths...]
```

Options:

| Option | Form | Meaning |
| --- | --- | --- |
| `--config` | `--config=FILE` or `--config FILE` | Read checker settings from a specific config file. |
| `--preset` | `--preset=NAME` or `--preset NAME` | Apply `default`, `standard`, `ci`, or `strict` as a run-level preset. |
| `--exclude` | `--exclude=PATH` or `--exclude PATH` | Exclude a path. Repeatable. |
| `--format` | `--format=text|json|markdown|sarif|github` | Output format. Default is `text`. |
| `--json` | flag | Alias for `--format=json`. |
| `--summary-json` | `--summary-json=FILE` | Write a machine-readable run summary JSON. |
| `--changed-only` | flag | Scan only changed PHP files from Git diff. |
| `--changed-base` | `--changed-base=REF` | Base ref used with `--changed-only`. |
| `--parallel` | `--parallel=N` | Parallel lint worker count. Default is `1`. |
| `--help`, `-h` | flag | Print syntax checker help and exit `0`. |

Path behavior:

- CLI paths override `syntax.paths` from config.
- If CLI paths are empty, `syntax.paths` is used.
- If both are empty, discovery starts from `.`.
- Config excludes and CLI excludes are merged.

Output and exits:

| Condition | Stream | Exit |
| --- | --- | --- |
| No PHP files found | `stdout`: `No PHP files found.` plus summary | `0` |
| All files pass | `stdout`: `Syntax OK: N PHP files checked.` plus summary | `0` |
| One or more files fail | `stderr`: failing file list plus lint output | `1` |
| Unknown option or runtime config error | `stderr`: error | `2` |
| Unknown preset | `stderr`: preset error | `2` |

## Comment Policy Checker

The comment checker scans PHP comments using `token_get_all()` and reports marker tags and commented-out code policy findings.

Command:

```bash
php vendor/bin/phpprobe comments [options] [paths...]
```

Options:

| Option | Form | Meaning |
| --- | --- | --- |
| `--config` | `--config=FILE` or `--config FILE` | Read checker settings from a specific config file. |
| `--preset` | `--preset=NAME` or `--preset NAME` | Apply `default`, `standard`, `ci`, or `strict` as a run-level preset. |
| `--exclude` | `--exclude=PATH` or `--exclude PATH` | Exclude a path. Repeatable. |
| `--format` | `--format=text|json|markdown|sarif|github` | Output format. Default is `text`. |
| `--json` | flag | Alias for `--format=json`. |
| `--strict` | flag | Escalate commented-out-code policy severities. |
| `--policy` | `--policy=relaxed|standard|strict` | Comment policy profile. |
| `--fail-on` | `--fail-on=error|warning|info` | Control failure threshold (default: `error`). |
| `--summary-json` | `--summary-json=FILE` | Write a machine-readable run summary JSON. |
| `--changed-only` | flag | Scan only changed PHP files from Git diff. |
| `--changed-base` | `--changed-base=REF` | Base ref used with `--changed-only`. |
| `--tags` | `--tags=TODO,FIXME,...` | Override marker tags for marker detection. |
| `--help`, `-h` | flag | Print comments checker help and exit `0`. |

Config-only option: `comments.rules` allows per-finding overrides such as `{ "comment_marker": { "enabled": false } }` or `{ "commented_out_code_with_weak_reason": { "severity": "info" } }`.

### Four enforced policies

1. Marker detection: tags like `TODO`, `FIXME`, `BUG`, `HACK`, `SECURITY`, `REVIEW`, `DEPRECATED`.
2. Commented-out code requires directly attached tagged reason.
3. Long commented-out blocks require an issue reference.
4. Oversized commented-out blocks are always reported.

Default thresholds:

- `min_reason_length = 12`
- `require_issue_for_blocks_longer_than = 3`
- `max_allowed_block_lines = 10`

Policy-to-finding mapping:

| Policy | Finding types |
| --- | --- |
| Marker detection | `comment_marker` |
| Tagged reason required for commented-out code | `commented_out_code_without_reason`, `commented_out_code_without_valid_tag`, `commented_out_code_without_valid_reason`, `commented_out_code_with_weak_reason` |
| Issue reference required for long blocks | `commented_out_code_requires_issue_reference` |
| Oversized block disallowed | `commented_out_code_block_too_large` |
| PHPDoc code without clear example label | `commented_out_code_in_phpdoc_without_example_label` |
| Invalid suppression directive | `invalid_suppression_rule` |
| Explicitly valid tagged reason (informational) | `commented_out_code_with_valid_reason` |

Output and exits:

| Condition | Stream | Exit |
| --- | --- | --- |
| No failing findings at threshold | `stdout`: summary (or JSON/markdown/SARIF) | `0` |
| Findings at or above threshold | `stderr`: text report (or JSON on `stdout`) | `1` |
| Unknown option or runtime config error | `stderr`: error | `2` |

## Public API Snapshot Checker

The API checker parses PHP files with `nikic/php-parser`, extracts the package-visible surface and can compare it with a saved snapshot. It is intended for library BC drift checks, not type analysis.

Command:

```bash
php vendor/bin/phpprobe api [options] [paths...]
```

Options:

| Option | Form | Meaning |
| --- | --- | --- |
| `--config` | `--config=FILE` or `--config FILE` | Read checker settings from a specific config file. |
| `--preset` | `--preset=NAME` or `--preset NAME` | Apply `default`, `standard`, `ci`, or `strict` as a run-level preset. |
| `--exclude` | `--exclude=PATH` or `--exclude PATH` | Exclude a path. Repeatable. |
| `--public-only` | flag | Ignore protected class members. |
| `--include-protected` | flag | Include protected members. This is the default. |
| `--baseline` | `--baseline=FILE` | Compare the current API against a snapshot file. |
| `--write-baseline` | `--write-baseline`, `--write-baseline=FILE` | Write the current API snapshot and exit `0`. Bare flag writes `.phpprobe-api-baseline.json`. |
| `--format` | `--format=text|json|markdown|sarif|github` | Output format. Default is `text`. |
| `--json` | flag | Alias for `--format=json`. |
| `--fail-on` | `--fail-on=error|warning|info` | Failure threshold for API drift. Default is `warning`. |
| `--summary-json` | `--summary-json=FILE` | Write a machine-readable run summary JSON. |
| `--changed-only` | flag | Scan only changed PHP files from Git diff. |
| `--changed-base` | `--changed-base=REF` | Base ref used with `--changed-only`. |
| `--help`, `-h` | flag | Print API checker help and exit `0`. |

Path behavior:

- CLI paths override `api.paths` from config.
- If CLI paths are empty, `api.paths` is used.
- If both are empty, discovery starts from `.`.
- Config excludes and CLI excludes are merged.

Snapshot contents:

- named classes, interfaces, traits and enums
- top-level namespaced functions
- top-level namespaced constants
- public members always
- protected members unless `--public-only` is used
- class modifiers, inheritance, implemented interfaces, method signatures, property signatures, constants, enum cases, function signatures and stable fingerprints

Output and exits:

| Condition | Stream | Exit |
| --- | --- | --- |
| No baseline passed | `stdout`: `Public API snapshot OK: N symbol(s) scanned.` | `0` |
| Baseline matches | `stdout`: `Public API unchanged: N symbol(s) scanned.` | `0` |
| Baseline differs | `stderr`: added/removed/changed symbol list | `1` by default, `0` when `--fail-on=error` |
| `--format=json|markdown|sarif|github` | `stdout`: selected format payload | `0` or `1`, depending on drift and fail-on |
| `--write-baseline` | `stdout`: baseline message or JSON result | `0` |
| Unknown option or runtime config/baseline error | `stderr`: error | `2` |
| Unknown preset | `stderr`: preset error | `2` |

## Duplicate Checker

The duplicate checker combines token fingerprints, AST block structure, statement windows, near-miss similarity, grouping, pruning, ranking and optional baseline suppression.

Command:

```bash
php vendor/bin/phpprobe duplicates [options] [paths...]
```

Options:

| Option | Form | Meaning |
| --- | --- | --- |
| `--config` | `--config=FILE` or `--config FILE` | Read checker settings from a specific config file. |
| `--preset` | `--preset=NAME` or `--preset NAME` | Apply `default`, `standard`, `ci`, or `strict` as a run-level preset. |
| `--exclude` | `--exclude=PATH` or `--exclude PATH` | Exclude a path. Repeatable. |
| `--mode` | `--mode=gate` or `--mode=audit` | `gate` runs token matching; `audit` also enables statement matching and near-miss matching. |
| `--min-lines` | `--min-lines=N` | Minimum duplicated line span. Values below `1` become `1`. |
| `--min-tokens` | `--min-tokens=N` | Token fingerprint window size. Values below `1` become `1`. |
| `--min-statements` | `--min-statements=N` | Statement window size for audit matching. Values below `1` become `1`. |
| `--min-similarity` | `--min-similarity=N` | Near-miss threshold. Accepts `0.0..1.0` or `0..100`; values above `1` are treated as percentages. |
| `--near-miss` | flag | Enable bounded statement/shape similarity matching. |
| `--exact` | flag | Disable variable/literal normalization and disable fuzzy matching. |
| `--fuzzy` | flag | Normalize identifiers/calls as `ID` for renamed-code scans. |
| `--no-fuzzy` | flag | Disable fuzzy identifier/call normalization. |
| `--baseline` | `--baseline=FILE` | Suppress clone groups whose fingerprints are already in a baseline file. |
| `--write-baseline` | `--write-baseline`, `--write-baseline=FILE` | Write current clone fingerprints to a baseline and exit `0`. Bare flag writes `.phpprobe-duplicates-baseline.json`. |
| `--format` | `--format=text|json|markdown|sarif|github` | Output format. Default is `text`. |
| `--json` | flag | Alias for `--format=json`. |
| `--fail-on` | `--fail-on=error|warning|info` | Failure threshold. Default is `warning`. |
| `--error-duplicate-percentage` | `--error-duplicate-percentage=N` | Error threshold used when `--fail-on=error`. Default `20`. |
| `--summary-json` | `--summary-json=FILE` | Write a machine-readable run summary JSON. |
| `--changed-only` | flag | Scan only changed PHP files from Git diff. |
| `--changed-base` | `--changed-base=REF` | Base ref used with `--changed-only`. |
| `--no-cache` | flag | Disable duplicate result cache. |
| `--cache-file` | `--cache-file=FILE` | Duplicate result cache path. |
| `--help`, `-h` | flag | Print duplicate checker help and exit `0`. |

Config-only option: `duplicates.ignore_fingerprints` accepts a list of clone fingerprints to suppress without using a baseline file.

Exact accepted forms matter: numeric options, `--mode`, `--baseline` and valued `--write-baseline=FILE` are parsed in equals form. `--config`, `--preset` and `--exclude` also accept split form. `--write-baseline` may also be passed as a bare flag.

Path behavior:

- CLI paths override `duplicates.paths` from config.
- If CLI paths are empty, `duplicates.paths` is used.
- If both are empty, discovery starts from `.`.
- Config excludes and CLI excludes are merged.

Mode behavior:

- `gate`: token-window duplicate detection only, unless `--near-miss` is explicitly passed.
- `audit`: token-window matching plus statement-window matching and near-miss matching is enabled automatically.

Output and exits:

| Condition | Stream | Exit |
| --- | --- | --- |
| No clone groups after baseline suppression | `stdout`: `No new duplicated code found (...)` plus summary | `0` |
| Clone groups found | `stderr`: text report plus summary | `1` by default |
| `--format=json|markdown|sarif|github` | `stdout`: selected format payload | depends on clone findings and fail-on |
| `--write-baseline` | `stdout`: baseline message or JSON result | `0` |
| Unknown option or runtime config/baseline error | `stderr`: error | `2` |
| Unknown preset | `stderr`: preset error | `2` |

## Duplicate Detection Details

File discovery:

- PHPProbe first tries `git ls-files -z --cached --others --exclude-standard`.
- It filters discovered PHP files with `git check-ignore -z --stdin --no-index`.
- If Git discovery is unavailable, it recursively scans the selected paths.
- Recursive fallback skips common infrastructure directories such as `.git`, `.idea`, `.phpunit.cache`, `.psalm-cache`, `.vscode`, `coverage`, `node_modules` and `vendor`.

Token normalization:

- Whitespace, comments, doc comments, PHP open tags and close tags are ignored.
- With `normalize=true`, variables become `VAR`, numbers become `NUM`, strings become `STR`.
- With `fuzzy=true`, identifiers and names become `ID`.
- With `--exact`, token values include token names and original text.

Token clones:

- PHPProbe hashes every normalized token window of `min_tokens` tokens.
- Matching windows are candidate clones.
- Candidates are extended token-by-token to find the full matching region.
- Overlapping windows in the same file are ignored.
- Clone regions below `min_lines` are ignored.

AST and statement matching:

- PHPProbe uses `nikic/php-parser` to index structural blocks.
- Indexed blocks include functions, methods, closures, arrow functions, loops, branches, match arms and try/catch/finally blocks.
- Statement hashes are built from AST shape.
- In `audit` mode, matching statement windows of `min_statements` statements are reported as statement clones.

Near-miss matching:

- Near-miss matching compares blocks with the same block type.
- Similarity is weighted as `72%` statement-hash similarity and `28%` AST-shape similarity.
- Similarity is based on longest-common-subsequence ratio.
- Matches below `min_similarity` are ignored.

Grouping, pruning and scoring:

- Duplicate pairs are grouped into clone families.
- Contained/weaker clones are pruned.
- Results are ranked by score, line span and similarity.
- Scoring rewards larger clones, more occurrences, higher similarity, structural completeness and near-miss signal; small trivial clones are penalized.

## Duplicate JSON Result Shape

`phpprobe duplicates --json` emits:

```json
{
  "files": 2,
  "total_lines": 100,
  "duplicated_lines": 20,
  "duplicate_percentage": 20.0,
  "known_clones": 0,
  "new_clones": 1,
  "clones": [
    {
      "fingerprint": "...",
      "source": "tokens",
      "score": 120.5,
      "similarity": 1.0,
      "tokens": 90,
      "lines": 10,
      "statements": 0,
      "block_type": "function",
      "occurrences": [
        {
          "file": "src/Example.php",
          "start_line": 10,
          "end_line": 20,
          "lines": 11,
          "context": "function"
        }
      ]
    }
  ]
}
```

Clone `source` is one of:

- `tokens`
- `statements`
- `near_miss`

`known_clones` is populated when a duplicate baseline is read. `new_clones` is the number of clone groups remaining after baseline suppression.

## API JSON Result Shape

`phpprobe api --json` emits:

```json
{
  "snapshot": {
    "version": 1,
    "generated_at": "2026-05-02T00:00:00+00:00",
    "symbols": [
      {
        "id": "class App\\Service",
        "kind": "class",
        "name": "App\\Service",
        "file": "src/Service.php",
        "line": 5,
        "modifiers": ["final"],
        "extends": "",
        "implements": [],
        "members": [],
        "fingerprint": "..."
      }
    ]
  },
  "baseline": {
    "version": 1,
    "generated_at": "",
    "symbols": []
  },
  "changed": false,
  "changes": {
    "added": [],
    "removed": [],
    "changed": []
  },
  "classifications": {
    "added": [],
    "removed": [],
    "changed": [
      {
        "id": "class App\\Service",
        "impact": "breaking",
        "reason": "Member signature changed: method App\\Service::run()"
      }
    ]
  },
  "impact": {
    "breaking": 1,
    "additive": 0,
    "internal": 0
  }
}
```

## Baselines

Write a baseline:

```bash
php vendor/bin/phpprobe duplicates --write-baseline
php vendor/bin/phpprobe duplicates --write-baseline=.phpprobe-duplicates-baseline.json
php vendor/bin/phpprobe api --write-baseline
php vendor/bin/phpprobe api --write-baseline=.phpprobe-api-baseline.json
```

Use a baseline:

```bash
php vendor/bin/phpprobe duplicates --baseline=.phpprobe-duplicates-baseline.json
php vendor/bin/phpprobe api --baseline=.phpprobe-api-baseline.json
```

This repository does not use a duplicate baseline in CI; duplicate findings are expected to be resolved directly.

Duplicate baseline files contain:

```json
{
  "version": 1,
  "generated_at": "2026-05-02T00:00:00+00:00",
  "clones": [
    {
      "fingerprint": "...",
      "source": "tokens",
      "score": 100.0
    }
  ]
}
```

API baseline files use the same top-level `version`, `generated_at` and `symbols` shape emitted under the `snapshot` JSON key. Missing, unreadable, or invalid baseline files now fail with exit code `2`.
Duplicate baseline files follow the same strict behavior: missing, unreadable, or invalid baselines fail with exit code `2`.

## Colored Output

Checker text output is colorized on interactive terminals:

- green: successful summaries
- yellow: warning/medium severity lines
- red: error/high/critical summaries
- cyan: baseline write notifications

Color output is automatically disabled for non-TTY streams and when `NO_COLOR` is set (or `TERM=dumb`), so CI logs and JSON output stay clean.

## CI / Cloud

Workflow: [.github/workflows/ci.yml](.github/workflows/ci.yml)

CI runs:

1. PHPProbe matrix on PHP `8.2`, `8.3`, `8.4`, `8.5`:
   - `composer validate --strict`
   - `composer test`
   - `composer lint`
   - `composer duplicates`
   - `composer api`
   - `composer comments`
2. PHPForge integration:
   - checks out `infocyph/phpforge`
   - injects local `phpprobe` via Composer `path` repository
   - runs PHPForge tests

`workflow_dispatch` supports `phpforge_ref` to test a specific PHPForge branch/tag/SHA.

## Development

Composer scripts:

| Script | Command |
| --- | --- |
| `composer test` | `vendor/bin/pest -c pest.xml` |
| `composer check` | `php bin/phpprobe check --preset=standard --config=resources/phpprobe.json src tests` |
| `composer lint` | `php bin/phpprobe syntax src tests` |
| `composer duplicates` | `php bin/phpprobe duplicates --preset=standard --config=resources/phpprobe.json src tests` |
| `composer api` | `php bin/phpprobe api --config=resources/phpprobe.json src tests` |
| `composer comments` | `php bin/phpprobe comments --config=resources/phpprobe.json src tests` |

Useful local checks:

```bash
composer validate --strict
composer test
composer check
composer lint
composer duplicates
composer api
composer comments
git diff --check
```
