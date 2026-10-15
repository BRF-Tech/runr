use spin_sdk::http::{IntoResponse, Request, Response};
use spin_sdk::http_component;

/// Every trigger reaches the component as an HTTP request: queue messages and schedule
/// ticks arrive as CloudEvents (the ce-type header names the event).
#[http_component]
fn handle(req: Request) -> anyhow::Result<impl IntoResponse> {
    let event = req.header("ce-type").and_then(|v| v.as_str()).unwrap_or("");
    let body = match event {
        "dev.runr.schedule.tick" => "tick from Rust (Wasm)".to_string(),
        "dev.runr.queue.message" => format!("processed {} (from Rust, Wasm)", String::from_utf8_lossy(req.body())),
        _ => {
            let name = req.query().split('&').find_map(|p| p.strip_prefix("name=")).unwrap_or("World");
            format!("Hello, {name}! (from Rust, Wasm)")
        }
    };
    println!("{body}");
    Ok(Response::builder()
        .status(200)
        .header("content-type", "text/plain")
        .body(body)
        .build())
}
