-- RunR persistent-mode worker for Lua (the sample-lua runtime).
--
-- RunR's universal wrapper starts this script once per worker (run.workers) and keeps it running.
-- For every invocation it writes the request envelope as one JSON line to stdin; the first stdout
-- line that is JSON is the answer (docs/runtimes.md, "persistent mode"). This script loads the
-- function once - RUNR_FILENAME from RUNR_CODE_DIR - and calls RUNR_HANDLER(event, context) for
-- every line:
--
--   event    the request envelope: trigger, id, method, path, query, rawQuery, headers, body,
--            isBase64Encoded and, for queue messages and schedule ticks, event (the CloudEvent,
--            event.data its parsed JSON payload); JSON null is nil
--   context  runtime, version, handler, filename, request_id, trigger, worker, timeout
--
-- The handler returns a string (the body, text/plain), a table with a numeric statusCode (a
-- response envelope: statusCode, headers, body, isBase64Encoded), any other table (sent as JSON)
-- or nil (an empty 200). print() and io.write() go to stderr, the function's logs, so a debug line
-- never reaches the protocol.
local cjson = require("cjson.safe")

local protocol = io.stdout
io.output(io.stderr)
print = function(...)
  local parts = {}
  for i = 1, select("#", ...) do parts[i] = tostring((select(i, ...))) end
  io.stderr:write(table.concat(parts, "\t"), "\n")
end

local code_dir = os.getenv("RUNR_CODE_DIR") or "/app/code"
local filename = os.getenv("RUNR_FILENAME") or "main.lua"
local handler_name = os.getenv("RUNR_HANDLER") or "handler"

-- require("helpers") finds helpers.lua next to the entry file; dependencies.env puts the
-- installed rocks (/app/vendor) on LUA_PATH / LUA_CPATH
package.path = code_dir .. "/?.lua;" .. code_dir .. "/?/init.lua;" .. package.path

local function log(fmt, ...)
  io.stderr:write("[runr-lua] ", string.format(fmt, ...), "\n")
end

-- JSON null (cjson.null) as nil, so `event.event.data.msg` never indexes a userdata
local function strip_nulls(value)
  if value == cjson.null then return nil end
  if type(value) == "table" then
    for k, v in pairs(value) do value[k] = strip_nulls(v) end
  end
  return value
end

local B64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"

local function base64(s)
  local out = {}
  for i = 1, #s, 3 do
    local a, b, c = s:byte(i, i + 2)
    local n = a * 65536 + (b or 0) * 256 + (c or 0)
    local chars = {}
    for j = 1, 4 do
      local idx = (n >> (6 * (4 - j))) & 63
      chars[j] = B64:sub(idx + 1, idx + 1)
    end
    if not b then chars[3], chars[4] = "=", "=" elseif not c then chars[4] = "=" end
    out[#out + 1] = table.concat(chars)
  end
  return table.concat(out)
end

local function load_handler()
  local chunk, err = loadfile(code_dir .. "/" .. filename)
  if not chunk then return nil, err end
  local ok, module = pcall(chunk)
  if not ok then return nil, module end
  local fn = type(module) == "table" and module[handler_name] or nil
  if fn == nil then fn = rawget(_G, handler_name) end
  if type(fn) ~= "function" then
    return nil, string.format("%s defines no function %s: return a table with it (return M) or define it globally",
      filename, handler_name)
  end
  return fn
end

local function failure(status, message)
  return { statusCode = status, body = { error = message } }
end

-- The handler's result as a response envelope
local function envelope(result)
  if result == nil then return { statusCode = 200, body = "" } end
  if type(result) == "table" and type(result.statusCode) == "number" then return result end
  return { statusCode = 200, body = result }
end

local handler, load_error = load_handler()
if handler then
  log("%s loaded, worker %s", filename, os.getenv("RUNR_WORKER") or "0")
else
  log("cannot load %s: %s", filename, tostring(load_error))
end

local context_base = {
  runtime = os.getenv("RUNR_RUNTIME"),
  version = os.getenv("RUNR_RUNTIME_VERSION"),
  handler = handler_name,
  filename = filename,
  worker = tonumber(os.getenv("RUNR_WORKER") or "0"),
  timeout = tonumber(os.getenv("RUNR_TIMEOUT") or "30"),
}

local function invoke(line)
  local event = cjson.decode(line)
  if type(event) ~= "table" then
    log("not a request envelope: %s", line:sub(1, 200))
    return failure(400, "invalid request envelope")
  end
  event = strip_nulls(event)
  event.query = event.query or {}
  event.headers = event.headers or {}
  if not handler then
    log("cannot load %s: %s", filename, tostring(load_error))
    return failure(500, "handler failed to load")
  end

  local context = { request_id = event.id, trigger = event.trigger }
  for k, v in pairs(context_base) do context[k] = v end

  local ok, result = xpcall(handler, debug.traceback, event, context)
  if not ok then
    log("handler failed: %s", tostring(result))
    return failure(500, "handler failed")
  end
  result = envelope(result)
  -- a body that is not UTF-8 text goes out base64-encoded (the wrapper decodes it)
  if type(result.body) == "string" and not result.isBase64Encoded and utf8.len(result.body) == nil then
    result.body = base64(result.body)
    result.isBase64Encoded = true
  end
  return result
end

for line in io.stdin:lines() do
  if line ~= "" then
    local reply = invoke(line)
    local text, err = cjson.encode(reply)
    if not text then
      log("the handler's result is not JSON-encodable: %s", tostring(err))
      text = cjson.encode(failure(500, "handler returned a value that cannot be sent as JSON"))
    end
    protocol:write(text, "\n")
    protocol:flush()
  end
end
