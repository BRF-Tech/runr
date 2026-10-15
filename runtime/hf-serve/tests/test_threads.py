import logging
import types

import pytest

from runr_hf_serve import threads


def cgroup_v2(tmp_path, cpu_max):
    (tmp_path / "cpu.max").write_text(cpu_max + "\n")
    return str(tmp_path)


def cgroup_v1(tmp_path, quota, period, folder="cpu"):
    base = tmp_path / folder
    base.mkdir()
    (base / "cpu.cfs_quota_us").write_text(quota + "\n")
    (base / "cpu.cfs_period_us").write_text(period + "\n")
    return str(tmp_path)


@pytest.mark.parametrize("cpu_max, expected", [
    ("100000 100000", 1),
    ("150000 100000", 2),   # 1.5 CPUs → 2 threads
    ("50000 100000", 1),    # 500m → at least one
    ("400000 100000", 4),
    ("max 100000", None),   # no limit
    ("max", None),
    ("garbage", None),
    ("abc 100000", None),
    ("-1 100000", None),
    ("100000 0", None),
])
def test_cgroup_v2(tmp_path, cpu_max, expected):
    assert threads.cgroup_cpu_limit(cgroup_v2(tmp_path, cpu_max)) == expected


@pytest.mark.parametrize("quota, period, expected", [
    ("200000", "100000", 2),
    ("250000", "100000", 3),
    ("-1", "100000", None),
    ("x", "100000", None),
])
def test_cgroup_v1(tmp_path, quota, period, expected):
    assert threads.cgroup_cpu_limit(cgroup_v1(tmp_path, quota, period)) == expected


def test_cgroup_v1_combined_controller_folder(tmp_path):
    assert threads.cgroup_cpu_limit(cgroup_v1(tmp_path, "100000", "100000", folder="cpu,cpuacct")) == 1


def test_no_cgroup_files_means_no_limit(tmp_path):
    assert threads.cgroup_cpu_limit(str(tmp_path / "missing")) is None


def test_the_environment_wins_over_the_cgroup(tmp_path):
    root = cgroup_v2(tmp_path, "100000 100000")
    assert threads.thread_count({"OMP_NUM_THREADS": "3"}, root, cpu_count=8) == 3


@pytest.mark.parametrize("value", ["0", "-2", "two", "", " "])
def test_invalid_environment_values_fall_back_to_the_cgroup(tmp_path, value):
    root = cgroup_v2(tmp_path, "200000 100000")
    assert threads.thread_count({"OMP_NUM_THREADS": value}, root, cpu_count=8) == 2


def test_the_limit_is_capped_at_the_visible_cpus(tmp_path):
    root = cgroup_v2(tmp_path, "1600000 100000")
    assert threads.thread_count({}, root, cpu_count=4) == 4


def test_configure_exports_the_count_to_openmp_and_mkl(tmp_path, caplog):
    env = {}
    with caplog.at_level(logging.INFO, logger="runr_hf_serve"):
        count = threads.configure(env, cgroup_v2(tmp_path, "150000 100000"), cpu_count=8)
    assert count == 2
    assert env == {"OMP_NUM_THREADS": "2", "MKL_NUM_THREADS": "2"}
    assert "CPU threads: 2 (from the cgroup CPU limit)" in caplog.text


def test_configure_keeps_values_that_are_set(tmp_path, caplog):
    env = {"OMP_NUM_THREADS": "1", "MKL_NUM_THREADS": "2"}
    with caplog.at_level(logging.INFO, logger="runr_hf_serve"):
        assert threads.configure(env, cgroup_v2(tmp_path, "400000 100000"), cpu_count=8) == 1
    assert env == {"OMP_NUM_THREADS": "1", "MKL_NUM_THREADS": "2"}
    assert "(from OMP_NUM_THREADS)" in caplog.text


def test_configure_without_a_limit_changes_nothing(tmp_path):
    env = {}
    assert threads.configure(env, cgroup_v2(tmp_path, "max 100000"), cpu_count=8) is None
    assert env == {}


def test_apply_torch_sets_the_intra_op_threads():
    calls = []
    fake = types.SimpleNamespace(set_num_threads=calls.append)
    threads.apply_torch(3, fake)
    threads.apply_torch(None, fake)
    assert calls == [3]


def test_apply_torch_tolerates_a_refusing_torch(caplog):
    def refuse(_n):
        raise RuntimeError("already started")

    threads.apply_torch(2, types.SimpleNamespace(set_num_threads=refuse))
    assert "set_num_threads(2) failed" in caplog.text
