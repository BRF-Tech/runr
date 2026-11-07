//! The handler interface of the sample Zig runtime. A function's entry file (main.zig) declares
//!
//!     const runr = @import("runr.zig");
//!     pub fn handler(allocator: std.mem.Allocator, req: runr.Request) !runr.Response
//!
//! RunR places this file next to the function's code before every build (build.files of
//! runtimes/sample-zig/runtime.yaml), together with runr_server.zig, the HTTP server that calls the
//! handler, and build.zig. Everything the handler allocates with `allocator` is freed after the
//! response is written (one arena per request).
const std = @import("std");

/// How the invocation reached the function.
pub const Trigger = enum {
    /// a plain HTTP request
    http,
    /// a message of the queue consumer: a CloudEvent POST (binary mode), the entry's fields as JSON
    queue,
    /// a schedule tick: the CloudEvent dev.runr.schedule.tick, body {"trigger":"schedule"}
    schedule,
};

pub const Header = struct {
    name: []const u8,
    value: []const u8,
};

pub const Request = struct {
    trigger: Trigger,
    method: []const u8,
    /// the path without the query string ("/orders/42")
    path: []const u8,
    /// the raw query string without "?" ("name=RunR&x=1")
    query: []const u8,
    headers: []const Header,
    body: []const u8,

    /// The value of header `name` (case-insensitive), null when the request has none.
    pub fn header(self: Request, name: []const u8) ?[]const u8 {
        for (self.headers) |h| {
            if (std.ascii.eqlIgnoreCase(h.name, name)) return h.value;
        }
        return null;
    }

    /// The URL-decoded value of query parameter `name`, null when it is absent or empty.
    pub fn queryParam(self: Request, allocator: std.mem.Allocator, name: []const u8) !?[]const u8 {
        var pairs = std.mem.splitScalar(u8, self.query, '&');
        while (pairs.next()) |pair| {
            const eq = std.mem.indexOfScalar(u8, pair, '=') orelse continue;
            if (!std.mem.eql(u8, pair[0..eq], name)) continue;
            const value = try urlDecode(allocator, pair[eq + 1 ..]);
            return if (value.len == 0) null else value;
        }
        return null;
    }
};

pub const Response = struct {
    status: u16 = 200,
    content_type: []const u8 = "text/plain; charset=utf-8",
    body: []const u8 = "",

    /// 200 with a text/plain body.
    pub fn text(body: []const u8) Response {
        return .{ .body = body };
    }

    /// 200 with a body that already is JSON text.
    pub fn json(body: []const u8) Response {
        return .{ .content_type = "application/json", .body = body };
    }
};

/// The string field `name` of a JSON object `body` (a queue message: {"msg":"hi"}), null when the
/// body is not a JSON object or the field is not a string.
pub fn jsonString(allocator: std.mem.Allocator, body: []const u8, name: []const u8) ?[]const u8 {
    const parsed = std.json.parseFromSlice(std.json.Value, allocator, body, .{}) catch return null;
    return switch (parsed.value) {
        .object => |object| if (object.get(name)) |value| switch (value) {
            .string => |s| s,
            else => null,
        } else null,
        else => null,
    };
}

/// `s` with "+" as a space and %XX sequences decoded.
pub fn urlDecode(allocator: std.mem.Allocator, s: []const u8) ![]u8 {
    const out = try allocator.alloc(u8, s.len);
    var n: usize = 0;
    var i: usize = 0;
    while (i < s.len) : (i += 1) {
        const c = s[i];
        if (c == '+') {
            out[n] = ' ';
        } else if (c == '%' and i + 2 < s.len) {
            const hi = std.fmt.charToDigit(s[i + 1], 16) catch {
                out[n] = c;
                n += 1;
                continue;
            };
            const lo = std.fmt.charToDigit(s[i + 2], 16) catch {
                out[n] = c;
                n += 1;
                continue;
            };
            out[n] = hi * 16 + lo;
            i += 2;
        } else {
            out[n] = c;
        }
        n += 1;
    }
    // the caller frees what it got: give back the exact length
    return allocator.realloc(out, n);
}

test "urlDecode" {
    const a = std.testing.allocator;
    const cases = [_][2][]const u8{
        .{ "RunR", "RunR" },
        .{ "Run+R", "Run R" },
        .{ "a%2Fb", "a/b" },
        .{ "100%", "100%" },
        .{ "%zz", "%zz" },
        .{ "%4", "%4" },
    };
    for (cases) |c| {
        const got = try urlDecode(a, c[0]);
        defer a.free(got);
        try std.testing.expectEqualStrings(c[1], got);
    }
}

test "Request.queryParam and header" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const headers = [_]Header{.{ .name = "Content-Type", .value = "application/json" }};
    const req = Request{ .trigger = .http, .method = "GET", .path = "/", .query = "x=1&name=Run%20R&empty=", .headers = &headers, .body = "" };
    try std.testing.expectEqualStrings("Run R", (try req.queryParam(arena.allocator(), "name")).?);
    try std.testing.expect((try req.queryParam(arena.allocator(), "empty")) == null);
    try std.testing.expect((try req.queryParam(arena.allocator(), "missing")) == null);
    try std.testing.expectEqualStrings("application/json", req.header("content-type").?);
}

test "jsonString" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();
    try std.testing.expectEqualStrings("hi", jsonString(a, "{\"msg\":\"hi\"}", "msg").?);
    try std.testing.expect(jsonString(a, "{\"msg\":42}", "msg") == null);
    try std.testing.expect(jsonString(a, "not json", "msg") == null);
}
