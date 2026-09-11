Syntax checking
===============

PHPProbe invokes the current PHP binary with ``-l`` for every discovered PHP
file. This validates source with the same PHP runtime used to launch PHPProbe;
it does not emulate another PHP version.

.. code-block:: bash

   php vendor/bin/phpprobe syntax \
     --parallel=4 \
     --timeout=30 \
     --exclude=vendor \
     src tests

Execution model
---------------

The default is one lint process at a time. ``--parallel=N`` keeps at most N
child processes active, with a hard range of 1–64. Supplied paths are preserved
as work groups. PHPProbe takes one file from each group in turn, so
``--parallel=2 src tests`` starts work from both trees instead of exhausting
``src`` before reaching ``tests``. The worker limit is global; PHP's native
``-l`` command still receives one file per process.

Each child receives an argument-vector command rather than shell text. Output
is consumed while the process runs, every child has its own 0.1–600 second
timeout, and final failures are sorted by path for deterministic CI output.

``proc_open`` is required. A process that cannot start, times out, or exits
non-zero is a syntax failure; it is never treated as a clean file.

Discovery
---------

Files must use the ``.php`` extension. Explicit files and directories are
supported. Missing paths, unreadable inputs, and unsafe discovery conditions are
errors. Git repositories use tracked and untracked files; non-Git directory
walking is recursive and symlink-safe. Exclusions are path fragments.

``--changed-only`` combines committed changes against ``--changed-base`` with
working-tree and untracked PHP files. It is useful for fast feedback, but a full
periodic scan remains the authoritative repository gate.

Options
-------

``--parallel=N``
   Use 1–64 lint workers. Default: 1.

``--timeout=SECONDS``
   Stop one lint process after 0.1–600 seconds. Default: 30.

``--config``, ``--preset``, ``--exclude``, ``--format``, ``--color``,
``--summary-json``, ``--changed-only``, and ``--changed-base`` are described in
:doc:`cli-reference`.

Output contract
---------------

Text output uses CLI-safe tables containing a per-input-path summary and, when
needed, a diagnostic table with group, file, line, and message columns.

JSON output contains ``files_checked``, a ``failures`` list, and group totals.
Each failure has ``file`` and ``message``. Use ``--format=phpstan-json`` for the
PHPStan error-formatter shape: ``totals``, file-keyed ``messages``, and global
``errors``. Markdown, SARIF, GitHub annotations, and deterministic text output
represent the same result. Summary JSON is written atomically.

An empty but valid file set passes with zero files checked. Invalid
configuration or discovery/execution errors return exit code 2; one or more
lint failures return exit code 1.
