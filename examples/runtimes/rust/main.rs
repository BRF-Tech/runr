// RunR Rust example: http mode. The binary is its own HTTP server on $PORT and serves
// every trigger: plain requests (http), CloudEvent POSTs from the queue consumer (queue)
// and the schedule event RunR's wrapper sends once per cron run (schedule).
// Standard library only, so plain `rustc` builds it.
use std::env;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::thread;

fn hex(b: u8) -> Option<u8> {
    (b as char).to_digit(16).map(|d| d as u8)
}

fn url_decode(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => out.push(b' '),
            b'%' if i + 2 < bytes.len() => {
                match (hex(bytes[i + 1]), hex(bytes[i + 2])) {
                    (Some(h), Some(l)) => {
                        out.push(h * 16 + l);
                        i += 2;
                    }
                    _ => out.push(b'%'),
                }
            }
            b => out.push(b),
        }
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn query_param(query: &str, key: &str) -> Option<String> {
    query
        .split('&')
        .filter_map(|pair| pair.split_once('='))
        .find(|(k, _)| *k == key)
        .map(|(_, v)| url_decode(v))
        .filter(|v| !v.is_empty())
}

fn handle(stream: TcpStream) -> std::io::Result<()> {
    let mut reader = BufReader::new(stream.try_clone()?);
    let mut request_line = String::new();
    if reader.read_line(&mut request_line)? == 0 {
        return Ok(());
    }
    let target = request_line.split_whitespace().nth(1).unwrap_or("/").to_string();

    let mut content_length = 0usize;
    let mut event_type = String::new();
    loop {
        let mut line = String::new();
        if reader.read_line(&mut line)? == 0 || line.trim().is_empty() {
            break;
        }
        if let Some((name, value)) = line.split_once(':') {
            match name.trim().to_ascii_lowercase().as_str() {
                "content-length" => content_length = value.trim().parse().unwrap_or(0),
                "ce-type" => event_type = value.trim().to_string(),
                _ => {}
            }
        }
    }
    let mut body = vec![0u8; content_length];
    reader.read_exact(&mut body)?;

    let query = target.split_once('?').map(|(_, q)| q).unwrap_or("");
    let text = if event_type == "dev.runr.schedule.tick" {
        eprintln!("schedule trigger fired");
        "tick from Rust".to_string()
    } else if !event_type.is_empty() {
        format!("processed {} (from Rust)", String::from_utf8_lossy(&body).trim())
    } else {
        let name = query_param(query, "name").unwrap_or_else(|| "World".to_string());
        format!("Hello, {}! (from Rust)", name)
    };

    let mut out = stream;
    write!(
        out,
        "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
        text.len(),
        text
    )?;
    out.flush()
}

fn main() {
    let port = env::var("PORT").unwrap_or_else(|_| "8080".to_string());
    let listener = TcpListener::bind(("0.0.0.0", port.parse::<u16>().unwrap_or(8080))).expect("bind");
    eprintln!("Rust example listening on :{}", port);
    for stream in listener.incoming().flatten() {
        thread::spawn(move || {
            if let Err(e) = handle(stream) {
                eprintln!("request failed: {}", e);
            }
        });
    }
}
