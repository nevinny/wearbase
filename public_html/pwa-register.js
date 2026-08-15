if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js', {scope: '/'}).catch(() => {
            // PWA support is optional: a registration failure must not break the web flow.
        });
    });
}
