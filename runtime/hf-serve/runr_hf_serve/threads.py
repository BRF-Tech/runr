"""CPU threads of the inference libraries, sized to the container's CPU limit.

PyTorch, OpenMP and MKL size their thread pools from the CPUs the host has, not from the
container's CPU limit: a pod limited to one CPU on an 8-CPU node runs 8 threads that the CFS
quota throttles in most scheduling periods (slower and far more jittery than one thread). The
count is chosen once, at process start, before torch is imported:

1. ``OMP_NUM_THREADS`` when it is a positive integer (RunR sets it from the CPU limit of the
   model service; a user value on the service wins),
2. else ceil(CPU quota / period) of the container's cgroup — v2 ``cpu.max``, v1
   ``cpu.cfs_quota_us`` / ``cpu.cfs_period_us`` — capped at the CPUs the process sees,
3. else nothing is changed (no limit: the libraries' defaults).

With a count, ``OMP_NUM_THREADS`` and ``MKL_NUM_THREADS`` are set when they are unset, and
``torch.set_num_threads`` is called once torch is imported (``apply_torch``).
"""

from __future__ import annotations

import logging
import math
import os
from typing import Mapping, MutableMapping, Optional

log = logging.getLogger("runr_hf_serve")

CGROUP_ROOT = "/sys/fs/cgroup"

THREAD_ENV = ("OMP_NUM_THREADS", "MKL_NUM_THREADS")


def _positive_int(value: Optional[str]) -> Optional[int]:
    try:
        number = int(str(value).strip())
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


def _read(path: str) -> Optional[str]:
    try:
        with open(path, encoding="ascii", errors="replace") as handle:
            return handle.read().strip()
    except OSError:
        return None


def _ceil_ratio(quota: str, period: str) -> Optional[int]:
    try:
        q, p = float(quota), float(period)
    except (TypeError, ValueError):
        return None
    if q <= 0 or p <= 0 or math.isinf(q) or math.isnan(q) or math.isnan(p):
        return None
    return max(1, math.ceil(q / p - 1e-9))


def cgroup_cpu_limit(root: str = CGROUP_ROOT) -> Optional[int]:
    """CPUs the cgroup quota allows (rounded up), None without a quota or a readable cgroup."""
    v2 = _read(os.path.join(root, "cpu.max"))
    if v2 is not None:
        parts = v2.split()
        if len(parts) >= 1 and parts[0] == "max":
            return None
        if len(parts) == 2:
            return _ceil_ratio(parts[0], parts[1])
        return None

    for v1 in (os.path.join(root, "cpu"), os.path.join(root, "cpu,cpuacct"), root):
        quota = _read(os.path.join(v1, "cpu.cfs_quota_us"))
        period = _read(os.path.join(v1, "cpu.cfs_period_us"))
        if quota is not None and period is not None:
            return _ceil_ratio(quota, period)  # quota -1 = no limit → None
    return None


def thread_count(env: Optional[Mapping[str, str]] = None, root: str = CGROUP_ROOT, cpu_count: Optional[int] = None) -> Optional[int]:
    """The thread count for the inference libraries, None when nothing limits the container."""
    env = os.environ if env is None else env
    explicit = _positive_int(env.get("OMP_NUM_THREADS"))
    if explicit is not None:
        return explicit

    limit = cgroup_cpu_limit(root)
    if limit is None:
        return None
    available = cpu_count if cpu_count is not None else (os.cpu_count() or limit)
    return max(1, min(limit, available))


def configure(env: Optional[MutableMapping[str, str]] = None, root: str = CGROUP_ROOT, cpu_count: Optional[int] = None) -> Optional[int]:
    """Choose the count and export it to OpenMP / MKL (unset variables only). Call before torch is imported."""
    env = os.environ if env is None else env
    explicit = _positive_int(env.get("OMP_NUM_THREADS")) is not None
    count = thread_count(env, root, cpu_count)
    if count is None:
        log.info("CPU threads: no CPU limit found, library defaults")
        return None
    for name in THREAD_ENV:
        if _positive_int(env.get(name)) is None:
            env[name] = str(count)
    log.info("CPU threads: %d (from %s)", count, "OMP_NUM_THREADS" if explicit else "the cgroup CPU limit")
    return count


def apply_torch(count: Optional[int], torch_module=None) -> None:
    """torch.set_num_threads(count); a missing or broken torch is not an error."""
    if not count:
        return
    try:
        if torch_module is None:
            import torch as torch_module  # noqa: WPS433 (lazy import)
        torch_module.set_num_threads(int(count))
    except Exception as exc:  # torch absent (tests) or the call refused
        log.warning("torch.set_num_threads(%s) failed: %s", count, exc)
