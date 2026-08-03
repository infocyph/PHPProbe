Comment policy
==============

The ``comments`` command enforces marker, dormant-code, PHPDoc, suppression,
and project-specific policies. PHPProbe reads PHP comment tokens, so comment-like
text inside strings is not treated as a comment.

Run the checker
---------------

.. code-block:: bash

   php vendor/bin/phpprobe comments src tests
   php vendor/bin/phpprobe comments --policy=strict --explain src
   php vendor/bin/phpprobe comments --format=sarif src > build/comments.sarif

``--fail-on`` selects the lowest severity group that fails the command:

* ``error`` fails on ``error`` and ``critical``;
* ``warning`` also fails on ``high`` and ``warning``;
* ``info`` fails on every severity, including ``medium``, ``low``, and ``info``.

``--fail-confidence=low|medium|high`` independently selects the minimum
confidence that can fail the command. Findings below either threshold remain in
normal output. ``--ci`` sets the failure and emitted-output threshold to
``error``; it does not change the configured policy.

Marker comments
---------------

Markers use ``TAG``, ``TAG: reason``, or ``TAG(scope): reason`` syntax:

.. code-block:: php

   // TODO(PROJ-142): remove the compatibility branch after client migration.
   // SECURITY(auth): rotate the legacy credential before enabling this path.

The default marker matrix is:

.. list-table::
   :header-rows: 1

   * - Severity
     - Tags
   * - ``critical``
     - ``SECURITY``
   * - ``high``
     - ``BUG``, ``FIXME``
   * - ``medium``
     - ``HACK``, ``XXX``, ``WARNING``
   * - ``low``
     - ``TODO``, ``OPTIMIZE``, ``REFACTOR``, ``DEPRECATED``
   * - ``info``
     - ``REVIEW``, ``QUESTION``, ``NOTE``

``comments.scan_markers`` can disable marker scanning. ``marker_tags`` replaces
the accepted list and ``marker_severity`` maps individual tags to severities.
The CLI ``--tags=TODO,FIXME,...`` replaces the tag list for one run.

Commented-out code
------------------

Code-like comments require a directly attached, meaningful tagged reason:

.. code-block:: php

   // TODO(PROJ-142): keep disabled until the upstream payload is versioned.
   // $payload = $legacyDecoder->decode($body);
   // return $payload->normalize();

The default required reason tags are ``TODO``, ``FIXME``, ``BUG``, ``HACK``,
``SECURITY``, ``REVIEW``, and ``DEPRECATED``. ``TEMP``, ``DEBUG``, and
``EXPERIMENTAL`` are optional tags in non-strict policy. Strict policy rejects
those optional tags unless ``allow_optional_reason_tags_in_strict_mode`` is
explicitly enabled.

The default issue patterns accept ``#123`` and identifiers such as
``PROJ-123``. A code block longer than the effective issue threshold must carry
one of the configured references. ``ignore_paths`` adds comment-only path
exclusions without changing syntax or duplicate discovery.

Policy profiles
---------------

Profiles enforce the following bounds after normal configuration is applied:

.. list-table::
   :header-rows: 1

   * - Policy
     - Minimum reason
     - Maximum block
     - Issue required above
     - Severity behavior
   * - ``relaxed``
     - At least 8 characters (12 with defaults)
     - At least 15 lines
     - At least 5 lines
     - Configured standard severities
   * - ``standard``
     - Configured value (default 12)
     - Configured value (default 10)
     - Configured value (default 3)
     - Configured standard severities
   * - ``strict``
     - At least 16 characters
     - At most 6 lines
     - At most 2 lines
     - Strict severity overrides

For example, relaxed policy does not lower an explicitly configured 20-character
minimum reason. ``--strict`` applies strict severities to the selected policy;
``--policy=strict`` applies both the strict bounds and strict severities.

Single-line reasons cannot have a blank line before the code by default. A
reason may precede a block comment, and one blank line may separate that reason
from the block. Each attachment behavior is independently configurable.

PHPDoc analysis
---------------

``doc_mode`` controls PHPDoc handling:

``heuristic``
   Uses comment-line heuristics only. This is the least expensive mode.

``parser``
   Parses PHPDoc structure. Malformed blocks receive safe fallback analysis.

``hybrid``
   Uses parsed structure when available and heuristic fallback where needed.
   This is the default.

``doc_signature_consistency`` detects missing, unknown, or mismatched
``@param`` entries and mismatched ``@return`` types. Valid refinements of a
native declaration remain compatible, including array shapes, generic arrays,
lists, callable signatures, scalar refinements, intersections, and nullable
unions composed from those types.
``doc_type_hygiene`` reports invalid PHPDoc tag values. Parsed results use a
content-addressed cache that is schema-checked before use and written atomically;
``comments.doc_cache`` controls that cache.

Documentation examples remain valid when introduced by a configured label:

.. code-block:: php

   /**
    * Usage:
    * $client->send($message);
    */

