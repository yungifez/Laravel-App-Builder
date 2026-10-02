// Keep-alive, injected by the preview gateway into every page of a preview.
// A preview stops when nobody has used it for a while. Background requests
// do not count, so a tab left open does not keep the app running. This
// tells the gateway that the page can be seen, once a minute and when the
// tab comes back into view.
(() => {
    const seen = () => {
        if (document.visibilityState === 'visible') {
            fetch('/__builder/alive', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
            }).catch(() => {});
        }
    };

    setInterval(seen, 60_000);
    document.addEventListener('visibilitychange', seen);
})();
