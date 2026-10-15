// Compiled once while the build image is built, so the dependencies of the default Cargo.toml are
// in CARGO_TARGET_DIR before any function build.
use spin_sdk::http::{IntoResponse, Request, Response};
use spin_sdk::http_component;

#[http_component]
fn handle(_req: Request) -> anyhow::Result<impl IntoResponse> {
    Ok(Response::builder().status(200).body("prewarm").build())
}
