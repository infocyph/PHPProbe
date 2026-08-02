Configuration
=============

Configuration is strict JSON. Unknown keys and incorrect types are rejected.
The smallest useful file selects a preset:

.. code-block:: json

   {
     "preset": "standard"
   }

Presets
-------

``default`` exposes the fast token-engine defaults. ``standard`` enables the
complete token, statement, structural, and bounded near-miss detector matrix.
``ci`` uses deterministic token thresholds, two syntax workers, and CI-oriented
comment exclusions. ``strict`` uses the complete AST-backed duplicate audit and
strict comment severities.

Syntax keys
-----------

``paths`` and ``exclude`` are lists of strings. ``format`` accepts ``text``,
``json``, ``markdown``, ``sarif``, or ``github``. ``parallel`` accepts 1–64,
and ``timeout`` accepts 0.1–600 seconds. ``changed_only`` is boolean;
``changed_base`` and ``summary_json`` are strings.

Duplicate keys
--------------

``mode`` accepts ``gate`` or ``audit``. ``normalize``, ``fuzzy``, and
``near_miss`` are booleans. Minimum line, token, and statement values are
positive integers. ``min_similarity`` accepts 0–1 or 0–100.
``max_near_miss_comparisons`` accepts 1–10,000,000.

``fail_on`` accepts ``error``, ``warning``, or ``info``.
``error_duplicate_percentage`` accepts 0–100. Baseline and cache file values
are paths. ``ignore_fingerprints`` is a list of fingerprint strings.

Output colors accept ``black``, ``red``, ``green``, ``yellow``, ``blue``,
``magenta``, ``cyan``, ``white``, ``gray``, or ``default``. Duplicate output
style accepts ``compact`` or ``classic``.

Comment keys
------------

The ``comments`` object accepts the common ``paths``, ``exclude``, ``format``,
``summary_json``, ``changed_only``, and ``changed_base`` keys. ``fail_on``
accepts ``error``, ``warning``, or ``info``. ``fail_confidence`` accepts
``low``, ``medium``, or ``high``. ``doc_mode`` accepts ``heuristic``,
``parser``, or ``hybrid``. ``doc_signature_consistency``,
``doc_type_hygiene``, ``explain``, and ``scan_markers`` are booleans.
``baseline`` and ``write_baseline`` are file paths.

``marker_tags`` is a list of non-empty tag names and ``marker_severity`` maps
tags to ``info``, ``low``, ``medium``, ``warning``, ``high``, ``critical``, or
``error``. ``custom_rules`` is a list whose entries require ``id`` and a valid
regular-expression ``pattern``; optional values are ``severity``, ``message``,
``enabled``, and ``scope`` (``all``, ``line``, ``block``, or ``doc``).

``doc_cache.enabled`` is boolean and ``doc_cache.file`` is a path. The
``rules`` object maps finding IDs to an object with optional ``enabled`` and
``severity`` overrides. An empty object means no per-rule overrides.

Commented-out-code keys
-----------------------

The ``commented_out_code`` object controls detection of dormant code snippets.
``policy`` accepts ``relaxed``, ``standard``, or ``strict``. Reason and example
tag values are lists of strings. ``min_reason_length``,
``max_allowed_block_lines``, and
``require_issue_for_blocks_longer_than`` are non-negative integers;
``allowed_issue_patterns`` is a list of valid regular expressions.

``suppression.directive`` is the literal marker used for scoped suppressions.
The single-line, block, and PHPDoc nested objects contain boolean attachment
rules. ``finding_severity`` and ``finding_severity_strict`` map finding IDs to
severity values. See :doc:`comments` for complete examples.

Path precedence
---------------

Explicit CLI paths replace configured paths. Repeated ``--exclude`` arguments
augment the selected configuration. In Git repositories, changed-only mode
combines the selected base diff with working-tree and untracked PHP files.
