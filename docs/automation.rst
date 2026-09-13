Automation and reports
======================

Combined reports
----------------

.. code-block:: bash

   php vendor/bin/phpprobe check \
     --preset=ci \
     --summary-json=build/phpprobe-summary.json \
     --report-dir=build/phpprobe \
     src tests

The report directory contains syntax, reference, duplicate, and comment checker JSON,
a Markdown summary, SARIF, and a combined JSON summary. Report files are
written atomically.

GitHub Actions
--------------

.. code-block:: yaml

   name: PHPProbe

   on:
     pull_request:
     push:
       branches: [main]

   jobs:
     quality:
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v6
         - uses: shivammathur/setup-php@v2
           with:
             php-version: "8.4"
             coverage: none
         - run: composer install --prefer-dist --no-interaction
         - run: >-
             php vendor/bin/phpprobe check
             --preset=ci
             --format=github
             --report-dir=build/phpprobe
             src tests
         - if: always()
           uses: actions/upload-artifact@v7
           with:
             name: phpprobe-reports
             path: build/phpprobe/

Changed-file checks
-------------------

.. code-block:: bash

   php vendor/bin/phpprobe check \
     --changed-only \
     --changed-base=origin/main \
     --format=github

Periodic full scans remain recommended because changed-only analysis cannot
discover every relationship between unchanged files.
