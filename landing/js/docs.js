// runr.sh documentation: the sidebar stays where the reader left it when a link opens another
// page. Its scroll position is kept for the tab (sessionStorage); without one, the current page's
// link is scrolled into the sidebar's view. Only the sidebar scrolls, never the page.
(function () {
  var box = document.querySelector('.d-side details');
  if (!box) return;
  var KEY = 'runr-docs-side';

  var saved = null;
  try { saved = sessionStorage.getItem(KEY); } catch (e) { /* storage blocked: start at the top */ }
  if (saved !== null) box.scrollTop = parseInt(saved, 10) || 0;

  var cur = box.querySelector('a[aria-current="page"]');
  if (cur) {
    var b = box.getBoundingClientRect();
    var c = cur.getBoundingClientRect();
    if (c.top < b.top || c.bottom > b.bottom) {
      box.scrollTop += (c.top - b.top) - (b.height - c.height) / 2;
    }
  }

  function save() {
    try { sessionStorage.setItem(KEY, String(box.scrollTop)); } catch (e) { /* nothing to keep */ }
  }
  box.addEventListener('scroll', save, { passive: true });
  window.addEventListener('pagehide', save);
})();
