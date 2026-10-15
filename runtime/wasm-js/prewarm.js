// Componentized once while the build image is built (node_modules installed, toolchain exercised).
addEventListener('fetch', (event) => {
  event.respondWith(new Response('prewarm'))
})
