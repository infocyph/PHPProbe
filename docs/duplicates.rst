Duplicate-code detection
========================

PHPProbe combines token fingerprints with AST-backed statement and structural
analysis. It verifies rolling-hash candidates before reporting them, merges
equivalent occurrences, removes contained clone groups, and counts unique
duplicated lines rather than summing overlapping reports.

Detector families
-----------------

.. list-table::
   :header-rows: 1

   * - Result source
     - Engine
     - Enabled when
   * - ``tokens``
     - Extended rolling token windows
     - Always
   * - ``statements``
     - Exact normalized AST-statement windows
     - ``mode=audit``
   * - ``near_miss``
     - Weighted statement-sequence and AST-shape similarity
     - Audit mode or ``near_miss=true``

AST blocks cover functions, methods, closures, arrow functions, loops,
branches/match arms, and try/catch/finally structures. Near-miss candidates are
compared only within the same block family. Length bounds reject impossible
matches before the more expensive sequence comparison.

Modes
-----

``gate`` is the low-overhead deterministic default. It builds token streams and
does not construct an AST unless near-miss analysis is explicitly enabled.

``audit`` constructs an AST and enables token, statement, and bounded near-miss
detectors. The recommended ``standard`` preset uses audit mode, so the complete
detector matrix is active.

.. code-block:: bash

   php vendor/bin/phpprobe duplicates \
     --mode=gate --min-lines=5 --min-tokens=90 src

   php vendor/bin/phpprobe duplicates \
     --mode=audit \
     --min-statements=4 \
     --min-similarity=0.88 \
     --max-near-miss-comparisons=100000 \
     src

Normalization
-------------

With default normalization, variables become ``VAR``, numbers become ``NUM``,
and string literals become ``STR``. ``--exact`` preserves original token values.
``--fuzzy`` additionally normalizes names and identifiers, allowing equivalent
logic with renamed calls/types to match. ``--no-fuzzy`` is useful when a preset
enabled fuzzy matching but one run should not.

Whitespace and comments do not enter token fingerprints. The AST shape also
normalizes variables, names, identifiers, strings, numbers, booleans, and null
while preserving executable node structure.

Imports are not duplicated behavior
-----------------------------------

Top-level namespace imports are excluded from token fingerprints. Reusing the
same imports across files is expected dependency declaration:

.. code-block:: php

   <?php

   use Psr\Log\LoggerInterface;
   use Vendor\Package\Clock;

Only top-level import declarations are skipped. Trait ``use`` statements and
closure capture clauses remain in analysis because they affect executable
behavior. Code after an import block is checked normally.

Thresholds and safety bounds
----------------------------

``min_lines`` applies to every occurrence. ``min_tokens`` controls the rolling
token window; matching windows are extended to the complete equal sequence.
``min_statements`` controls statement windows and the minimum AST block size for
near-miss candidates.

Near-miss similarity combines statement-sequence similarity at 72% and AST
shape similarity at 28%. ``min_similarity`` accepts 0–1, or 0–100 on the CLI.
``max_near_miss_comparisons`` is a hard operational ceiling. Exceeding it is an
error with guidance to narrow discovery, raise similarity, or explicitly raise
the limit; the accepted maximum is 10,000,000.

Failure policy
--------------

With ``fail_on=warning`` or ``info``, any new clone group fails. With
``fail_on=error``, clones fail only when their unique duplicated lines reach
``error_duplicate_percentage`` of all scanned lines. The default percentage is
20. A scan with no remaining clone groups always passes.

Baselines and direct ignores
----------------------------

.. code-block:: bash

   php vendor/bin/phpprobe duplicates \
     --write-baseline=.phpprobe-duplicates-baseline.json src

   php vendor/bin/phpprobe duplicates \
     --baseline=.phpprobe-duplicates-baseline.json src

Baselines store stable clone fingerprints and detector metadata. Writing one
returns success. ``duplicates.ignore_fingerprints`` can suppress a small known
set directly in configuration. After suppression, duplicate lines and failure
percentages are recalculated from the remaining new groups.

Cache
-----

The result cache key includes detector options plus hashes of file paths and
contents; timestamps and sizes alone are not trusted. Cache payloads are
versioned, schema-validated, size-bounded, and written atomically. Malformed,
incompatible, or oversized entries are ignored safely. The default file is
project-scoped in the system temporary directory. Use ``--no-cache`` or
``duplicates.cache.enabled=false`` to disable it.

Output contract
---------------

JSON reports include file/line totals, unique duplicated lines and percentage,
known/new clone counts, cache status, and clone groups. Every group exposes a
stable fingerprint, detector source, score, similarity, token/line/statement
counts, block type, and sorted occurrences with file, range, and context.

Text output offers compact/classic layouts and configurable score bands.
Markdown, SARIF, GitHub annotation, and atomic summary JSON outputs represent
the same scan. See :doc:`configuration` and :doc:`cli-reference` for every
setting and option.
