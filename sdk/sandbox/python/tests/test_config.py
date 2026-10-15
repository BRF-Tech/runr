"""Configuration from arguments and environment variables."""

import pytest

from runr_sandbox import RunrSandboxError, Sandbox, resolve_config
from runr_sandbox.config import api_base_url


@pytest.mark.parametrize(
    "url, expected",
    [
        ("https://runr.example.com", "https://runr.example.com/api"),
        ("https://runr.example.com/", "https://runr.example.com/api"),
        ("https://runr.example.com/api", "https://runr.example.com/api"),
        ("https://runr.example.com/api/", "https://runr.example.com/api"),
        ("https://example.com/runr", "https://example.com/runr/api"),
    ],
)
def test_the_api_suffix(url, expected):
    assert api_base_url(url) == expected


def test_environment_variables(monkeypatch):
    monkeypatch.setenv("RUNR_API_URL", "https://runr.example.com")
    monkeypatch.setenv("RUNR_API_KEY", "env-key")
    monkeypatch.setenv("RUNR_TENANT_ID", "tenant-7")
    config = resolve_config()
    assert config.api_url == "https://runr.example.com/api"
    headers = config.headers()
    assert headers["Authorization"] == "Bearer env-key"
    assert headers["X-Tenant-ID"] == "tenant-7"
    assert headers["Accept"] == "application/json"


def test_arguments_win_over_the_environment(monkeypatch):
    monkeypatch.setenv("RUNR_API_URL", "https://env.example.com")
    monkeypatch.setenv("RUNR_API_KEY", "env-key")
    config = resolve_config(api_url="https://arg.example.com", api_key="arg-key", timeout=5)
    assert config.api_url == "https://arg.example.com/api"
    assert config.headers()["Authorization"] == "Bearer arg-key"
    assert config.timeout == 5.0
    assert "X-Tenant-ID" not in config.headers()


def test_a_call_without_a_url_is_refused():
    with pytest.raises(RunrSandboxError) as caught:
        resolve_config()
    assert "RUNR_API_URL" in caught.value.message


def test_the_client_uses_the_environment(server, monkeypatch):
    monkeypatch.setenv("RUNR_API_URL", server.url)
    monkeypatch.setenv("RUNR_API_KEY", server.token)
    monkeypatch.setenv("RUNR_TENANT_ID", "tenant-9")
    with Sandbox.create() as sandbox:
        assert sandbox.status == "running"
    request = server.state.calls("POST", r"^/api/sandboxes$")[0]
    assert request["headers"]["x-tenant-id"] == "tenant-9"
