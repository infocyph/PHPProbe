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
``ci`` uses deterministic token thresholds and two syntax workers. ``strict``
uses the complete AST-backed audit with more sensitive thresholds.

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

Path precedence
---------------

Explicit CLI paths replace configured paths. Repeated ``--exclude`` arguments
augment the selected configuration. In Git repositories, changed-only mode
combines the selected base diff with working-tree and untracked PHP files.
