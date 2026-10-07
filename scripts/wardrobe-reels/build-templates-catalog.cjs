#!/usr/bin/env node
// Одноразовый разбор docs/wardrobe_reels_templates.md -> config/social/wardrobe_reels_templates.json.
// Источник правды по сценариям — md; JSON пересобирается этим скриптом (node build-templates-catalog.cjs).
// Всё, что не вытаскивается из прозы md надёжно (чаптеры, тикеры, склейки плашек), лежит в OVERRIDES ниже.
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const source = path.join(root, 'docs/wardrobe_reels_templates.md');
const target = path.join(root, 'config/social/wardrobe_reels_templates.json');

const STATUS = {'✅': 'ready', '🟡': 'partial', '🔨': 'blocked'};

// Значения по умолчанию для черновиков. Для публикации они НЕ годятся: цифры в плашках — только реальные
// (правило шаблонов), поэтому рендер с дефолтами всегда помечается draft. Список = тикер по элементам.
const DEFAULTS = {
    'вещь': ['водолазка', 'юбка', 'платье', 'брюки', 'блузка', 'жакет', 'шарф', 'топ'],
    'цвет': ['чёрная', 'бежевая', 'синяя', 'серая', 'белая', 'зелёная', 'бордовая', 'коричневая'],
    'размер': '116', 'рост': '110', 'возраст': '6', 'имя': 'Маша', 'N': '7', 'M': '40', 'сумма': '12 400',
    'год 1': '2019', 'год 2': '2021', 'год 3': '2024', 'имя 1': 'Маша', 'имя 2': 'Петя', 'имя 3': 'Саша',
    'повод': 'работа', 'срок': 'месяц', 'город': 'Москва', 'день': 'пятницу', 'число_детей': 'трое',
};
const TRANSLIT = {а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'c',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya'};
// Короткие slug для первых пяти в производство и просто читаемые; остальные — автотранслит названия.
const SLUGS = {
    1: 'visit-s-birkami', 11: 'kakoy-u-neyo-razmer', 12: 'kolgotki-116', 17: 'kurtku-nosili-troe', 28: 'veschey-gde-kazhdaya',
};

// Ручные правки битов: ключ "номер.номер_бита" (с 1). Здесь — то, что md описывает прозой («×8 по 0.2 с», «главы»).
// shots: N отдельных кадров-ассетов внутри бита (NN-1…NN-N); plates: плашки, циклично по длине бита.
const OVERRIDES = {
    '5.2': {plate: 'Понедельник', shots: 3},
    '5.3': {plates: ['Вторник', 'Вторник', 'Вторник', 'Среда', 'Среда', 'Среда', 'Четверг', 'Четверг', 'Четверг', 'Пятница', 'Пятница', 'Пятница'], shots: 12},
    '6.2': {plates: ['{вещь} — 0 раз'], ticker: 8},
    '9.2': {plates: ['{цвет} + {цвет}'], ticker: 8},
    '10.2': {plate: 'Её', shots: 6},
    '10.3': {plate: 'Его', shots: 6},
    '14.2': {plates: ['{вещь} — мало'], ticker: 8},
    '15.2': {plates: ['Низ', 'Верх', 'Носки и бельё'], shots: 9},
    '16.2': {plates: ['Форма — {сумма}', 'Обувь — {сумма}', 'Сменка — {сумма}']},
    '19.2': {plate: '{Старший}: {сумма} ₽', shots: 4},
    '19.3': {plate: '{Средний}: {сумма} ₽', shots: 4},
    '19.4': {plate: '{Младший}: {сумма} ₽', shots: 4},
    '21.2': {plates: ['младшему', 'Авито', 'отдать'], ticker: 9},
    '22.2': {plates: ['Сапоги ×3', 'Комбинезон ×2']},
    '25.2': {plate: 'Ухожу', shots: 5},
    '25.3': {plate: 'Остаётся', shots: 5},
    '25.4': {plate: 'Капсула', shots: 4},
    '26.2': {plates: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']},
    '28.2': {plates: ['химчистка', 'ремонт подошвы', 'в стирке', 'на месте'], ticker: 10},
    '30.2': {plate: 'Заказано', shots: 4},
    '30.3': {plate: 'В пути'},
    // #4: фото одно на весь ролик — последний бит «снова шкаф» это тот же кадр 1 (луп), а не отдельный ассет.
    '2.3': {shots: 4}, '8.3': {shots: 3}, '12.3': {shots: 3}, '20.3': {shots: 4},
    '3.2': {plate: '{цена} ₽ · надела {N} раз'},
    '4.4': {loop: true},
    '10.5': {loop: true},
    '16.5': {loop: true},
    '22.5': {loop: true},
};

function translit(text) {
    return text.toLowerCase().replace(/\{[^}]*\}/g, '').replace(/[а-яё]/g, c => TRANSLIT[c]).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}
const num = s => Number(s.replace(',', '.'));

// Режет строку по « — » вне «…», чтобы тире внутри плашек не ломало разбор.
function splitDash(text) {
    const parts = [];
    let depth = 0, cur = '';
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (ch === '«') depth++;
        if (ch === '»') depth = Math.max(0, depth - 1);
        if (depth === 0 && text.startsWith(' — ', i)) { parts.push(cur); cur = ''; i += 2; continue; }
        cur += ch;
    }
    parts.push(cur);
    return parts.map(p => p.trim());
}
const unquote = s => s.replace(/^«/, '').replace(/»[.;,]?$/, '');
function variablesIn(text) {
    return [...text.matchAll(/\{([^}]+)\}/g)].map(m => m[1]);
}

