CLI reference
=============

All commands return ``0`` for success, ``1`` when a valid scan crosses its
configured gate (or a diagnostic check fails), and ``2`` for usage,
configuration, environment, process, or I/O errors.

Checker common behavior
-----------------------

``syntax``, ``reference``, ``duplicates``, and ``comments`` accept positional file/directory
paths. CLI paths replace configured paths. Use ``--`` to treat every remaining
argument as a path, including names beginning with ``-``.

The four checkers share these options:

``--config=FILE``
   Read settings from FILE. Without the option, PHPProbe uses
   ``./phpprobe.json`` when it exists and otherwise falls back to its bundled
   standard configuration.

``--preset=NAME``
   Apply ``default``, ``standard``, ``ci``, or ``strict`` over the file config.

``--exclude=PATH``
   Add an exclusion. Repeatable; both ``--exclude=PATH`` and
   ``--exclude PATH`` are accepted.

``--format=FORMAT`` / ``--json``
   Select ``text``, ``json``, ``phpstan-json``, ``markdown``, ``sarif``, or ``github``.
   ``--json`` is an alias for ``--format=json``.

   ``phpstan-json`` emits the PHPStan JSON error-formatter structure without
   changing PHPProbe's native ``json`` contracts.

``--color=MODE``
   ``auto``, ``always``, or ``never``. This controls ANSI text output only.

``--summary-json=FILE``
   Atomically write the checker's compact JSON summary.

``--changed-only`` / ``--changed-base=REF``
   Limit Git discovery to committed changes against REF plus working-tree and
   untracked PHP files.

``--help`` / ``-h``
   Print command help.

``syntax``
----------

.. code-block:: text

   phpprobe syntax [options] [paths...]

Additional options:

``--parallel=N``
   Bounded global lint worker count, 1–64. Supplied input paths are scheduled
   round-robin within that limit. Default: 1.

``--timeout=SECONDS``
   Per-file process timeout, 0.1–600. Default: 30.

``reference``
-------------

.. code-block:: text

   phpprobe reference [options] [paths...]

The checker reports unresolved class-like references and declarations whose
FQCN does not match the path implied by Composer's PSR-4 configuration. Every
finding is error-level and makes the command return ``1``. Findings include
ranked replacement candidates; no credible candidate is reported as a possible
dead reference.

Composer ``ext-*`` packages in ``require`` and ``require-dev`` are checked
against the PHP runtime executing PHPProbe. Missing required extensions are
certain errors and include an install-or-enable suggestion. Composer
``config.platform`` simulation does not override the active-runtime check.

``--composer=FILE``
   Composer metadata used for project PSR-4 mappings and installed autoload
   metadata. Default: ``composer.json``.

With ``--changed-only``, PHPProbe reports references in changed files while
still indexing the complete set of files from the configured scan paths.

``duplicates``
--------------

.. code-block:: text

   phpprobe duplicates [options] [paths...]

Additional options:

Supplied parent paths are displayed as report groups. Detection still analyzes
one unified corpus, so clones spanning two parent paths are retained.

``--mode=gate|audit``
   Select fast token gate or complete AST-backed audit.

``--exact`` / ``--fuzzy`` / ``--no-fuzzy``
   Disable variable/literal normalization, enable identifier/call
   normalization, or explicitly disable fuzzy normalization.

``--near-miss``
   Enable bounded statement/shape similarity.

``--min-lines=N`` / ``--min-tokens=N`` / ``--min-statements=N``
   Positive detector thresholds. Defaults: 5, 70, and 4.

``--min-similarity=N``
   Near-miss similarity from 0–1 or as 0–100 percent. Default: 0.85.

``--max-near-miss-comparisons=N``
   Hard ceiling from 1–10,000,000. Default: 100,000.

``--baseline=FILE``
   Suppress clone groups present in FILE.

``--write-baseline[=FILE]``
   Write current clone fingerprints and return success. Default file when
   omitted: ``.phpprobe-duplicates-baseline.json``.

``--fail-on=error|warning|info``
   Gate threshold. Default: ``warning``.

``--error-duplicate-percentage=N``
   Percentage threshold used by ``fail-on=error``. Range 0–100; default 20.

``--no-cache`` / ``--cache-file=FILE``
   Disable the result cache or select its location.

``comments``
------------

.. code-block:: text

   phpprobe comments [options] [paths...]

Additional options:

Supplied parent paths are scanned in round-robin order and displayed as report
groups. The selected policy and failure threshold remain global to the run.

``--policy=relaxed|standard|strict``
   Select threshold and strictness profile.

``--strict``
   Apply strict finding severities without changing the selected policy bounds.

``--doc-mode=heuristic|parser|hybrid``
   Select PHPDoc analysis. Default: ``hybrid``.

``--baseline=FILE`` / ``--write-baseline[=FILE]``
   Read or write finding fingerprints. The default output file is
   ``.phpprobe-comments-baseline.json``.

``--fail-on=error|warning|info``
   Minimum severity group emitted in reports and allowed to fail the command.
   Default: ``error``.

``--fail-confidence=low|medium|high``
   Minimum confidence that can fail. Default: ``low``.

``--ci``
   Emit and fail on error-level findings only.

``--explain``
   Include explanation and suggested remediation fields.

``--tags=TAG,TAG,...``
   Replace configured marker tags for the run.

``check``
---------

.. code-block:: text

   phpprobe check [options] [paths...]

``check`` starts each checker in an isolated subprocess. Syntax runs first;
reference, duplicates, and comments run only after syntax succeeds. It accepts:

* ``--config``, ``--preset``, ``--format``, ``--summary-json``,
  ``--changed-only``, ``--changed-base``, and repeatable ``--exclude``;
* syntax ``--parallel`` and ``--timeout``;
* shared duplicate/comment ``--fail-on``;
* duplicate ``--mode``, ``--exact``, ``--fuzzy``, ``--no-fuzzy``,
  ``--near-miss``, all numeric thresholds, baseline/write-baseline,
  ``--no-cache``, ``--cache-file``, and error percentage options;
* ``--report-dir=DIR`` for checker JSON, Markdown, SARIF, and combined summary
  artifacts.

Comment-only policy switches are intentionally configured in ``phpprobe.json``
when using ``check``. Run ``comments`` directly for one-off comment CLI
overrides.

``config validate``
-------------------

.. code-block:: text

   phpprobe config validate [--config=FILE] [--json]

The default path is ``./phpprobe.json``. A syntactically valid file with schema
errors returns ``1``; unreadable/invalid JSON returns ``2``.

``init``
--------

.. code-block:: text

   phpprobe init [--preset=NAME] [--path=FILE] [--with-ci] [--force]

The default preset is ``standard`` and default path is ``./phpprobe.json``.
``--with-ci`` also creates ``.github/workflows/phpprobe.yml``. Existing targets
are never overwritten unless ``--force`` is present.

``doctor``
----------

.. code-block:: text

   phpprobe doctor [--config=FILE] [--json]

Doctor checks PHP version, JSON/tokenizer extensions, ``proc_open``, and the
configuration. A missing config is a warning; an invalid one is a failure.

Presets
-------

``phpprobe presets`` lists bundled names. ``phpprobe preset <name>`` prints the
selected preset as JSON so its exact current values can be inspected.
