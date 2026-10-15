/**
 * RunR Node.js Schedule Runtime — calls handler directly and exits.
 */

const path = require('path');

const CODE_DIR = '/app/code';
const HANDLER_NAME = process.env.RUNR_HANDLER || 'handler';
const FILENAME = process.env.RUNR_FILENAME || 'main.js';

const modulePath = path.join(CODE_DIR, FILENAME);
const userModule = require(modulePath);

const handlerFn = userModule[HANDLER_NAME];
if (typeof handlerFn !== 'function') {
  console.error(`Error: handler '${HANDLER_NAME}' not found in ${FILENAME}`);
  process.exit(1);
}

const event = { trigger: 'schedule' };
const context = { mode: 'schedule' };

(async () => {
  try {
    const result = await handlerFn(event, context);
    const output = typeof result === 'object' ? JSON.stringify(result) : String(result);
    console.log(output);
  } catch (err) {
    console.error(`Error: ${err.message}`);
    process.exit(1);
  }
})();
