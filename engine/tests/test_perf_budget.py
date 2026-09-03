"""Unit coverage for the perf-gate budget knob — NON-perf, runs in the default suite.

The perf tests themselves are excluded by default (``-m 'not perf'``); this file is
not marked, so the ``_budget_seconds`` contract (env override + validation) is
exercised on every ``make test`` without paying a solve. It is the guard for the
PR-tier gate wiring (P4-167 / decision C2): ``main`` keeps 60 s, a PR may raise the
budget through ``PERF_BUDGET_SECONDS`` and a malformed value fails loudly.
"""

from __future__ import annotations

import pytest

from tests.perf.test_perf_dense import _budget_seconds


def test_budget_defaults_to_60_when_env_absent(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("PERF_BUDGET_SECONDS", raising=False)
    assert _budget_seconds() == 60.0


def test_budget_reads_env_override(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PERF_BUDGET_SECONDS", "90")
    assert _budget_seconds() == 90.0


def test_budget_reads_fractional_env_override(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PERF_BUDGET_SECONDS", "45.5")
    assert _budget_seconds() == 45.5


def test_budget_rejects_non_numeric(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PERF_BUDGET_SECONDS", "soon")
    with pytest.raises(ValueError):
        _budget_seconds()


def test_budget_rejects_non_positive(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("PERF_BUDGET_SECONDS", "0")
    with pytest.raises(ValueError):
        _budget_seconds()