The default labels are ``Example:``, ``Examples:``, ``Usage:``, ``Snippet:``,
and ``Code sample:``. Set ``allow_documentation_examples`` to ``false`` to
apply normal dormant-code policy inside PHPDoc.

Built-in findings
-----------------

The default finding and severity matrix is:

.. list-table::
   :header-rows: 1
   :widths: 42 12 12 34

   * - Finding ID
     - Standard
     - Strict
     - Meaning
   * - ``comment_marker``
     - ``info``
     - unchanged
     - Configured marker tag was found.
   * - ``commented_out_code_without_reason``
     - ``warning``
     - ``error``
     - Code-like comment has no attached reason.
   * - ``commented_out_code_without_valid_tag``
     - ``warning``
     - ``error``
     - Reason tag is not permitted by policy.
   * - ``commented_out_code_without_valid_reason``
     - ``warning``
     - ``error``
     - Attached text is not a valid tagged reason.
   * - ``commented_out_code_with_weak_reason``
     - ``warning``
     - ``error``
     - Reason text is shorter than the effective minimum.
   * - ``commented_out_code_with_valid_reason``
     - ``info``
     - unchanged
     - Dormant code is retained with an accepted reason.
   * - ``commented_out_code_block_too_large``
     - ``error``
     - ``error``
     - Block exceeds the effective line limit.
   * - ``commented_out_code_requires_issue_reference``
     - ``warning``
     - unchanged
     - Large block lacks an accepted issue reference.
   * - ``commented_out_code_in_phpdoc_without_example_label``
     - ``warning``
     - unchanged
     - PHPDoc contains code without a configured example label.
   * - ``invalid_suppression_rule``
     - ``warning``
     - ``error``
     - Suppression syntax, rule, expiry, scope, or symbol is invalid.
   * - ``expired_suppression_rule``
     - ``warning``
     - ``error``
     - Suppression expiry is in the past.
   * - ``dead_suppression_rule``
     - ``warning``
     - ``error``
     - Suppression no longer matches a finding.
   * - ``phpdoc_signature_mismatch``
     - ``warning``
     - ``error``
     - PHPDoc type does not match the declaration.
   * - ``phpdoc_unknown_param``
     - ``warning``
     - ``error``
     - ``@param`` names a parameter that does not exist.
   * - ``phpdoc_missing_param``
     - ``info``
     - unchanged
     - Declaration parameter lacks a corresponding ``@param``.
   * - ``phpdoc_invalid_tag_value``
     - ``warning``
     - ``error``
     - PHPDoc tag value cannot be parsed safely.

Strict entries marked ``unchanged`` retain the standard or configured severity.
``finding_severity`` replaces standard defaults by ID and
``finding_severity_strict`` replaces strict overrides. ``comments.rules`` can
then disable any built-in/custom finding or override its final severity.

Suppressions
------------

Suppressions are explicit, rule-scoped, and auditable:

.. code-block:: php

   // @phpprobe-ignore commented_out_code_without_reason until=2026-12-31
   // $temporary = $legacyValue;

   // @phpprobe-ignore phpdoc_signature_mismatch scope=symbol symbol=OrderService::send

The directive accepts one or more comma-separated rule IDs, or ``*``. The
optional ``until=YYYY-MM-DD`` adds an expiry. ``scope=symbol`` confines the
suppression to the resolved function, method, class, interface, trait, or enum;
``symbol=Name`` can select it explicitly. Unknown rules/options, invalid or
expired dates, unresolved symbols, and unused suppressions are findings.

``commented_out_code.suppression.enabled`` disables directive handling and
``directive`` changes the literal directive prefix. Named, expiring
suppressions are preferable to ``*``.

Custom rules
------------

Custom rules match normalized comment content. Only ``id`` and a valid PHP
regular-expression ``pattern`` are required:

.. code-block:: json

   {
     "comments": {
       "custom_rules": [
         {
           "id": "no_credentials",
           "pattern": "/password\\s*[:=]/i"
         },
         {
           "id": "no_internal_urls",
           "pattern": "~https?://[^ ]+\\.internal~i",
           "severity": "high",
           "message": "Do not publish internal URLs in comments.",
           "enabled": true,
           "scope": "line"
         }
       ]
     }
   }

Optional defaults are ``severity=warning``, ``enabled=true``, ``scope=all``,
and ``message=Matched custom comment rule \"<id>\".``. Scope accepts ``all``,
``line``, ``block``, or ``doc``. Findings use the ID
``custom_rule_<configured-id>`` with non-alphanumeric characters normalized to
underscores, and can be overridden through ``comments.rules``.

Baselines and changed files
---------------------------

Baselines store stable finding fingerprints and are useful when adopting the
checker in an existing codebase:

.. code-block:: bash

   php vendor/bin/phpprobe comments \
     --write-baseline=.phpprobe-comments-baseline.json src
   php vendor/bin/phpprobe comments \
     --baseline=.phpprobe-comments-baseline.json src

Writing a baseline exits successfully after recording the current findings.
``--changed-only --changed-base=origin/main`` limits discovery to changed PHP
files. Periodic full scans remain necessary for repository-wide coverage.
