Getting started
===============

Requirements
------------

PHPProbe supports PHP 8.4 and newer. It requires Composer, the tokenizer
extension, and ``proc_open`` for syntax lint processes.

Installation
------------

.. code-block:: bash

   composer require --dev infocyph/phpprobe
   php vendor/bin/phpprobe init --preset=standard --with-ci

The initializer writes a minimal ``phpprobe.json``. Existing files are
preserved unless ``--force`` is supplied.

First run
---------

.. code-block:: bash

   php vendor/bin/phpprobe syntax src tests
   php vendor/bin/phpprobe duplicates src
   php vendor/bin/phpprobe comments src tests
   php vendor/bin/phpprobe check src tests

The combined command runs syntax first and skips duplicate and comment analysis
when any file is invalid.

Operational checks
------------------

.. code-block:: bash

   php vendor/bin/phpprobe config validate phpprobe.json
   php vendor/bin/phpprobe doctor --config=phpprobe.json
   php vendor/bin/phpprobe presets
   php vendor/bin/phpprobe preset ci

Exit codes
----------

``0`` means the gate passed, ``1`` means findings crossed the configured
threshold, and ``2`` identifies a usage, configuration, environment, I/O, or
execution error.
