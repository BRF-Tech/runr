"""RunR Python Schedule Runtime — calls handler directly and exits."""

import importlib.util
import json
import os
import sys

CODE_DIR = "/app/code"
HANDLER_NAME = os.environ.get("RUNR_HANDLER", "handler")
FILENAME = os.environ.get("RUNR_FILENAME", "main.py")

sys.path.insert(0, CODE_DIR)

filepath = os.path.join(CODE_DIR, FILENAME)
module_name = os.path.splitext(FILENAME)[0]

spec = importlib.util.spec_from_file_location(module_name, filepath)
module = importlib.util.module_from_spec(spec)
sys.modules[module_name] = module
spec.loader.exec_module(module)

handler_fn = getattr(module, HANDLER_NAME, None)
if handler_fn is None:
    print(f"Error: handler '{HANDLER_NAME}' not found in {FILENAME}", file=sys.stderr)
    sys.exit(1)

event = {"trigger": "schedule"}
context = {"mode": "schedule"}

try:
    result = handler_fn(event, context)
    output = json.dumps(result, ensure_ascii=False) if isinstance(result, (dict, list)) else str(result)
    print(output)
except Exception as e:
    print(f"Error: {e}", file=sys.stderr)
    sys.exit(1)
