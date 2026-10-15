"""RunR hf-serve: serves one Hugging Face model with a uniform JSON API.

The image RunR deploys for model services on the ``transformers`` and ``diffusers`` engines.
It also carries the two helpers of offline model storage: ``mirror`` (a pinned snapshot of a
Hub repository into region storage) and ``receive`` (the target of chunked uploads for
air-gapped regions). Everything is configured through the environment, see ``config.py``.
"""

__version__ = "1.1.0"
