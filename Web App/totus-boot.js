

(function () {
    var v = encodeURIComponent(String(window.TOTUS_WEB_APP_BUILD != null ? window.TOTUS_WEB_APP_BUILD : '0'));
    function load(src, next) {
        var s = document.createElement('script');
        s.src = src + '?v=' + v;
        if (next) s.onload = next;
        document.body.appendChild(s);
    }
    load('api-config.js', function () {
        load('src/main.js');
    });
})();
