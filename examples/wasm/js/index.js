// Every trigger reaches the component as an HTTP request: queue messages and schedule ticks
// arrive as CloudEvents (the ce-type header names the event).
async function handle(request) {
  const event = request.headers.get('ce-type') || ''
  let body
  if (event === 'dev.runr.schedule.tick') {
    body = 'tick from JavaScript (Wasm)'
  } else if (event === 'dev.runr.queue.message') {
    body = `processed ${await request.text()} (from JavaScript, Wasm)`
  } else {
    const name = new URL(request.url).searchParams.get('name') || 'World'
    body = `Hello, ${name}! (from JavaScript, Wasm)`
  }
  console.log(body)
  return new Response(body, { status: 200, headers: { 'content-type': 'text/plain' } })
}

addEventListener('fetch', (event) => {
  event.respondWith(handle(event.request))
})
