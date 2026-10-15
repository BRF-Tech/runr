from typing import Dict, Iterator

import pytest

from fake_server import FakeServer


@pytest.fixture
def server() -> Iterator[FakeServer]:
    with FakeServer() as srv:
        yield srv


@pytest.fixture
def opts(server: FakeServer) -> Dict[str, str]:
    """Client options pointing at the fake API."""
    return {"api_url": server.url, "api_key": server.token}


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in ("RUNR_API_URL", "RUNR_API_KEY", "RUNR_TENANT_ID"):
        monkeypatch.delenv(name, raising=False)
