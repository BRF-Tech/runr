//! The build of a function of the sample Zig runtime: `zig build` in the function's directory
//! (build.command in runtime.yaml) compiles runr_server.zig, which imports the function's handler
//! through runr_entry.zig, into one executable, `server`.
//!
//! Dependencies: every dependency of the function's build.zig.zon (`zig fetch --save <url>`)
//! becomes an import of the same name, `const clap = @import("clap");`, when the package exposes
//! a module of that name — the common case. RunR places this file next to the code before the
//! build (build.files), so a function does not ship its own build.zig.
const std = @import("std");

pub fn build(b: *std.Build) void {
    const target = b.standardTargetOptions(.{});
    const optimize = b.standardOptimizeOption(.{});

    const root = b.createModule(.{
        .root_source_file = b.path("runr_server.zig"),
        .target = target,
        .optimize = optimize,
    });
    for (b.available_deps) |dep| {
        const name = dep[0];
        const package = b.dependency(name, .{ .target = target, .optimize = optimize });
        if (package.builder.modules.get(name)) |module| {
            root.addImport(name, module);
        } else {
            std.debug.print("build.zig.zon: dependency {s} has no module named {s}, not imported\n", .{ name, name });
        }
    }

    const exe = b.addExecutable(.{ .name = "server", .root_module = root });
    b.installArtifact(exe);

    // `zig build test`: the tests of runr.zig
    const tests = b.addTest(.{ .root_module = b.createModule(.{
        .root_source_file = b.path("runr.zig"),
        .target = target,
        .optimize = optimize,
    }) });
    b.step("test", "Run the tests of runr.zig").dependOn(&b.addRunArtifact(tests).step);
}
