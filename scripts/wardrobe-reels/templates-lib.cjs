// Общая логика каталога шаблонов рилсов: поиск, ассеты, переменные, шот-лист.
// Используют render-template.cjs (рендер) и daily.cjs (выбор кандидата дня).
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const ASSET_EXT = ['jpg', 'jpeg', 'png', 'mp4', 'mov'];
const SEGMENT_ORDER = ['С1', 'С2', 'С3', 'С4', 'С5', 'С6'];

function loadCatalog(file = path.join(root, 'config/social/wardrobe_reels_templates.json')) {
    const catalog = JSON.parse(fs.readFileSync(file, 'utf8'));
    if (catalog.campaign !== 'wardrobe-templates-v1' || !Array.isArray(catalog.templates)) throw new Error('Неожиданный каталог шаблонов');
    return catalog;
}

function findTemplate(catalog, key) {
    const k = String(key).trim();
    const t = catalog.templates.find(x => String(x.id) === k || x.slug === k || x.slug.startsWith(k + '-') && /^\d+$/.test(k));
    if (!t) throw new Error(`Нет шаблона «${key}»`);
    return t;
}
function selectTemplates(catalog, key) {
    if (key === 'all') return catalog.templates;
    return [findTemplate(catalog, key)];
}

const assetsDirFor = (template, base = path.join(root, 'var/wardrobe-reels/assets')) => path.join(base, template.slug);
const shotKey = (beat, k) => String(beat.n).padStart(2, '0') + (beat.shots ? `-${k}` : '');

// Какие файлы нужны ролику: по одному на кадр (бит) или на каждый из beat.shots; loop и «затемнение» файла не требуют.
function requiredShots(template) {
    const list = [];
    for (const beat of template.beats) {
        if (beat.frame.type === 'loop' || beat.frame.fill || beat.frame.sameAs) continue;
        const n = beat.shots || 1;
        for (let k = 1; k <= n; k++) list.push({beat, k, key: shotKey(beat, k)});
    }
    return list;
}
function findAsset(dir, key) {
    for (const ext of ASSET_EXT) {
        const file = path.join(dir, `${key}.${ext}`);
        if (fs.existsSync(file) && fs.statSync(file).size > 0) return file;
    }
    return null;
}
function resolveAssets(template, dir) {
    const found = {}, missing = [];
    for (const shot of requiredShots(template)) {
        const file = findAsset(dir, shot.key);
        if (file) found[shot.key] = file; else missing.push(shot.key);
    }
    return {found, missing};
}

// Переменные берём из vars.json в папке ассетов и из --var; без них остаётся дефолт, а ролик — draft:
// цифры/имена в плашках должны быть реальными.
function resolveVariables(template, dir, cliVars = {}) {
    const file = path.join(dir, 'vars.json');
    const provided = {...(fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {}), ...cliVars};
    const values = {}, missing = [];
    for (const [name, fallback] of Object.entries(template.variables)) {
        if (provided[name] !== undefined && provided[name] !== '') values[name] = provided[name];
        else { values[name] = fallback; missing.push(name); }
    }
    return {values, missing};
}
// Подстановка {имя}. Значение-список (дефолт «вещь», «цвет») для тикера берёт элемент по номеру тика,
// каждое следующее вхождение в одной строке — со сдвигом, чтобы «{цвет} + {цвет}» не повторялось.
function substitute(text, values, tick = 0) {
    let occurrence = 0;
    return text.replace(/\{([^}]+)\}/g, (all, name) => {
        if (!(name in values)) return all;
        const v = values[name];
        return Array.isArray(v) ? String(v[(tick + occurrence++) % v.length]) : String(v);
    });
}

function evaluate(template, assetsBase, cliVars = {}) {
    const dir = assetsDirFor(template, assetsBase);
    const assets = resolveAssets(template, dir);
    const vars = resolveVariables(template, dir, cliVars);
    const reasons = [];
    if (template.status !== 'ready') reasons.push(`статус ${template.status}`);
    if (assets.missing.length) reasons.push(`нет ассетов: ${assets.missing.join(', ')}`);
    if (vars.missing.length) reasons.push(`переменные по умолчанию: ${vars.missing.join(', ')}`);
    return {dir, assets, vars, draft: reasons.length > 0, reasons};
}

