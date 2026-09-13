const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('playwright');

// Run the actual inline scripts with a small DOM and stubbed HTTP; no AI calls.
function script(name) {
    return readFileSync(join(__dirname, '../../templates/account/wardrobe', name), 'utf8')
        .match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/{{\s*path\('([^']+)'\)\s*}}/g, '/$1')
        .replace(/{{[^}]+}}/g, 'test');
}

test('saved-photo suggestions fill gaps and replace only explicitly selected fields', async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.route('http://wardrobe.test/**', route => {
            const url = route.request().url();
            if (url.endsWith('/')) return route.fulfill({ contentType: 'text/html', body: '<html></html>' });
            return route.fulfill({ json: url.endsWith('ai_token') ? { token: 'test' } : {
                ok: true, fields: { category: 'Рубашки', name: 'AI имя', colorName: 'синий', materialText: 'лён', season: 'summer' }
            }});
        });
        await page.goto('http://wardrobe.test/');
        await page.setContent(`
            <div id="wardrobe-photo-section"><input type="file"></div>
            <input id="wardrobe-ai-photo-consent" type="checkbox" checked>
            <button id="wardrobe-ai-rerun-btn">AI</button>
            <div id="wardrobe-ai-photo-status"></div><div id="wardrobe-ai-photo-diff"></div>
            <select id="wardrobe_item_form_categoryRef"><option value=""></option><option value="7">Рубашки</option></select>
            <input id="wardrobe_item_form_name" value="Моё имя">
            <input id="wardrobe_item_form_colorName" value="белый">
            <textarea id="wardrobe_item_form_materialText"></textarea>
            <select id="wardrobe_item_form_season"><option value=""></option><option value="summer">Лето</option></select>
        `);
        await page.addScriptTag({ content: script('form.html.twig') });
        await page.click('#wardrobe-ai-rerun-btn');
        await page.waitForSelector('[data-ai-field="colorName"]');
        assert.equal(await page.inputValue('#wardrobe_item_form_categoryRef'), '7');
        assert.equal(await page.inputValue('#wardrobe_item_form_season'), 'summer');
        assert.equal(await page.inputValue('#wardrobe_item_form_materialText'), 'лён');
        assert.equal(await page.inputValue('#wardrobe_item_form_colorName'), 'белый');
        await page.check('[data-ai-field="colorName"]');
        await page.click('#wardrobe-ai-photo-diff-apply');
        assert.equal(await page.inputValue('#wardrobe_item_form_colorName'), 'синий');
        assert.equal(await page.inputValue('#wardrobe_item_form_name'), 'Моё имя');
    } finally {
        await browser.close();
    }
});

test('bulk acceptance sends current edits, skips uncertain cards and retains failed cards', async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const accepted = [];
        await page.route('http://wardrobe.test/**', route => {
            if (route.request().method() === 'GET') return route.fulfill({ contentType: 'text/html', body: '<html></html>' });
            accepted.push(route.request().postDataJSON());
            return route.fulfill({ json: { ok: false, error: 'Проверьте категорию' } });
        });
        await page.goto('http://wardrobe.test/');
        await page.setContent(`
            <div id="wardrobe-ingest-root" data-status-url="/status" data-upload-url="/upload" data-csrf="test"></div>
            <div id="ingest-upload-error"></div>
            <div id="ingest-grid">
                <div data-draft-id="1" data-confidence="high" data-accept-url="/accept/1">
                    <input class="ingest-field" data-field="colorName" value="белый"><div class="ingest-error"></div>
                </div>
                <div data-draft-id="2" data-confidence="med" data-accept-url="/accept/2"></div>
            </div>
            <button id="ingest-accept-all-btn">Принять</button>
        `);
        await page.evaluate(() => { window.WardrobeIngestQueue = { mount() {} }; });
        await page.addScriptTag({ content: script('ingest.html.twig') });
        await page.fill('[data-field="colorName"]', 'голубой');
        await page.click('#ingest-accept-all-btn');
        await page.waitForFunction(() => document.querySelector('.ingest-error').textContent.length > 0);
        assert.deepEqual(accepted, [{ colorName: 'голубой' }]);
        assert.equal(await page.locator('[data-draft-id]').count(), 2);
        assert.equal(await page.inputValue('[data-field="colorName"]'), 'голубой');
    } finally {
        await browser.close();
    }
});
