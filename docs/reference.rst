Reference integrity
===================

The reference checker performs static, framework-independent validation of
class-like PHP names:

.. code-block:: bash

   php vendor/bin/phpprobe reference src tests

It indexes named classes, interfaces, traits, and enums in the scan paths. It
also reads the project's Composer PSR-4 mappings, Composer's installed
classmap/PSR-4 metadata, and PHP's native class-like symbols.

Required PHP extensions
-----------------------

The checker reads ``ext-*`` packages from the project's Composer ``require``
and ``require-dev`` sections. Each requirement is compared with the extensions
loaded by the PHP runtime running PHPProbe. A missing requirement, such as
``ext-swoole``, is emitted as a certain error at its ``composer.json`` line with
an install-or-enable suggestion.

This check uses the real runtime rather than Composer's simulated
``config.platform`` values. Consequently, installing dependencies with an
ignored platform requirement does not make the reference check pass.

Checked references
------------------

PHPProbe checks inheritance, implemented interfaces, trait use, attributes,
parameter/property/return/class-constant types, ``new``, ``instanceof``, catch
types, static calls, static properties, and class constants. Dynamic class-name
strings and service-container aliases are not treated as confirmed errors.

Composer PSR-4 locations
------------------------

For project PSR-4 roots, the declared FQCN is compared with the source path. A
file at ``src/Billing/Payment.php`` under ``"App\\": "src/"`` is expected to
declare ``App\\Billing\\Payment``. This detects a file move whose namespace was
not updated.

Solutions and confidence
------------------------

Unknown symbols are compared with indexed project and dependency symbols.
Results carry ranked candidates and one of these confidence values:

``certain``
   One replacement is a strong, unambiguous match.

``possible``
   Candidate replacements exist but require review.

``dead``
   No credible replacement was found; the reference may target removed code.

Output
------

Text output uses a CI-safe table. Native JSON retains candidates, confidence,
and required-extension totals;
``phpstan-json``, Markdown, SARIF, GitHub annotations, and summary JSON are also
available through the common checker options.
