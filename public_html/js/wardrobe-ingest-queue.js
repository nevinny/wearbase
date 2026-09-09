(function (global) {
    'use strict';

    if (global.WardrobeIngestQueue) return;

    var DB_NAME = 'wearbase-wardrobe-upload';
    var STORE = 'pending';
    var drainPromise = null;

    function database() {
        return new Promise(function (resolve, reject) {
            var request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = function () {
                if (!request.result.objectStoreNames.contains(STORE)) {
                    request.result.createObjectStore(STORE, {keyPath: 'key'});
                }
            };
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    async function transaction(mode, callback) {
        var db = await database();
        return new Promise(function (resolve, reject) {
            var tx = db.transaction(STORE, mode);
            var result = callback(tx.objectStore(STORE));
            tx.oncomplete = function () { db.close(); resolve(result); };
            tx.onerror = function () { db.close(); reject(tx.error); };
        });
    }

    function requestResult(request) {
        return new Promise(function (resolve, reject) {
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    async function list() {
        var db = await database();
        try {
            return await requestResult(db.transaction(STORE, 'readonly').objectStore(STORE).getAll());
        } finally {
            db.close();
        }
    }

    async function remove(key) { await transaction('readwrite', function (store) { store.delete(key); }); }
    async function clear() { await transaction('readwrite', function (store) { store.clear(); }); }

    function key() {
        return global.crypto && global.crypto.randomUUID
            ? global.crypto.randomUUID()
            : Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    async function enqueue(files, member) {
        var db = await database();
        var tx = db.transaction(STORE, 'readwrite');
        var store = tx.objectStore(STORE);
        Array.prototype.forEach.call(files, function (file) {
            store.put({
                key: key(),
                blob: file,
                name: file.name,
                type: file.type,
                member: member,
                consent: true,
                status: 'pending'
            });
        });
        await new Promise(function (resolve, reject) {
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
        db.close();
    }

    function mount(options) {
        var status = document.createElement('div');
        status.className = 'mt-3 space-y-2 text-xs';
        options.errorBox.parentNode.insertBefore(status, options.errorBox);

        async function render(message, loginRequired) {
            var records = (await list()).filter(function (record) { return record.member === options.member; });
            status.innerHTML = '';
            if (message) {
                var notice = document.createElement('div');
                notice.className = 'rounded-lg bg-amber-50 p-2 text-amber-800';
                notice.textContent = message;
                if (loginRequired) {
                    var login = document.createElement('a');
                    login.href = '/login';
                    login.className = 'ml-1 font-semibold underline';
                    login.textContent = 'Войти';
                    notice.appendChild(login);
                }
                status.appendChild(notice);
            }
            records.forEach(function (record) {
                var row = document.createElement('div');
                row.className = 'flex items-center justify-between rounded-lg bg-gray-50 p-2';
                row.textContent = record.name + ' — ' + (record.status === 'auth' ? 'нужен вход' : 'ожидает загрузки');
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'ml-2 font-semibold text-red-600';
                cancel.textContent = 'Отменить';
                cancel.onclick = async function () { await remove(record.key); await render(); };
                row.appendChild(cancel);
                status.appendChild(row);
            });
        }

        async function drain() {
            if (drainPromise) return drainPromise;
            drainPromise = (async function () {
                if (!navigator.onLine) { await render('Нет сети — фото сохранены только на этом устройстве.'); return; }
                var records = (await list()).filter(function (record) { return record.member === options.member; });
                for (var i = 0; i < records.length; i++) {
                    var record = records[i];
                    var form = new FormData();
                    form.append('photos', record.blob, record.name);
                    form.append('member', String(record.member || ''));
                    form.append('photoConsent', record.consent ? '1' : '0');
                    var response;
                    try {
                        response = await fetch(options.uploadUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {'X-CSRF-Token': options.csrf, 'X-Idempotency-Key': record.key},
                            body: form
                        });
                    } catch (error) {
                        await render('Соединение прервано — повторим при восстановлении сети.');
                        return;
                    }
                    if (response.status === 401 || (response.redirected && new URL(response.url).pathname === '/login')) {
                        record.status = 'auth';
                        await transaction('readwrite', function (store) { store.put(record); });
                        await render('Сессия закончилась. Очередь сохранена.', true);
                        return;
                    }
                    var data = {};
                    try { data = await response.json(); } catch (error) {}
                    if (!response.ok || !data.ok) {
                        await render(data.error || 'Загрузка не удалась — можно отменить или повторить позже.');
                        return;
                    }
                    await remove(record.key);
                    await render();
                    if (data.reviewUrl) options.reviewUrl = data.reviewUrl;
                }
                if (options.reviewUrl) global.location.href = options.reviewUrl;
            })().finally(function () { drainPromise = null; });
            return drainPromise;
        }

        async function add(files) {
            if (!files || !files.length) return;
            if (!options.consent.checked) {
                options.errorBox.textContent = 'Подтвердите согласие на обработку фото';
                options.errorBox.classList.remove('hidden');
                return;
            }
            options.errorBox.classList.add('hidden');
            await enqueue(files, options.member);
            await render();
            await drain();
        }

        options.area.addEventListener('click', function () { options.fileInput.click(); });
        options.area.addEventListener('dragover', function (event) { event.preventDefault(); });
        options.area.addEventListener('drop', function (event) { event.preventDefault(); add(event.dataTransfer.files); });
        options.fileInput.addEventListener('change', function () { add(options.fileInput.files); });
        global.addEventListener('online', drain);
        render().then(drain);
        return {add: add, drain: drain, cancel: remove, clear: clear, list: list};
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[href$="/logout"]');
        if (!link) return;
        event.preventDefault();
        clear().finally(function () { global.location.href = link.href; });
    });

    global.WardrobeIngestQueue = {mount: mount, clear: clear, list: list};
})(window);
