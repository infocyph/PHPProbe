Code graph
==========

PHPProbe extracts a deterministic, PHP-only source graph without model calls,
network access, reflection discovery, or execution of analyzed files.

.. code-block:: bash

   php vendor/bin/phpprobe graph \
     --root=. \
     --pretty \
     --output=build/code-graph.json \
     src tests

Contract
--------

The top-level document contains ``schema``, ``schema_version``, ``root``,
``files_scanned``, ``nodes``, and ``edges``. Schema version 1 uses the name
``phpprobe.code-graph``.

Every node contains:

* a stable, type-prefixed ``id``;
* ``type`` and ``name``;
* repository-relative ``file``, ``line``, and ``end_line`` provenance;
* ``defined`` to distinguish scanned declarations from referenced targets;
* deterministic structural ``attributes``.

Every edge contains ``source``, ``target``, ``relation``, ``file``, ``line``,
``certainty``, and ``resolution``. Structural edges always use
``certainty=extracted``. Exact targets use ``resolution=exact``. Calls whose
receiver type cannot be proven use ``dynamic``. Unresolved unqualified
function calls in namespaces use ``namespace_fallback`` to preserve PHP's
runtime lookup semantics without inventing a target.

Responsibilities
----------------

PHPProbe owns only deterministic PHP parsing and normalized extracted facts.
Incremental manifests, graph traversal, community detection, visualization,
natural-language querying, and model-generated summaries belong to consumers
such as PHPForge. Those consumers should store model output as inferred data
and must not overwrite PHPProbe's extracted nodes or edges.