// Кандидаты дня: только status=ready, все ассеты и переменные на месте. Порядок — по кругу сегментов С1..С6
// (внутри сегмента по номеру), чтобы соседние дни не были про один и тот же сегмент.
function eligibleRotation(catalog, assetsBase) {
    const bySegment = new Map();
    for (const t of catalog.templates) {
        const e = evaluate(t, assetsBase);
        if (e.draft) continue;
        if (!bySegment.has(t.segment)) bySegment.set(t.segment, []);
        bySegment.get(t.segment).push(t);
    }
    const queues = SEGMENT_ORDER.filter(s => bySegment.has(s)).map(s => bySegment.get(s));
    const order = [];
    for (let round = 0; queues.some(q => round < q.length); round++) {
        for (const q of queues) if (round < q.length) order.push(q[round]);
    }
    return order;
}

const STATUS_LABEL = {ready: '✅ ready', partial: '🟡 partial (после починки функции)', blocked: '🔨 blocked (после выпуска функции)'};
const TYPE_LABEL = {photo: 'ФОТО (снять)', chat: 'СКРИН ЧАТА (снять на своём телефоне, без настоящих имён)', screen: 'ЗАПИСЬ ЭКРАНА PWA (реальная запись, не макет)'};

function shotlistMarkdown(catalog, templates, assetsBase) {
    const out = ['# Шот-лист рилсов гардероба', '',
        'Правила съёмки: вертикально 9:16 (1080×1920 и выше), снимать с запасом по краям. Без лиц детей (спина, руки, ноги, вещь на кровати/вешалке). Взрослых — только по согласию. Без логотипов и названий иностранных брендов. Экран продукта — только реальная запись PWA.',
        'Ключевое: верх ~300 px и низ ~420 px кадра закрыты интерфейсом IG — важное в центр. Плашка с текстом ляжет поверх кадра на высоте ~55%, оставьте там спокойный фон.',
        'Файлы класть в `var/wardrobe-reels/assets/<slug>/` под именами из колонки «Файл» (`.jpg`, `.png` или `.mp4`).', ''];
    for (const t of templates) {
        const e = evaluate(t, assetsBase);
        out.push(`## №${t.id} ${t.title}`, '',
            `- slug: \`${t.slug}\` · сегмент ${t.segment} · ${STATUS_LABEL[t.status]} · ${t.format} ${t.durationSec} с`,
            `- папка: \`var/wardrobe-reels/assets/${t.slug}/\``);
        if (t.status !== 'ready') out.push(`- ⚠️ ${t.status === 'blocked' ? 'Функции нет — экранные кадры снимать рано, бытовые можно заранее.' : 'Функция работает частично — экранные кадры после починки.'} ${t.statusNote}`);
        else if (/не показывать/.test(t.statusNote)) out.push(`- Внимание: ${t.statusNote}`);
        const vars = Object.keys(t.variables);
        if (vars.length) out.push(`- реальные значения (иначе ролик останется черновиком) — \`vars.json\`: ${vars.map(v => `{${v}}`).join(' ')}`);
        out.push(`- что снимать (из шаблона): ${t.shoot}`, '', '| Файл | Бит | Что снять | Плашка | Длит., с |', '|---|---|---|---|---|');
        for (const shot of requiredShots(t)) {
            const b = shot.beat;
            const part = b.shots ? ` (кадр ${shot.k} из ${b.shots})` : '';
            const done = e.assets.found[shot.key] ? 'есть' : '**снять**';
            const plate = ((b.plates && b.plates[(shot.k - 1) % b.plates.length]) || b.plate || '—').replace(/\n/g, ' / ');
            const dur = ((b.end - b.start) / (b.shots || 1)).toFixed(1);
            out.push(`| \`${shot.key}\` ${done} | ${b.n}${part} | ${TYPE_LABEL[b.frame.type]}: ${b.frame.desc} | ${plate} | ${dur} |`);
        }
        out.push('', `Луп: последний бит повторяет кадр 1 (${t.loopTo || 'тот же кадр'}) — отдельный файл не нужен.`, '');
    }
    return out.join('\n');
}

module.exports = {root, loadCatalog, findTemplate, selectTemplates, assetsDirFor, requiredShots, shotKey, findAsset, resolveAssets, resolveVariables, substitute, evaluate, eligibleRotation, shotlistMarkdown, SEGMENT_ORDER};
