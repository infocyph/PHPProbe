Comment policy
==============

The ``comments`` command detects policy markers, code hidden in comments,
PHPDoc drift, invalid suppressions, and project-specific patterns. It scans PHP
tokens rather than arbitrary text, so strings containing comment-like text are
not reported.

Run the checker
---------------

.. code-block:: bash

   php vendor/bin/phpprobe comments src tests
   php vendor/bin/phpprobe comments --strict --explain src
   php vendor/bin/phpprobe comments --format=sarif src > build/comments.sarif

``--fail-on`` selects the lowest failing severity. ``--fail-confidence`` can
exclude lower-confidence heuristic findings from the exit decision without
hiding them. ``--ci`` is shorthand for error-only emission and failure.

Markers
-------

Markers use ``TAG``, ``TAG: message``, or ``TAG(scope): message`` syntax:

.. code-block:: php

   // TODO(PROJ-142): remove the compatibility branch after client migration.
   // SECURITY(auth): rotate the legacy credential before enabling this path.

Configure accepted tags and their severity in ``comments``:

.. code-block:: json

   {
     "comments": {
       "scan_markers": true,
       "marker_tags": ["TODO", "FIXME", "SECURITY"],
       "marker_severity": {
         "TODO": "low",
         "FIXME": "high",
         "SECURITY": "critical"
       }
     }
   }

Commented-out code
------------------

Code-like lines need a directly attached, meaningful tagged reason. Larger
blocks can additionally require an issue reference.

.. code-block:: php

   // TODO(PROJ-142): keep disabled until the upstream payload is versioned.
   // $payload = $legacyDecoder->decode($body);
   // return $payload->normalize();

PHPDoc examples remain valid when introduced by a configured example label:

.. code-block:: php

   /**
    * Usage:
    * $client->send($message);
    */

The ``relaxed``, ``standard``, and ``strict`` policies adjust reason length,
block size, required issue references, and effective severities. Explicit
configuration remains available under ``commented_out_code``.

PHPDoc analysis
---------------

``doc_mode`` controls PHPDoc handling:

``heuristic``
   Uses comment-line heuristics only. This is the least expensive mode.

``parser``
   Parses PHPDoc structure and reports fallback analysis when a block is
   malformed.

``hybrid``
   Uses parsed structure where available and safe heuristic fallback elsewhere.
   This is the default.

``doc_signature_consistency`` detects missing, unknown, or mismatched
``@param`` and ``@return`` types. ``doc_type_hygiene`` reports malformed PHPDoc
tag values. Parsed results use a content-addressed cache that is schema-checked
before use and written atomically.

Suppressions
------------

Suppressions are explicit, rule-scoped, and auditable:

.. code-block:: php

   // @phpprobe-ignore commented_out_code_without_reason until=2026-12-31
   // $temporary = $legacyValue;

   // @phpprobe-ignore phpdoc_signature_mismatch scope=symbol symbol=OrderService::send

Unknown rules, invalid dates, expired suppressions, unresolved symbols, and
suppressions that match no active finding are reported. ``*`` can suppress all
rules in the selected range, but named rules are preferable.

Custom rules
------------

Custom rules match normalized comment lines. Each rule has a stable ID, regular
expression, severity, message, enabled flag, and scope.

.. code-block:: json

   {
     "comments": {
       "custom_rules": [
         {
           "id": "no_credentials",
           "pattern": "/password\\s*[:=]/i",
           "severity": "critical",
           "message": "A comment appears to contain a credential.",
           "enabled": true,
           "scope": "all"
         }
       ]
     }
   }

Per-rule overrides
------------------

Built-in and custom findings can be disabled or assigned a different severity:

.. code-block:: json

   {
     "comments": {
       "rules": {
         "comment_marker": {"severity": "warning"},
         "phpdoc_missing_param": {"enabled": false}
       }
     }
   }

Baselines and changed files
---------------------------

Baselines contain stable finding fingerprints and are useful when adopting the
checker in an existing codebase:

.. code-block:: bash

   php vendor/bin/phpprobe comments \
     --write-baseline=.phpprobe-comments-baseline.json src
   php vendor/bin/phpprobe comments \
     --baseline=.phpprobe-comments-baseline.json src

``--changed-only --changed-base=origin/main`` limits discovery to changed PHP
files. Periodic full scans are still recommended for repository-wide policy
coverage.
