Configuration reference
=======================

PHPProbe reads strict JSON. The root must be an object, the file must be
non-empty and no larger than 1 MiB, and unknown keys or incorrect types are
errors. Validate without scanning:

.. code-block:: bash

   php vendor/bin/phpprobe config validate phpprobe.json

The smallest useful configuration selects a preset:

.. code-block:: json

   {"preset": "standard"}

Precedence
----------

Values are resolved in this order, from lowest to highest priority:

#. PHPProbe's built-in defaults;
#. the preset named by root ``preset``;
#. explicit values in the configuration file;
#. the preset selected by CLI ``--preset``;
#. explicit checker CLI options.

CLI path arguments replace configured ``paths``. Repeatable CLI ``--exclude``
values augment configured exclusions. Nested preset/config objects are merged;
lists and scalar values are replaced.

Root keys
---------

``preset``
   One of ``default``, ``standard``, ``ci``, or ``strict``.

``output``
   Global text colors shared by the checkers.

``syntax``, ``reference``, ``duplicates``, ``comments``
   Per-checker configuration objects.

``commented_out_code``
   Dormant-code, suppression, and comment-finding policy used by ``comments``.

Global output
-------------

``output.colors`` accepts the keys ``success``, ``error``, ``warning``,
``info``, and ``file``. Each accepts ``red``, ``green``, ``yellow``, ``blue``,
``magenta``, ``cyan``, ``gray``, or ``bold``. Defaults are green, red, yellow,
cyan, and cyan respectively.

Common checker keys
-------------------

Every ``syntax``, ``reference``, ``duplicates``, and ``comments`` object supports:

``paths``
   List of files/directories to scan. Default: empty; supply paths in config or
   on the CLI.

``exclude``
   List of path fragments excluded from discovery. Default: empty before a
   project preset is applied.

``format``
   ``text``, ``json``, ``phpstan-json``, ``markdown``, ``sarif``, or ``github``.
   Default: ``text``.

``summary_json``
   File path for an atomically written machine-readable summary. Empty disables
   it.

``changed_only``
   Boolean. When true, discover only PHP files changed in Git.

``changed_base``
   Git base reference used by changed-only discovery, for example
   ``origin/main``.

Syntax keys
-----------

``parallel``
   Integer from 1 through 64. Default: 1.

``timeout``
   Number from 0.1 through 600 seconds per PHP lint process. Default: 30.

Reference keys
--------------

``composer``
   Composer metadata file used to resolve the project's PSR-4 mappings and the
   installed dependency autoloader. Default: ``composer.json``.

Duplicate keys
--------------

``mode``
   ``gate`` or ``audit``. Default: ``gate``. Audit mode enables near-miss
   analysis and AST-backed detectors.

``normalize``
   Boolean. Normalize variable names and literal values. Default: true.

``fuzzy``
   Boolean. Also normalize identifiers and calls. Default: false.

``near_miss``
   Boolean. Enable bounded statement/shape similarity. Default: false; audit
   mode makes it effective even when omitted.

``min_lines``
   Positive integer minimum physical lines per occurrence. Default: 5.

``min_tokens``
   Positive integer token fingerprint window. Default: 70.

``min_statements``
   Positive integer statement window. Default: 4.

``min_similarity``
   Number from 0 through 1 in JSON. Default: 0.85. The equivalent CLI option
   additionally accepts percentages from 0 through 100.

``max_near_miss_comparisons``
   Integer from 1 through 10,000,000. Default: 100,000.

``baseline`` / ``write_baseline``
   Input/output baseline file paths. Empty disables the operation.

``ignore_fingerprints``
   List of exact clone fingerprints to suppress without a baseline file.

``fail_on``
   ``error``, ``warning``, or ``info``. Default: ``warning``.

``error_duplicate_percentage``
   Number from 0 through 100. With ``fail_on=error``, the command fails only
   when the new-clone duplicated-line percentage reaches this value. Default:
   20.

``cache.enabled`` / ``cache.file``
   Boolean cache toggle and optional cache path. The default cache is enabled
   and project-scoped in the system temporary directory.

``output.style``
   ``compact`` or ``classic``. Default: ``compact``.

``output.score_colors``
   Object with ``high``, ``medium``, ``low``, and ``base`` bands. High/medium/
   low accept a non-negative numeric ``min`` plus ``color``; base accepts only
   ``color``. Defaults are 260/red, 180/yellow, 120/cyan, and gray. Colors use
   the global color enum.

Comment keys
------------

``fail_on``
   ``error``, ``warning``, or ``info``. Default: ``error``. See
   :doc:`comments` for severity grouping.

``fail_confidence``
   ``low``, ``medium``, or ``high``. Default: ``low``.

