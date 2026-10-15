// Leaves the console for the URL an MCP client is waiting at (OAuth redirect back with the
// authorization code or the error). A function of its own so tests can observe it.
export function navigateAway(url) {
  window.location.assign(url)
}
