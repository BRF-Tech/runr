# Runtime sources (examples)

`sample-custom-executor/runtimes/` is a copy of the `runtimes/` directory of
[BRF-Tech/runr-sample-custom-executor](https://github.com/BRF-Tech/runr-sample-custom-executor)
(commit 42af605530be): a Lua runtime in persistent mode through the universal wrapper and a compiled Zig
runtime whose binary serves HTTP itself, both with lifecycle fields. The runtime source e2e suite
(`runtime/ci.sh test-runtime-sources`, `E2eTestRuntimeSourcesTrait`) serves it from a git daemon in
the Kind cluster and connects it as a Git source, so the test does not depend on GitHub.

The sample repository is the one to copy: it has the Dockerfiles' context, the contract tests, the
GitHub Actions workflows and the guide. Refresh this copy from it when its runtimes change:

```bash
rm -rf examples/runtime-sources/sample-custom-executor/runtimes
cp -r ../runr-sample-custom-executor/runtimes examples/runtime-sources/sample-custom-executor/
```

How to build an executor: [docs/executor-development.md](../../docs/executor-development.md).