const PLATE_LIKE = /^(хук(?![а-яё])|та же плашка|без текста|смена плашки:\s*«|«)/;

function parseBeats(lines, id) {
    const beats = [];
    for (const line of lines) {
        const m = line.match(/^\s+- ([\d.,]+)–([\d.,]+) — (.+)$/);
        if (!m) throw new Error(`#${id}: не разобран бит: ${line}`);
        const [, a, b, rest] = m;
        let silence = null;
        let text = rest.replace(/[;,]?\s*(?:затем\s+)?(?:(\d[.,]?\d*)\s*с\s+тишины|тишина\s+(\d[.,]?\d*)\s*с|тишина)/, (all, x, y) => {
            silence = num(x || y || '0.5');
            return '';
        });
        const parts = splitDash(text);
        // Плашка — последняя часть после « — », если она похожа на плашку; всё прочее — описание кадра.
        const last = parts.length > 1 ? parts.at(-1) : '';
        const hasTail = PLATE_LIKE.test(last);
        const head = hasTail ? parts.slice(0, -1).join(' — ') : parts.join(' — ');
        const tail = hasTail ? last : '';
        const beat = {start: num(a), end: num(b), head, tail, silence};
        beats.push(beat);
    }
    return beats;
}

function classifyFrame(head) {
    if (/^(снова )?кадр 1\b/.test(head)) return 'loop';
    if (/запись экрана/.test(head)) return 'screen';
    if (/^(экран чата|экран телефона|прокрутка чата)/.test(head)) return 'chat';
    return 'photo';
}

