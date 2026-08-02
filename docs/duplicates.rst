Duplicate-code detection
========================

Modes
-----

``gate`` is the fast, deterministic engine default. It tokenizes source and
uses a rolling window hash, verifying candidate windows before emitting clones.
``audit`` additionally constructs an AST and enables statement, structural,
and bounded near-miss matching. The recommended ``standard`` preset uses
``audit`` so all detector families remain active. AST construction is skipped
only when the selected configuration enables no AST-backed detector.

.. code-block:: bash

   php vendor/bin/phpprobe duplicates --mode=gate --min-tokens=90 src

   php vendor/bin/phpprobe duplicates \
     --mode=audit \
     --near-miss \
     --min-similarity=0.88 \
     --max-near-miss-comparisons=100000 \
     src

Imports are not duplicated behavior
-----------------------------------

Top-level namespace imports are excluded from token fingerprints. It is normal
for many files to contain the same declarations:

.. code-block:: php

   <?php

   use Psr\Log\LoggerInterface;
   use Vendor\Package\Clock;

Only import declarations are skipped. Trait ``use`` statements and closure
capture clauses remain analyzable because they affect program structure.
Executable code following an import block is still checked normally.

Normalization
-------------

Default normalization replaces variables and literals. ``--exact`` preserves
their original values. ``--fuzzy`` also normalizes identifiers and calls.
``--near-miss`` compares statement and AST shapes, subject to the configured
hard comparison ceiling.

Thresholds
----------

``--min-lines=N``
   Minimum physical lines in a reported occurrence.

``--min-tokens=N``
   Rolling token-window width.

``--min-statements=N``
   Statement-window width in audit mode.

``--min-similarity=N``
   Near-miss threshold expressed as 0–1 or 0–100.

``--max-near-miss-comparisons=N``
   Maximum structural candidate comparisons, up to 10,000,000.

Baselines and cache
-------------------

.. code-block:: bash

   php vendor/bin/phpprobe duplicates \
     --write-baseline=.phpprobe-duplicates-baseline.json src

   php vendor/bin/phpprobe duplicates \
     --baseline=.phpprobe-duplicates-baseline.json src

Fingerprints remain stable when code moves between line numbers. The optional
cache uses source-content hashes rather than file size and modification time.
Malformed, incompatible, or oversized cache entries are ignored. ``--no-cache``
disables caching.
