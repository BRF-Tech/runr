//! HTTP server of the sample Zig runtime (run.mode: http). It listens on $PORT, turns every request
//! into a runr.Request, calls the function's handler and writes its runr.Response:
//!
//!   - http trigger: every request as it arrives (Knative's queue-proxy sits in front)
//!   - queue trigger: the queue consumer's CloudEvent POSTs (binary mode, ce-type
//!     dev.runr.queue.message, the stream entry's fields as a JSON object in the body)
//!   - schedule trigger: RunR's universal wrapper starts this server, POSTs one schedule CloudEvent
//!     (ce-type dev.runr.schedule.tick), prints the answer and stops the server
//!     (run.schedule: invoke in runtime.yaml)
//!
//! One thread per connection and one request per connection (Connection: close). Bodies with
//! Content-Length or chunked transfer encoding, up to 10 MiB. Standard library only, plain POSIX
//! sockets. RunR copies this file next to the function's code before the build (build.files).
const std = @import("std");
const runr = @import("runr.zig");
// Written by the build command (build.command in runtime.yaml):
//     pub const handler = @import("<entry file>").<handler name>;
const entry = @import("runr_entry.zig");

const max_head = 64 * 1024;
const max_body = 10 * 1024 * 1024;

const RequestError = error{ BadRequest, HeaderTooLarge, BodyTooLarge, UnexpectedEof };

pub fn main() !void {
    const port_text = std.posix.getenv("PORT") orelse "8080";
    const port = std.fmt.parseInt(u16, port_text, 10) catch {
        std.debug.print("[runr-zig] PORT={s} is not a port number\n", .{port_text});
        std.process.exit(2);
    };
    const address = std.net.Address.initIp4(.{ 0, 0, 0, 0 }, port);
    var server = try address.listen(.{ .reuse_address = true });
    defer server.deinit();
    std.debug.print("[runr-zig] {s} listening on :{d}\n", .{ std.posix.getenv("RUNR_FILENAME") orelse "main.zig", port });

    while (true) {
        const conn = server.accept() catch |err| {
            std.debug.print("[runr-zig] accept: {s}\n", .{@errorName(err)});
            continue;
        };
        const thread = std.Thread.spawn(.{}, serve, .{conn.stream.handle}) catch |err| {
            std.debug.print("[runr-zig] cannot start a thread: {s}\n", .{@errorName(err)});
            conn.stream.close();
            continue;
        };
        thread.detach();
    }
}

fn serve(fd: std.posix.fd_t) void {
    defer std.posix.close(fd);
    var arena = std.heap.ArenaAllocator.init(std.heap.page_allocator);
    defer arena.deinit();
    const a = arena.allocator();
    handle(a, fd) catch |err| switch (err) {
        error.BadRequest => writeError(a, fd, 400, "bad request"),
        error.HeaderTooLarge => writeError(a, fd, 431, "request header too large"),
        error.BodyTooLarge => writeError(a, fd, 413, "request body too large"),
        error.UnexpectedEof => {},
        else => std.debug.print("[runr-zig] request failed: {s}\n", .{@errorName(err)}),
    };
}

