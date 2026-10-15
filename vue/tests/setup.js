import { afterEach } from 'vitest'

// jsdom has no layout engine; vue-router's scrollBehavior calls this on navigation.
window.scrollTo = () => {}

// jsdom has no matchMedia (useTheme reads prefers-color-scheme when AppHeader loads).
// Answers "no match" for every query, like a browser without a dark-mode preference.
if (typeof window.matchMedia !== 'function') {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener() {},
    removeEventListener() {},
    addListener() {},
    removeListener() {},
    dispatchEvent() { return false },
  })
}

// jsdom's Blob/File lack the promise readers every browser has (arrayBuffer(), text());
// the image upload and the model hash read files with them. Backed by jsdom's FileReader.
function readBlob(blob, method) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(reader.error)
    reader[method](blob)
  })
}
if (typeof Blob !== 'undefined') {
  if (typeof Blob.prototype.arrayBuffer !== 'function') {
    Blob.prototype.arrayBuffer = function arrayBuffer() { return readBlob(this, 'readAsArrayBuffer') }
  }
  if (typeof Blob.prototype.text !== 'function') {
    Blob.prototype.text = function text() { return readBlob(this, 'readAsText') }
  }
}

// Node's Web Crypto checks a plain ArrayBuffer against its own realm's prototype, and the
// test realm's ArrayBuffer is a different one ("2nd argument is not instance of ArrayBuffer");
// typed-array views pass the check, so digest() gets a view over the same bytes. Browsers
// take either form.
const subtle = globalThis.crypto?.subtle
if (subtle?.digest) {
  const digest = subtle.digest.bind(subtle)
  subtle.digest = (algorithm, data) => digest(
    algorithm,
    Object.prototype.toString.call(data) === '[object ArrayBuffer]' ? new Uint8Array(data) : data,
  )
}

// Unit tests never reach a server. A request no spec mocked (e.g. a store a component loads on
// the side) fails at once like an unreachable API, instead of jsdom connecting to its origin
// (http://localhost:3000, where a local dev server may well be listening).
XMLHttpRequest.prototype.send = function send() {
  setTimeout(() => {
    this.dispatchEvent(new ProgressEvent('error'))
    this.dispatchEvent(new ProgressEvent('loadend'))
  }, 0)
}

// Every test starts with empty browser storage.
afterEach(() => {
  localStorage.clear()
})
