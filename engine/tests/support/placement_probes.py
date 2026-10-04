"""Picklable probes for the ENG-49 child-process placement tests.

They live in the ``tests.support`` package (not inline in the test module) so a
``spawn`` child can import them by qualified name — the exact mechanism the real
placement child uses to re-import ``solve_match_placement``. A function defined in
a test file picked up in pytest's prepend import mode would have an ambiguous
``__module__`` from the child's point of view; a package module never does.
"""

from __future__ import annotations

import os


def probe_pid(_arg: object = None) -> int:
    """Return the PID of the process that runs it — different from the parent iff
    it truly executed in a child process."""
    return os.getpid()


def probe_raise(_arg: object = None) -> None:
    """Raise a plain exception so the parent can assert it is re-raised verbatim
    (exception dans le fils → même remontée qu'avant)."""
    raise ValueError("boom from placement child")


def probe_hard_exit(_arg: object = None) -> None:
    """Kill the child abruptly (as an OOM kill would), breaking the pool so the
    recovery path can be exercised."""
    os._exit(1)
