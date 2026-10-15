"""Mapping of API error responses to exception classes, and request body helpers."""

import pytest

from runr_sandbox import (
    AuthError,
    ConflictError,
    NotFoundError,
    PayloadTooLargeError,
    QuotaError,
    RateLimitError,
    RunrSandboxError,
    ValidationError,
)
from runr_sandbox import _api
from runr_sandbox.errors import error_from_payload
from runr_sandbox.models import ExecResult


@pytest.mark.parametrize(
    "status, body, cls",
    [
        (401, {"message": "Unauthenticated."}, AuthError),
        (403, {"message": "This action is unauthorized."}, AuthError),
        (404, {"message": "Not found."}, NotFoundError),
        (409, {"message": "Not running.", "reason": "not_running", "status": "terminated"}, ConflictError),
        (413, {"message": "Too large."}, PayloadTooLargeError),
        (422, {"message": "Quota.", "reason": "quota_cpu"}, QuotaError),
        (422, {"message": "Invalid.", "errors": {"cpu": ["bad"]}}, ValidationError),
        (429, {"message": "Too Many Attempts."}, RateLimitError),
        (502, {"message": "Cluster unreachable.", "reason": "cluster_error"}, RunrSandboxError),
    ],
)
def test_status_mapping(status, body, cls):
    error = error_from_payload(status, body)
    assert type(error) is cls
    assert error.status == status
    assert error.message == body["message"]
    assert error.body == body


def test_reason_and_string_form():
    error = error_from_payload(422, {"message": "Concurrent limit.", "reason": "quota_concurrent"})
    assert error.reason == "quota_concurrent"
    assert str(error) == "[HTTP 422, quota_concurrent] Concurrent limit."


def test_413_defaults_its_reason():
    assert error_from_payload(413, {"message": "x"}).reason == "file_too_large"


def test_a_text_body_becomes_the_message():
    error = error_from_payload(502, None, "<html>Bad Gateway</html>")
    assert error.message == "<html>Bad Gateway</html>"
    assert error_from_payload(500, None, "").message == "HTTP 500"


def test_retry_after():
    assert error_from_payload(429, {"message": "slow down"}, retry_after="7").retry_after == 7.0
    assert error_from_payload(429, {"message": "slow down"}, retry_after="soon").retry_after is None


def test_exec_result_from_base64_fields():
    result = ExecResult.from_api({"exit_code": 0, "stdout": "", "stdout_base64": "/w==", "stderr": "e", "duration_ms": 3})
    assert result.stdout_bytes == b"\xff" and result.stderr == "e" and result.ok


def test_file_and_mode_normalization():
    assert _api.normalize_files({"/a": "x"}) == [{"path": "/a", "content_base64": "eA=="}]
    assert _api.normalize_files([{"path": "/b", "content": b"y", "mode": 0o755}]) == [
        {"path": "/b", "content_base64": "eQ==", "mode": "0755"},
    ]
    assert _api._mode("755") == "0755" and _api._mode("0644") == "0644"
    assert _api.normalize_network(("10.0.0.0/8",)) == {"egress_allow": ["10.0.0.0/8"]}
    assert _api.normalize_network({"egress_allow": []}) == {"egress_allow": []}


def test_list_params():
    params = _api.list_params(status="all", metadata={"agent": "x"}, all_tenants=True, per_page=10)
    assert params == {"status": "all", "per_page": 10, "all_tenants": 1, "metadata[agent]": "x"}


def test_http_timeout_outlasts_the_command():
    assert _api.http_timeout(300.0, None) == 300.0
    assert _api.http_timeout(300.0, 600) == 660.0
