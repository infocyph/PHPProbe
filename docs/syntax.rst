Syntax checking
===============

PHPProbe invokes the current PHP binary with ``-l`` for every discovered PHP
file. Parallel workers are bounded and failures are reported in deterministic
path order.

.. code-block:: bash

   php vendor/bin/phpprobe syntax \
     --parallel=4 \
     --timeout=30 \
     --exclude=vendor \
     src tests

Options
-------

``--parallel=N``
   Use between 1 and 64 lint workers. The default is 1.

``--timeout=SECONDS``
   Stop an individual lint process after 0.1 to 600 seconds. The default is 30.

``--changed-only`` and ``--changed-base=REF``
   Restrict discovery to committed changes, working-tree changes, and untracked
   PHP files.

``--format=FORMAT``
   Select ``text``, ``json``, ``markdown``, ``sarif``, or ``github``.

``--summary-json=FILE``
   Atomically write a compact machine-readable summary.

Failure behavior
----------------

Missing paths are errors. Process timeouts, output-limit failures, and disabled
``proc_open`` are reported rather than treated as clean scans.
