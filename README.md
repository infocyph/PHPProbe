# PHPProbe

[![CI](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml/badge.svg)](https://github.com/infocyph/PHPProbe/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHPProbe is a focused, standalone quality gate for PHP syntax, duplicated code, and comment policy. It works in any Composer project and does not require a framework or PHPForge.

## Requirements

- PHP 8.4 or newer
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
php vendor/bin/phpprobe comments src tests
php vendor/bin/phpprobe check src tests
```

`check` runs syntax first. Duplicate and comment analysis only run when syntax succeeds, preventing parser noise and wasted work on invalid source.

| Command | Purpose |
| --- | --- |
| `syntax` | Lint PHP files, sequentially or with bounded parallel workers. |
| `duplicates` | Detect exact, normalized, fuzzy, structural, and near-miss clones. |
| `comments` | Enforce marker, commented-out-code, PHPDoc, custom-rule, and suppression policies. |
| `check` | Run syntax and the configured duplicate/comment profiles, then optionally write report artifacts. |
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

## Comment policy

The comment checker distinguishes documentation from code-like comments and supports three PHPDoc modes: `heuristic`, `parser`, and `hybrid`. The default hybrid mode uses the PHPDoc parser where possible and falls back safely for malformed input.

```bash
# Review every finding with explanations
php vendor/bin/phpprobe comments --fail-on=info --explain src tests

# CI policy with stable machine-readable output
php vendor/bin/phpprobe comments \
  --ci \
  --format=sarif \
  --summary-json=build/comments-summary.json \
  src tests
```

Commented-out code must have a nearby tagged reason such as `TODO(PROJ-123): retain until the legacy endpoint is retired`. Large blocks can require an issue reference. PHPProbe also detects marker tags, malformed or stale suppressions, genuine PHPDoc signature drift, invalid PHPDoc tag values, and configured custom regex rules. Refined PHPDoc types such as array shapes, lists, generics, callable and `Closure` signatures, local PHPStan/Psalm type aliases, bounded templates, conditional returns, `static`/`self`, and compatible nullable unions are accepted against their broader native declarations. Fenced PHP examples and configured example labels are treated as documentation, while genuine commented-out executable code remains enforced. Existing findings can be managed with a fingerprint baseline:

```bash
php vendor/bin/phpprobe comments --write-baseline=.phpprobe-comments-baseline.json src
php vendor/bin/phpprobe comments --baseline=.phpprobe-comments-baseline.json src
```

See [comment policy](docs/comments.rst) for rules, suppressions, configuration, and practical examples.

## Syntax checking

```bash
php vendor/bin/phpprobe syntax --parallel=2 --timeout=30 src tests
```

Workers are bounded to 1–64, each lint process has a configurable timeout, and supplied parent paths are scheduled round-robin. For example, `--parallel=2 src tests` starts work from both trees while retaining a global two-process limit. Failures remain sorted by path for deterministic output. Missing scan paths and invalid configuration are errors rather than silent passes.

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
    "format": "text",
    "summary_json": "",
    "changed_only": false,
    "changed_base": "",
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
  },
  "comments": {
    "paths": ["src", "tests"],
    "exclude": ["vendor", "build"],
    "format": "text",
    "summary_json": "",
    "changed_only": false,
    "changed_base": "",
    "fail_on": "error",
    "fail_confidence": "low",
    "doc_mode": "hybrid",
    "explain": false,
    "baseline": "",
    "write_baseline": "",
    "scan_markers": true,
    "marker_tags": [
      "TODO", "FIXME", "BUG", "HACK", "XXX", "NOTE", "OPTIMIZE",
      "REFACTOR", "DEPRECATED", "SECURITY", "REVIEW", "QUESTION", "WARNING"
    ],
    "marker_severity": {
      "SECURITY": "critical", "BUG": "high", "FIXME": "high",
      "HACK": "medium", "XXX": "medium", "WARNING": "medium",
      "TODO": "low", "OPTIMIZE": "low", "REFACTOR": "low",
      "DEPRECATED": "low", "REVIEW": "info", "QUESTION": "info", "NOTE": "info"
    },
    "custom_rules": [],
    "doc_cache": { "enabled": true, "file": "" },
    "doc_signature_consistency": true,
    "doc_type_hygiene": true,
    "rules": {}
  },
  "commented_out_code": {
    "enabled": true,
    "policy": "standard",
    "allowed_reason_tags": ["TODO", "FIXME", "BUG", "HACK", "SECURITY", "REVIEW", "DEPRECATED"],
    "optional_reason_tags": ["TEMP", "DEBUG", "EXPERIMENTAL"],
    "allow_optional_reason_tags_in_strict_mode": false,
    "ignore_paths": [],
    "suppression": { "enabled": true, "directive": "@phpprobe-ignore" },
    "min_reason_length": 12,
    "max_allowed_block_lines": 10,
    "require_issue_for_blocks_longer_than": 3,
    "allowed_issue_patterns": ["/#\\d+/", "/[A-Z]+-\\d+/"],
    "single_line_comments": { "allow_blank_line_between_reason_and_code": false },
    "block_comments": {
      "allow_reason_before_block_comment": true,
      "allow_blank_line_between_reason_and_code": true
    },
    "phpdoc_comments": {
      "allow_documentation_examples": true,
      "example_labels": ["Example:", "Examples:", "Usage:", "Snippet:", "Code sample:"]
    },
    "finding_severity": {
      "comment_marker": "info",
      "commented_out_code_without_reason": "warning",
      "commented_out_code_without_valid_tag": "warning",
      "commented_out_code_without_valid_reason": "warning",
      "commented_out_code_with_weak_reason": "warning",
      "commented_out_code_with_valid_reason": "info",
      "commented_out_code_block_too_large": "error",
      "commented_out_code_requires_issue_reference": "warning",
      "commented_out_code_in_phpdoc_without_example_label": "warning",
      "invalid_suppression_rule": "warning",
      "expired_suppression_rule": "warning",
      "dead_suppression_rule": "warning",
      "phpdoc_signature_mismatch": "warning",
      "phpdoc_unknown_param": "warning",
      "phpdoc_missing_param": "info",
      "phpdoc_invalid_tag_value": "warning"
    },
    "finding_severity_strict": {
      "commented_out_code_without_reason": "error",
      "commented_out_code_without_valid_tag": "error",
      "commented_out_code_without_valid_reason": "error",
      "commented_out_code_with_weak_reason": "error",
      "commented_out_code_block_too_large": "error",
      "invalid_suppression_rule": "error",
      "expired_suppression_rule": "error",
      "dead_suppression_rule": "error",
      "phpdoc_signature_mismatch": "error",
      "phpdoc_unknown_param": "error",
      "phpdoc_invalid_tag_value": "error"
    }
  }
}
```

Configuration is strict: unknown keys, invalid enum values, unsafe worker counts, unbounded comparison limits, and incorrect value types fail with exit code `2`.

Preset intent:

- `default`: low-overhead syntax, token duplicate, and standard comment-policy defaults;
- `standard`: full duplicate detector matrix with the standard comment policy and generated-path exclusions;
- `ci`: CI-oriented exclusions, two syntax workers, and more conservative duplicate thresholds;
- `strict`: tighter AST-backed duplicate thresholds and strict comment-policy thresholds/severities.

## Changed files and exclusions

Use `--changed-only` to combine committed changes against the selected base with working-tree and untracked PHP files:

```bash
php vendor/bin/phpprobe check --changed-only --changed-base=origin/main
```

CLI paths replace configured paths. Repeat `--exclude=PATH` to add exclusions. Git repositories use tracked and untracked file lists; other directories use a symlink-safe recursive scan.

## Output and automation

Every checker supports `text`, `json`, `phpstan-json`, `markdown`, `sarif`, and `github` formats. Text reports use terminal-safe tables grouped by each supplied parent path. Native JSON retains checker-specific details and adds group summaries; `phpstan-json` emits PHPStan's file-keyed error-formatter shape. Exit codes are stable:

For comment reports, `--fail-on` is also the emission threshold: `error` shows only error/critical findings, `warning` adds warning/high findings, and `info` shows everything.

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
use Infocyph\PHPProbe\CommentChecker;
use Infocyph\PHPProbe\DuplicateChecker;
use Infocyph\PHPProbe\SyntaxChecker;

$syntaxExit = (new SyntaxChecker())->run(['--format=json', 'src']);
$duplicateExit = (new DuplicateChecker())->run(['--mode=gate', 'src']);
$commentExit = (new CommentChecker())->run(['--fail-on=warning', 'src']);
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

Full documentation is available in the [docs directory](docs/index.rst), including the [complete CLI reference](docs/cli-reference.rst). Security reports should follow [SECURITY.md](SECURITY.md), and contributions should follow [CONTRIBUTING.md](CONTRIBUTING.md).

## License

PHPProbe is released under the [MIT License](LICENSE).