fn handle(a: std.mem.Allocator, fd: std.posix.fd_t) !void {
    // The head: everything up to the empty line
    const buf = try a.alloc(u8, max_head);
    var filled: usize = 0;
    const head_len = while (true) {
        if (filled == buf.len) return error.HeaderTooLarge;
        const n = try std.posix.read(fd, buf[filled..]);
        if (n == 0) return error.UnexpectedEof;
        // the end of the head may span two reads: search from 3 bytes before the new ones
        const from = if (filled > 3) filled - 3 else 0;
        filled += n;
        if (std.mem.indexOf(u8, buf[from..filled], "\r\n\r\n")) |i| break from + i;
    };

    var lines = std.mem.splitSequence(u8, buf[0..head_len], "\r\n");
    var request_line = std.mem.splitScalar(u8, lines.first(), ' ');
    const method = request_line.next() orelse return error.BadRequest;
    const target = request_line.next() orelse return error.BadRequest;
    if (method.len == 0 or target.len == 0) return error.BadRequest;

    var count: usize = 0;
    var counter = lines;
    while (counter.next()) |_| count += 1;
    const headers = try a.alloc(runr.Header, count);
    for (headers) |*h| {
        const line = lines.next().?;
        const colon = std.mem.indexOfScalar(u8, line, ':') orelse return error.BadRequest;
        h.* = .{
            .name = std.mem.trim(u8, line[0..colon], " \t"),
            .value = std.mem.trim(u8, line[colon + 1 ..], " \t"),
        };
    }

    const query_at = std.mem.indexOfScalar(u8, target, '?');
    var req = runr.Request{
        .trigger = .http,
        .method = method,
        .path = if (query_at) |q| target[0..q] else target,
        .query = if (query_at) |q| target[q + 1 ..] else "",
        .headers = headers,
        .body = "",
    };

    // The body: the bytes after the head that were read already, then the socket. Its own buffer:
    // the request line and the headers point into `buf`
    const rest = buf[head_len + 4 .. filled];
    const body_buf = try a.alloc(u8, max_head);
    @memcpy(body_buf[0..rest.len], rest);
    var body_reader = BodyReader{ .fd = fd, .buf = body_buf, .start = 0, .end = rest.len };
    if (req.header("transfer-encoding")) |te| {
        if (!std.ascii.eqlIgnoreCase(te, "chunked")) return error.BadRequest;
        req.body = try body_reader.readChunked(a);
    } else if (req.header("content-length")) |cl| {
        const len = std.fmt.parseInt(usize, cl, 10) catch return error.BadRequest;
        if (len > max_body) return error.BodyTooLarge;
        const body = try a.alloc(u8, len);
        try body_reader.readInto(body);
        req.body = body;
    }

    // Knative probes and CORS preflights never reach the handler
    if (req.header("k-network-probe") != null or req.header("k-kubelet-probe") != null) {
        return writeResponse(a, fd, runr.Response.text("ok"));
    }
    if (std.mem.eql(u8, method, "OPTIONS")) {
        return writeAll(fd, "HTTP/1.1 204 No Content\r\n" ++ cors ++
            "Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS\r\n" ++
            "Access-Control-Allow-Headers: Content-Type, Authorization\r\n" ++
            "Access-Control-Max-Age: 86400\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    // A CloudEvent in binary mode is a queue delivery, unless it is RunR's schedule tick
    if (req.header("ce-type")) |t| {
        req.trigger = if (std.mem.eql(u8, t, "dev.runr.schedule.tick")) .schedule else .queue;
    } else if (req.header("ce-specversion") != null) {
        req.trigger = .queue;
    }

    // The handler may return runr.Response or an error union of it
    const result = entry.handler(a, req);
    const res: runr.Response = if (@typeInfo(@TypeOf(result)) == .error_union) result catch |err| {
        std.debug.print("[runr-zig] handler failed: {s}\n", .{@errorName(err)});
        return writeError(a, fd, 500, "handler failed");
    } else result;
    try writeResponse(a, fd, res);
}

const cors = "Access-Control-Allow-Origin: *\r\n";

/// Reads a request body: first the bytes of `buf[start..end]` (read together with the head), then
/// from the socket.
const BodyReader = struct {
    fd: std.posix.fd_t,
    buf: []u8,
    start: usize,
    end: usize,

    fn readInto(self: *BodyReader, out: []u8) !void {
        var n: usize = 0;
        while (n < out.len) {
            if (self.start < self.end) {
                const k = @min(out.len - n, self.end - self.start);
                @memcpy(out[n..][0..k], self.buf[self.start..][0..k]);
                self.start += k;
                n += k;
            } else {
                const k = try std.posix.read(self.fd, out[n..]);
                if (k == 0) return error.UnexpectedEof;
                n += k;
            }
        }
    }

    /// The next line without its CRLF, valid until the next read.
    fn readLine(self: *BodyReader) ![]const u8 {
        while (true) {
            if (std.mem.indexOf(u8, self.buf[self.start..self.end], "\r\n")) |i| {
                const line = self.buf[self.start .. self.start + i];
                self.start += i + 2;
                return line;
            }
            // keep the partial line, make room behind it
            const rest = self.end - self.start;
            std.mem.copyForwards(u8, self.buf[0..rest], self.buf[self.start..self.end]);
            self.start = 0;
            self.end = rest;
            if (self.end == self.buf.len) return error.BadRequest;
            const k = try std.posix.read(self.fd, self.buf[self.end..]);
            if (k == 0) return error.UnexpectedEof;
            self.end += k;
        }
    }

    /// A chunked body (RFC 9112, section 7.1): size lines, data, the last chunk, trailers.
    fn readChunked(self: *BodyReader, a: std.mem.Allocator) ![]u8 {
        var body = try a.alloc(u8, 0);
        var len: usize = 0;
        while (true) {
            const line = try self.readLine();
            const size_text = std.mem.trim(u8, line[0 .. std.mem.indexOfScalar(u8, line, ';') orelse line.len], " \t");
            const size = std.fmt.parseInt(usize, size_text, 16) catch return error.BadRequest;
            if (size == 0) break;
            if (size > max_body or len + size > max_body) return error.BodyTooLarge;
            if (len + size > body.len) body = try a.realloc(body, @max(body.len * 2, len + size));
            try self.readInto(body[len..][0..size]);
            len += size;
            if ((try self.readLine()).len != 0) return error.BadRequest;
        }
        while ((try self.readLine()).len != 0) {} // trailer fields
        return body[0..len];
    }
};

fn writeResponse(a: std.mem.Allocator, fd: std.posix.fd_t, res: runr.Response) !void {
    if (res.status < 100 or res.status > 999) {
        std.debug.print("[runr-zig] the handler answered status {d}, outside 100-999\n", .{res.status});
        return writeError(a, fd, 500, "handler returned an invalid status");
    }
    const content_type = if (std.mem.indexOfAny(u8, res.content_type, "\r\n") == null)
        res.content_type
    else
        "text/plain; charset=utf-8";
    const head = try std.fmt.allocPrint(a, "HTTP/1.1 {d} {s}\r\nContent-Type: {s}\r\nContent-Length: {d}\r\n" ++
        cors ++ "Connection: close\r\n\r\n", .{ res.status, reason(res.status), content_type, res.body.len });
    try writeAll(fd, head);
    try writeAll(fd, res.body);
}

fn writeError(a: std.mem.Allocator, fd: std.posix.fd_t, status: u16, message: []const u8) void {
    const body = std.fmt.allocPrint(a, "{{\"error\":\"{s}\"}}", .{message}) catch return;
    writeResponse(a, fd, .{ .status = status, .content_type = "application/json", .body = body }) catch {};
}

fn writeAll(fd: std.posix.fd_t, bytes: []const u8) !void {
    var n: usize = 0;
    while (n < bytes.len) n += try std.posix.write(fd, bytes[n..]);
}

fn reason(status: u16) []const u8 {
    return switch (status) {
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        204 => "No Content",
        301 => "Moved Permanently",
        302 => "Found",
        304 => "Not Modified",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        409 => "Conflict",
        413 => "Content Too Large",
        422 => "Unprocessable Content",
        429 => "Too Many Requests",
        431 => "Request Header Fields Too Large",
        500 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        else => "Status",
    };
}
