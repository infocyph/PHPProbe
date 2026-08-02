PHPProbe
========

PHPProbe is a framework-independent PHP syntax, duplicate-code, and comment-policy
quality gate. It is designed for local development, continuous integration, and
direct use by other Composer packages.

.. toctree::
   :maxdepth: 2
   :caption: Guide

   getting-started
   syntax
   duplicates
   comments
   configuration
   automation

Design guarantees
-----------------

* Invalid source is rejected before duplicate and comment analysis begins.
* Invalid configuration, missing paths, and unsafe limits fail closed.
* Gate-mode duplicate analysis avoids constructing an AST.
* Structural comparisons have an explicit upper bound.
* File discovery and output ordering are deterministic.
* Cache entries are content-addressed, validated, bounded, and atomically written.
* Comment parsing degrades safely from parser-backed analysis to heuristics.