function build(md) {
    const blocks = md.split(/^### (?=\d+\. )/m).slice(1);
    const templates = [];
    for (const block of blocks) {
        const lines = block.split('\n');
        const [, idText, title] = lines[0].match(/^(\d+)\. (.+)$/);
        const id = Number(idText);
        const field = label => {
            const re = new RegExp(`^- \\*\\*${label}[^*]*\\*\\*\\s*(.*)$`);
            const l = lines.find(x => re.test(x));
            return l ? l.match(re)[1].trim() : null;
        };
        const segmentLine = field('Сегмент');
        const segment = segmentLine.match(/^(С\d)/)[1];
        const pain = (segmentLine.match(/\*\*Боль:\*\*\s*«(.+)»/) || [])[1] || null;
        const statusLine = field('Статус');
        const status = STATUS[statusLine.match(/✅|🟡|🔨/)[0]];
        const formatLine = field('Формат');
        const fm = formatLine.match(/^([\w\u0400-\u04FF-]+)\s+(\d+)\s*с(?:,\s*([^·]+?))?\s*·\s*\*\*Задача:\*\*\s*(.+?)\.?$/);
        if (!fm) throw new Error(`#${id}: формат: ${formatLine}`);
        const hookLine = field('Хук');
        const hookParts = splitDash(hookLine)[0].trim().replace(/^«/, '').replace(/»$/, '').split(/»\s*\/\s*«/);
        if (hookParts.length !== 2) throw new Error(`#${id}: хук: ${hookLine}`);
        const hook = hookParts.join('\n');
        const i0 = lines.findIndex(x => /^- \*\*Биты:\*\*/.test(x));
        const beatLines = [];
        for (let i = i0 + 1; i < lines.length && /^\s+- /.test(lines[i]); i++) beatLines.push(lines[i]);
        const raw = parseBeats(beatLines, id);
        const slug = `${String(id).padStart(2, '0')}-${SLUGS[id] || translit(title)}`;
        const gateLine = field('Гейт');
        const gateQuote = gateLine.match(/^«(.+?)»/);
        const captionLine = field('Подпись');
        const caption = captionLine.match(/^«(.*)»[^»]*$/s)[1];
        const loopLine = field('Луп');

        let prevPlate = '';
        const beats = raw.map((r, i) => {
            const n = i + 1;
            const ov = OVERRIDES[`${id}.${n}`];
            let type = ov && ov.loop ? 'loop' : classifyFrame(r.head);
            let plate = '';
            let plateSource = 'none';
            const tail = r.tail;
            if (/^хук(?![а-яё])/.test(tail)) { plate = hook; plateSource = 'hook'; }
            else if (/^та же плашка/.test(tail)) { plate = prevPlate; plateSource = 'same'; }
            else if (/^без текста/.test(tail)) { plate = ''; }
            else if (/^смена плашки:\s*«/.test(tail)) { plate = unquote(tail.replace(/^смена плашки:\s*/, '')); plateSource = 'text'; }
            else if (/^«/.test(tail)) { plate = unquote(tail); plateSource = 'text'; }
            else if (type === 'loop' && !tail) { plate = hook; plateSource = 'hook'; }
            // «Подпись внутри прозы»: если плашка не нашлась, а в описании есть единственная цитата — это плашка главы.
            const dark = /^затемнение/.test(r.head);
            const frame = {
                type: type === 'loop' ? 'loop' : type,
                desc: r.head.replace(/\s+/g, ' '),
                asset: null,
            };
            if (dark) { frame.fill = 'dark'; }
            // «тот же кадр»: бит продолжает предыдущий ассет (другая плашка/движение), отдельный файл не нужен.
            if (/тот же кадр|том же кадре/.test(r.head) && i > 0) frame.sameAs = i;
            const beat = {n, start: r.start, end: r.end, frame, plate, plateSource, silenceAfter: 0};
            if (r.silence !== null && !dark) beat.silenceAfter = r.silence;
            if (ov) {
                const {loop, ...rest} = ov;
                if (loop) { beat.frame.type = 'loop'; }
                if (rest.plate !== undefined) { beat.plate = rest.plate; beat.plateSource = 'text'; }
                if (rest.plates) { beat.plates = rest.plates; beat.plate = rest.plates[0]; beat.plateSource = 'text'; }
                if (rest.shots) beat.shots = rest.shots;
                if (rest.ticker) beat.ticker = rest.ticker;
            }
            prevPlate = beat.plate;
            return beat;
        });
        // «затемнение, тишина»: тишина занимает сам тёмный бит — отдаём её предыдущему биту как окно после него.
        raw.forEach((r, i) => {
            if (/^затемнение/.test(r.head) && r.silence !== null && i > 0) beats[i - 1].silenceAfter = beats[i].end - beats[i].start;
        });
        // Ассеты: loop смотрит на бит 1, остальные — NN (+ -k для shots). Расширение выберет рендер (jpg|png|mp4).
        const assetBase = `var/wardrobe-reels/assets/${slug}`;
        beats.forEach(b => {
            if (b.frame.type === 'loop') { b.frame.loopOf = 1; b.frame.asset = null; }
            else if (b.frame.fill || b.frame.sameAs) { b.frame.asset = null; }
            else b.frame.asset = `${assetBase}/${String(b.n).padStart(2, '0')}`;
        });
        // Бит 1 никогда не loop; последний бит со словом «кадр 1» — loop (проверка ниже).
        const last = beats.at(-1);
        if (last.frame.type !== 'loop') throw new Error(`#${id}: последний бит не loop (${last.frame.desc})`);
        if (beats[0].start !== 0) throw new Error(`#${id}: бит 1 начинается не с 0`);
        if (!beats[0].plate) throw new Error(`#${id}: на 0-й секунде нет плашки`);
        for (let i = 1; i < beats.length; i++) {
            if (Math.abs(beats[i].start - beats[i - 1].end) > 1e-6) throw new Error(`#${id}: дыра между битами ${i} и ${i + 1}`);
        }
        if (Math.abs(last.end - Number(fm[2])) > .6) throw new Error(`#${id}: длительность ${last.end} ≠ ${fm[2]}`);

        // Переменные: только реально встречающиеся; дефолт — известный или сама подстановка («{трое}» -> «трое»).
        const textBlob = [title, hook, caption, gateQuote ? gateQuote[1] : '', ...beats.flatMap(b => [b.plate, ...(b.plates || [])])].join('\n');
        const variables = {};
        for (const v of variablesIn(textBlob)) variables[v] = DEFAULTS[v] ?? v;

        templates.push({
            id, slug, title, segment, status,
            statusNote: statusLine,
            format: fm[1],
            durationSec: Number(fm[2]),
            formatNote: fm[3] ? fm[3].trim() : null,
            goal: fm[4],
            pain,
            hook,
            beats,
            loopTo: loopLine ? loopLine.replace(/\.$/, '') : null,
            caption,
            gate: gateQuote ? gateQuote[1] : null,
            gateNote: gateQuote ? null : gateLine,
            shoot: field('Кадры'),
            variables,
        });
    }
    return templates;
}

const templates = build(fs.readFileSync(source, 'utf8'));
if (templates.length !== 30 || templates.some((t, i) => t.id !== i + 1)) throw new Error(`Ожидалось 30 шаблонов по порядку, разобрано ${templates.length}`);
const slugs = new Set(templates.map(t => t.slug));
if (slugs.size !== 30) throw new Error('Повтор slug');
const out = {
    version: 1,
    campaign: 'wardrobe-templates-v1',
    source: 'docs/wardrobe_reels_templates.md',
    note: 'Сгенерировано scripts/wardrobe-reels/build-templates-catalog.cjs. Не править руками — править md/скрипт.',
    cta_url: 'https://wearbase.ru/ru/wardrobe',
    cta_label: 'Цифровой гардероб',
    templates,
};
fs.writeFileSync(target, JSON.stringify(out, null, 2) + '\n');
const count = s => templates.filter(t => t.status === s).length;
console.log(`Шаблонов: ${templates.length} (ready ${count('ready')} · partial ${count('partial')} · blocked ${count('blocked')}) -> ${path.relative(root, target)}`);
