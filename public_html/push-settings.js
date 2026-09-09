(() => {
    const root = document.querySelector('[data-web-push-settings]');
    if (!root) return;
    const button = root.querySelector('[data-web-push-enable]');
    const status = root.querySelector('[data-web-push-status]');
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!supported || !root.dataset.publicKey) {
        button.hidden = true;
        status.textContent = supported ? 'Push пока не настроен на сервере.' : 'Этот браузер не поддерживает push.';
        return;
    }

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('permission');
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToBytes(root.dataset.publicKey)
            });
            const response = await fetch(root.dataset.subscribeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf},
                body: JSON.stringify(subscription.toJSON())
            });
            if (!response.ok) throw new Error('server');
            status.textContent = 'Push включён на этом устройстве.';
        } catch (_) {
            status.textContent = 'Не удалось включить push. Проверьте разрешение браузера.';
            button.disabled = false;
        }
    });

    root.querySelectorAll('[data-web-push-revoke]').forEach((revokeButton) => {
        revokeButton.addEventListener('click', async () => {
            revokeButton.disabled = true;
            try {
                const response = await fetch(revokeButton.dataset.url, {
                    method: 'DELETE', credentials: 'same-origin', headers: {'X-CSRF-Token': root.dataset.csrf}
                });
                if (!response.ok) throw new Error('server');
                revokeButton.closest('[data-web-push-row]').remove();
                status.textContent = 'Push отключён для устройства.';
            } catch (_) {
                revokeButton.disabled = false;
                status.textContent = 'Не удалось отключить push.';
            }
        });
    });

    function base64UrlToBytes(value) {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
        return Uint8Array.from(atob(padded), character => character.charCodeAt(0));
    }
})();
