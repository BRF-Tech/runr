// Web Worker of the bot protection's local check (utils/captchaPow.js): scans one challenge off
// the main thread and reports progress, so the form stays responsive while the browser works.
//   in:  {challenge}
//   out: {type: "progress", done, total} … {type: "solved", number, payload, took} | {type: "error", error}
import { runPowJob } from '../utils/captchaPow'

self.onmessage = (event) => {
  runPowJob(event.data, (message) => self.postMessage(message), () => performance.now())
}
