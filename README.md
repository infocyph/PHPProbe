# PHPProbe

[![CI](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml/badge.svg)](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHPProbe is a focused, standalone quality gate for PHP syntax and duplicated code. It works in any Composer project and does not require a framework or PHPForge.

## Requirements

- PHP 8.2 or newer
- Composer 2
- The tokenizer extension
- `proc_open` for syntax checking

## Installation

```bash
composer require --dev infocyph/phpprobe
php vendor/bin/phpprobe init --preset=standard --with-ci
```

The initializer creates `phpprobe.json` and, when requested, a GitHub Actions workflow. It will not overwrite either file unless `--force` is supplied.

## Quick start

```bash
php vendor/bin/phpprobe syntax src tests
php vendor/bin/phpprobe duplicates src
php vendor/bin/phpprobe check src tests
```

`check` runs syntax first. Duplicate analysis only runs when syntax succeeds, preventing parser noise and wasted work on invalid source.

| Command | Purpose |
| --- | --- |
| `syntax` | Lint PHP files, sequentially or with bounded parallel workers. |
| `duplicates` | Detect exact, normalized, fuzzy, structural, and near-miss clones. |
| `check` | Run syntax and the configured duplicate-detector profile, then optionally write report artifacts. |
| `config validate` | Validate a configuration file without running a scan. |
| `init` | Create a minimal configuration and optional CI workflow. |
| `doctor` | Check the runtime, required extension, process support, and config. |
| `presets` / `preset <name>` | List or inspect bundled presets. |

## Duplicate detection

The low-level default `gate` mode uses token streams and rolling fingerprints. It avoids AST construction when no AST-backed detector is enabled. The recommended `standard` preset preserves the complete detector matrix: normalized/fuzzy token clones, AST-backed statement clones, structural matching, and bounded near-miss matching. `audit` mode enables those AST-backed paths.

```bash
# Fast deterministic gate
php vendor/bin/phpprobe duplicates --mode=gate --min-lines=5 --min-tokens=90 src

# Deeper structural audit
php vendor/bin/phpprobe duplicates \
  --mode=audit \
  --near-miss \
  --min-statements=4 \
  --min-similarity=0.88 \
  --max-near-miss-comparisons=100000 \
  src
```

Top-level namespace imports are intentionally excluded from clone fingerprints. Repeating `use Vendor\\Package\\Type;` across files is normal dependency declaration, not copied behavior. Trait `use` statements and closure capture clauses remain part of analysis because they affect executable structure.

Normalization levels:

- default normalization replaces variables and literal values;
- `--exact` compares the original token values;
- `--fuzzy` additionally normalizes identifiers and calls;
- `--near-miss` compares related statement and AST shapes within a hard comparison budget.

Baselines suppress known clone groups while preserving stable fingerprints across line movement:

```bash
php vendor/bin/phpprobe duplicates --write-baseline=.phpprobe-duplicates-baseline.json src
php vendor/bin/phpprobe duplicates --baseline=.phpprobe-duplicates-baseline.json src
```

The result cache is content-addressed, versioned, size-bounded, schema-validated, and written atomically. Disable it with `--no-cache` or choose a location with `--cache-file=FILE`.

## Syntax checking

```bash
php vendor/bin/phpprobe syntax --parallel=4 --timeout=30 src tests
```

Workers are bounded to 1–64, each lint process has a configurable timeout, and failures are sorted by path for deterministic output. Missing scan paths and invalid configuration are errors rather than silent passes.

## Configuration

A minimal project config can select a preset:

```json
{
  "preset": "standard"
}
```

All supported settings can be overridden explicitly:

```json
{
  "preset": "standard",
  "output": {
    "colors": {
      "success": "green",
      "error": "red",
      "warning": "yellow",
      "info": "cyan",
      "file": "cyan"
    }
  },
  "syntax": {
    "paths": ["src", "tests"],
    "exclude": ["vendor", "build"],
    "parallel": 4,
    "timeout": 30,
    "format": "text",
    "summary_json": "",
    "changed_only": false,
    "changed_base": ""
  },
  "duplicates": {
    "paths": ["src"],
    "exclude": ["vendor", "tests", "build"],
    "mode": "gate",
    "normalize": true,
    "fuzzy": true,
    "near_miss": false,
    "min_lines": 5,
    "min_tokens": 90,
    "min_statements": 4,
    "min_similarity": 0.85,
    "max_near_miss_comparisons": 100000,
    "baseline": "",
    "write_baseline": "",
    "ignore_fingerprints": [],
    "fail_on": "warning",
    "error_duplicate_percentage": 20,
    "cache": {
      "enabled": true,
      "file": ""
    },
    "output": {
      "style": "compact",
      "score_colors": {
        "high": { "min": 260, "color": "red" },
        "medium": { "min": 180, "color": "yellow" },
        "low": { "min": 120, "color": "cyan" },
        "base": { "color": "gray" }
      }
    }
  }
}
```

Configuration is strict: unknown keys, invalid enum values, unsafe worker counts, unbounded comparison limits, and incorrect value types fail with exit code `2`.

Preset intent:

- `default`: explicit low-level defaults;
- `standard`: complete token, statement, structural, and near-miss analysis;
- `ci`: deterministic CI thresholds and two lint workers;
- `strict`: AST-backed audit with bounded near-miss detection.

## Changed files and exclusions

Use `--changed-only` to combine committed changes against the selected base with working-tree and untracked PHP files:

```bash
php vendor/bin/phpprobe check --changed-only --changed-base=origin/main
```

CLI paths replace configured paths. Repeat `--exclude=PATH` to add exclusions. Git repositories use tracked and untracked file lists; other directories use a symlink-safe recursive scan.

## Output and automation

Every checker supports `text`, `json`, `markdown`, `sarif`, and `github` formats. Exit codes are stable:

- `0`: the configured gate passed;
- `1`: findings crossed the configured failure threshold;
- `2`: usage, configuration, environment, I/O, or execution error.

```bash
php vendor/bin/phpprobe check \
  --preset=ci \
  --format=github \
  --summary-json=build/phpprobe-summary.json \
  --report-dir=build/phpprobe \
  src tests
```

The report directory contains checker JSON, a Markdown summary, SARIF, and a combined JSON summary. See [the automation guide](docs/automation.rst) for CI examples.

## Programmatic use

The checker gateway classes accept the same argument list as the CLI:

```php
use Infocyph\PHPProbe\DuplicateChecker;
use Infocyph\PHPProbe\SyntaxChecker;

$syntaxExit = (new SyntaxChecker())->run(['--format=json', 'src']);
$duplicateExit = (new DuplicateChecker())->run(['--mode=gate', 'src']);
```

Output is written to standard output/error and the returned integer is the CLI-compatible exit code.

## Development

PHPProbe intentionally owns its standalone toolchain; no `ic:*` scripts are required.

```bash
composer install
composer tests
composer benchmark
```

`composer tests` runs Pest, max-level PHPStan, PHPCS, Pint dry-run, Rector dry-run, and PHPProbe against itself.

Full documentation is available in the [docs directory](docs/index.rst). Security reports should follow [SECURITY.md](SECURITY.md), and contributions should follow [CONTRIBUTING.md](CONTRIBUTING.md).

## License

PHPProbe is released under the [MIT License](LICENSE).
