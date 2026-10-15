# Q — a toy language for RunR

Q exists to prove that RunR runs a language nobody has heard of: its interpreter
([`interpreter/`](interpreter/), Go, standard library only) becomes the runtime image, and
RunR's universal wrapper keeps one `q` process running that answers request envelopes line by
line (`run.mode: persistent`).

```q
# main.q: hello world in Q, for every trigger
set name = query.name or "World"
if trigger == "schedule" then return "tick from Q"
if trigger == "queue" then return "processed {data.msg} (from Q)"
print "Hello, {name}! (from Q)"
```

## Language

One statement per line; `#` starts a comment; blank lines are ignored. The script is parsed once
when the process starts — a parse error stops the process with the file name and line number.

| Statement | Effect |
|---|---|
| `set NAME = EXPR` | assign a variable |
| `print EXPR` | append to the response body |
| `header NAME = EXPR` | set a response header (`header Content-Type = "text/html"`) |
| `status EXPR` | set the response status (100–599, default 200) |
| `log EXPR` | write a line to the logs (stderr) |
| `return [EXPR]` | append EXPR (optional) and finish the request |
| `if EXPR then STATEMENT [else STATEMENT]` | conditional |

Expressions, lowest precedence first: `A or B` (A when truthy, else B), `A and B`,
`A == B` / `A != B` (numeric when both sides are numbers), `A + B` (sum of two numbers, else
concatenation), `not A`, and the values below. Every value is a string; `""` and `"false"` are
falsy. Comparisons give `"true"` / `"false"`.

| Value | Meaning |
|---|---|
| `"text {name}"` | string; `{variable}` and `{accessor}` interpolate; escapes `\" \\ \n \t \{ \}` |
| `42`, `1.5`, `true`, `false` | literals |
| `method`, `path`, `body`, `trigger`, `id` | the request (`trigger` is `http`, `schedule` or `queue`) |
| `query.NAME`, `header.NAME` | query parameter, request header (case-insensitive) |
| `event.type`, `event.id`, `event.source`, `event.time`, `event.specversion` | the CloudEvent of queue and schedule invocations |
| `data`, `data.KEY.KEY…`, `data.LIST.0` | the CloudEvent data, else the JSON request body; objects and lists come back as JSON |

A runtime error (an unknown variable, a status that is not a number) answers 500 with
`{"error":"line N: …"}` and logs the same message.

## Run it locally

```bash
cd interpreter
go test ./...
echo '{"runr":1,"trigger":"http","query":{"name":"RunR"}}' | go run . -once ../main.q
# {"statusCode":200,"body":"Hello, RunR! (from Q)"}
go run . -check ../main.q    # parse only
```

Without `-once`, `q script.q` reads one envelope per stdin line and writes one response per
stdout line — the persistent-mode protocol RunR's wrapper speaks. `q -cgi script.q` handles one
CGI request (the request in the environment, the body on stdin, the response as CGI on stdout) —
the WAGI contract the interpreter speaks on WebAssembly ([`examples/wasm/q-exec`](../../wasm/q-exec)).

The image builds from the files uploaded with the runtime (`Dockerfile`, `go.mod`, the `.go`
sources); [`../README.md`](../README.md) shows the registration call
(`runr runtimes register -f examples/runtimes/q/runtime.yaml --files examples/runtimes/q/interpreter`).
