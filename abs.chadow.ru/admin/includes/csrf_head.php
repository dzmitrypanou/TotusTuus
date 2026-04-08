<?php
/**
 * Подключайте в <head> после bootstrap (сессия и csrf.php уже загружены).
 */
?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
(function() {
  var m = document.querySelector('meta[name="csrf-token"]');
  window.__csrfToken = m ? m.getAttribute('content') : '';
  var of = window.fetch;
  window.fetch = function(input, init) {
    init = init || {};
    var method = String(init.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS' && window.__csrfToken) {
      var h = init.headers;
      if (h instanceof Headers) {
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', window.__csrfToken);
      } else if (h && typeof h === 'object') {
        init.headers = Object.assign({}, h, { 'X-CSRF-Token': window.__csrfToken });
      } else {
        init.headers = { 'X-CSRF-Token': window.__csrfToken };
      }
    }
    return of.call(this, input, init);
  };
})();
    </script>
