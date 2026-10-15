"""HTTP layer (httpx) of the sync and the async client."""

from __future__ import annotations

import contextlib
from typing import Any, AsyncIterator, Dict, Iterator, Mapping, Optional

import httpx

from .config import ClientConfig
from .errors import RunrSandboxError, error_from_payload


def _raise_for(response: httpx.Response) -> None:
    if response.status_code < 400:
        return
    try:
        body: Any = response.json()
    except ValueError:
        body = None
    text = ""
    if body is None:
        try:
            text = response.text
        except Exception:  # pragma: no cover - undecodable body
            text = ""
    raise error_from_payload(response.status_code, body, text, response.headers.get("Retry-After"))


def _json(response: httpx.Response) -> Any:
    if not response.content:
        return {}
    try:
        return response.json()
    except ValueError as exc:
        raise RunrSandboxError(response.status_code, "The RunR API answered with a body that is not JSON.", "bad_response", response.text) from exc


def _stream_timeout(base: float) -> httpx.Timeout:
    # Streams stay open as long as the command runs; the API sends a comment line every 15 s
    return httpx.Timeout(base, read=None)


class SyncTransport:
    """Blocking requests against one RunR API."""

    def __init__(self, config: ClientConfig, http_client: Optional[httpx.Client] = None) -> None:
        self.config = config
        self._owns_client = http_client is None
        self._client = http_client or httpx.Client(timeout=config.timeout, follow_redirects=False)

    def _headers(self, extra: Optional[Mapping[str, str]]) -> Dict[str, str]:
        headers = self.config.headers()
        if extra:
            headers.update(extra)
        return headers

    def request(
        self,
        method: str,
        path: str,
        *,
        json: Any = None,
        params: Optional[Mapping[str, Any]] = None,
        content: Optional[bytes] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Optional[float] = None,
    ) -> httpx.Response:
        try:
            response = self._client.request(
                method,
                self.config.url(path),
                json=json,
                params=dict(params) if params else None,
                content=content,
                headers=self._headers(headers),
                timeout=timeout if timeout is not None else self.config.timeout,
            )
        except httpx.TimeoutException as exc:
            raise RunrSandboxError(None, f"The RunR API did not answer in time ({method} {path}).", "http_timeout") from exc
        except httpx.HTTPError as exc:
            raise RunrSandboxError(None, f"Cannot reach the RunR API: {exc}", "http_error") from exc
        _raise_for(response)
        return response

    def json(self, method: str, path: str, **kwargs: Any) -> Any:
        return _json(self.request(method, path, **kwargs))

    @contextlib.contextmanager
    def stream(
        self,
        method: str,
        path: str,
        *,
        json: Any = None,
        params: Optional[Mapping[str, Any]] = None,
        headers: Optional[Mapping[str, str]] = None,
    ) -> Iterator[httpx.Response]:
        try:
            with self._client.stream(
                method,
                self.config.url(path),
                json=json,
                params=dict(params) if params else None,
                headers=self._headers(headers),
                timeout=_stream_timeout(self.config.timeout),
            ) as response:
                if response.status_code >= 400:
                    response.read()
                    _raise_for(response)
                yield response
        except httpx.TimeoutException as exc:
            raise RunrSandboxError(None, f"The RunR API did not answer in time ({method} {path}).", "http_timeout") from exc
        except httpx.HTTPError as exc:
            raise RunrSandboxError(None, f"The stream from the RunR API broke off: {exc}", "http_error") from exc

    def close(self) -> None:
        if self._owns_client:
            self._client.close()


class AsyncTransport:
    """Non-blocking requests against one RunR API."""

    def __init__(self, config: ClientConfig, http_client: Optional[httpx.AsyncClient] = None) -> None:
        self.config = config
        self._owns_client = http_client is None
        self._client = http_client or httpx.AsyncClient(timeout=config.timeout, follow_redirects=False)

    def _headers(self, extra: Optional[Mapping[str, str]]) -> Dict[str, str]:
        headers = self.config.headers()
        if extra:
            headers.update(extra)
        return headers

    async def request(
        self,
        method: str,
        path: str,
        *,
        json: Any = None,
        params: Optional[Mapping[str, Any]] = None,
        content: Optional[bytes] = None,
        headers: Optional[Mapping[str, str]] = None,
        timeout: Optional[float] = None,
    ) -> httpx.Response:
        try:
            response = await self._client.request(
                method,
                self.config.url(path),
                json=json,
                params=dict(params) if params else None,
                content=content,
                headers=self._headers(headers),
                timeout=timeout if timeout is not None else self.config.timeout,
            )
        except httpx.TimeoutException as exc:
            raise RunrSandboxError(None, f"The RunR API did not answer in time ({method} {path}).", "http_timeout") from exc
        except httpx.HTTPError as exc:
            raise RunrSandboxError(None, f"Cannot reach the RunR API: {exc}", "http_error") from exc
        _raise_for(response)
        return response

    async def json(self, method: str, path: str, **kwargs: Any) -> Any:
        return _json(await self.request(method, path, **kwargs))

    @contextlib.asynccontextmanager
    async def stream(
        self,
        method: str,
        path: str,
        *,
        json: Any = None,
        params: Optional[Mapping[str, Any]] = None,
        headers: Optional[Mapping[str, str]] = None,
    ) -> AsyncIterator[httpx.Response]:
        try:
            async with self._client.stream(
                method,
                self.config.url(path),
                json=json,
                params=dict(params) if params else None,
                headers=self._headers(headers),
                timeout=_stream_timeout(self.config.timeout),
            ) as response:
                if response.status_code >= 400:
                    await response.aread()
                    _raise_for(response)
                yield response
        except httpx.TimeoutException as exc:
            raise RunrSandboxError(None, f"The RunR API did not answer in time ({method} {path}).", "http_timeout") from exc
        except httpx.HTTPError as exc:
            raise RunrSandboxError(None, f"The stream from the RunR API broke off: {exc}", "http_error") from exc

    async def aclose(self) -> None:
        if self._owns_client:
            await self._client.aclose()
