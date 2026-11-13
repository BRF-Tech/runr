use spin_sdk::http::body::IncomingBodyExt;
use spin_sdk::http::{IntoResponse, Request, Response};
use spin_sdk::http_service;

/// Every trigger reaches the component as an HTTP request: queue messages and schedule
/// ticks arrive as CloudEvents (the ce-type header names the event).
#[http_service]
async fn handle(req: Request) -> anyhow::Result<impl IntoResponse> {
    let event = req.headers().get("ce-type").and_then(|v| v.to_str().ok()).unwrap_or("").to_string();
    let query = req.uri().query().unwrap_or("").to_string();
    let body = match event.as_str() {
        "dev.runr.schedule.tick" => "tick from Rust (Wasm)".to_string(),
        "dev.runr.queue.message" => {
            let message = req.into_body().bytes().await?;
            format!("processed {} (from Rust, Wasm)", String::from_utf8_lossy(&message))
        }
        _ => {
            let name = query.split('&').find_map(|p| p.strip_prefix("name=")).unwrap_or("World");
            format!("Hello, {name}! (from Rust, Wasm)")
        }
    };
    println!("{body}");
    Ok(Response::builder()
        .status(200)
        .header("content-type", "text/plain")
        .body(body)?)
}