``doc_mode``
   ``heuristic``, ``parser``, or ``hybrid``. Default: ``hybrid``.

``doc_signature_consistency`` / ``doc_type_hygiene``
   Boolean PHPDoc checks. Both default to true.

``explain``
   Boolean. Include explanation and remediation text. Default: false.

``baseline`` / ``write_baseline``
   Input/output finding baseline paths. Empty disables the operation.

``scan_markers``
   Boolean marker scan toggle. Default: true.

``marker_tags``
   List of non-empty marker names. See :doc:`comments` for defaults.

``marker_severity``
   Object mapping marker tags to severities.

``custom_rules``
   List of custom rule objects. ``id`` and valid PHP regex ``pattern`` are
   required. ``severity`` defaults to ``warning``; ``message`` defaults to a
   generated message; ``enabled`` defaults to true; ``scope`` defaults to
   ``all`` and accepts ``all``, ``line``, ``block``, or ``doc``.

``doc_cache.enabled`` / ``doc_cache.file``
   Boolean parsed-PHPDoc cache toggle and optional path. The cache is enabled
   by default and project-scoped in the system temporary directory.

``rules``
   Object keyed by finding ID. Each value may contain boolean ``enabled`` and/or
   ``severity``. An empty object means no overrides.

Commented-out-code keys
-----------------------

``enabled``
   Boolean dormant-code detection toggle. Default: true. Marker and PHPDoc
   analysis remain separately controlled.

``policy``
   ``relaxed``, ``standard``, or ``strict``. Default: ``standard``.

``allowed_reason_tags``
   List of accepted reason tags. Default: ``TODO``, ``FIXME``, ``BUG``,
   ``HACK``, ``SECURITY``, ``REVIEW``, and ``DEPRECATED``.

``optional_reason_tags``
   List accepted outside strict mode. Default: ``TEMP``, ``DEBUG``, and
   ``EXPERIMENTAL``.

``allow_optional_reason_tags_in_strict_mode``
   Boolean. Default: false.

``ignore_paths``
   List of extra path fragments excluded only by the comments checker.

``suppression.enabled`` / ``suppression.directive``
   Boolean directive toggle and literal directive prefix. Defaults: true and
   ``@phpprobe-ignore``.

``min_reason_length``
   Integer greater than or equal to 1. Default: 12; policy bounds may adjust it.

``max_allowed_block_lines``
   Integer greater than or equal to 1. Default: 10; policy bounds may adjust it.

``require_issue_for_blocks_longer_than``
   Integer greater than or equal to 0. Default: 3. Zero requires a reference for
   every detected block; policy bounds may adjust it.

``allowed_issue_patterns``
   List of valid PHP regular expressions. Defaults accept ``#123`` and
   ``PROJ-123``-style references.

``single_line_comments.allow_blank_line_between_reason_and_code``
   Boolean. Default: false.

``block_comments.allow_reason_before_block_comment``
   Boolean. Default: true.

``block_comments.allow_blank_line_between_reason_and_code``
   Boolean. Default: true.

``phpdoc_comments.allow_documentation_examples``
   Boolean. Default: true.

``phpdoc_comments.example_labels``
   List of labels that introduce code samples. Defaults: ``Example:``,
   ``Examples:``, ``Usage:``, ``Snippet:``, and ``Code sample:``.

``finding_severity`` / ``finding_severity_strict``
   Objects mapping finding IDs to severities. The first defines normal defaults;
   the second overlays strict mode. The complete built-in matrix is in
   :doc:`comments`.

Severity values
---------------

Every marker, finding, custom-rule, and rule-override severity accepts exactly:
``error``, ``critical``, ``high``, ``warning``, ``medium``, ``low``, or
``info``.

Presets
-------

``default``
   Low-level defaults: one syntax worker, normalized token duplicate detection,
   and standard comment policy. It intentionally has no project exclusions.

``standard``
   Applies common generated/vendor exclusions, audit duplicate mode, fuzzy and
   bounded near-miss matching, 90 tokens, four statements, and 0.85 similarity.

``ci``
   Applies common exclusions, excludes tests from duplicate/comment analysis,
   uses two syntax workers, fuzzy duplicate matching, 100 tokens, five
   statements, and 0.90 similarity.

``strict``
   Applies common exclusions, two syntax workers, audit/fuzzy/near-miss duplicate
   detection with 4 lines, 70 tokens, 3 statements, and 0.80 similarity, plus
   strict comment policy with a 16-character reason, six-line maximum block,
   and issue references above two lines.

The ``standard`` and ``strict`` duplicate presets exercise every detector
family. ``default`` exists as the explicit low-overhead building block.
