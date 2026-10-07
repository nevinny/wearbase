const {test} = require('node:test');
const assert = require('node:assert/strict');
const {normalizeWords, diffWords, verdict, expectedFromManifest} = require('../../scripts/wardrobe-reels/qa-transcript.cjs');

test('normalization ignores case, punctuation and ё', () => {
    assert.deepEqual(normalizeWords('Ещё — ВСЁ, «нечего» надеть?!'), ['еще', 'все', 'нечего', 'надеть']);
});

test('identical narration passes', () => {
    const diff = diffWords('Шкаф полный. Нечего надеть?', 'шкаф полный, нечего надеть');
    assert.equal(diff.matched, 4);
    assert.deepEqual(diff.missing, []);
    assert.equal(verdict(diff).ok, true);
});

test('clipped last word (the -shortest artefact) fails as a tail loss', () => {
    const diff = diffWords('Добавь вещи и собирай образы каждое утро', 'добавь вещи и собирай образы каждое');
    assert.deepEqual(diff.tailMissing, ['утро']);
    const v = verdict(diff);
    assert.equal(v.ok, false);
    assert.match(v.reasons[0], /утро/);
});

test('one lost word in the middle of a long script is tolerated, many are not', () => {
    const script = 'раз два три четыре пять шесть семь восемь девять десять одиннадцать двенадцать';
    const one = diffWords(script, script.replace('пять ', ''));
    assert.deepEqual(one.missing.map(m => m.word), ['пять']);
    assert.equal(verdict(one).ok, true);
    const many = diffWords(script, 'раз девять десять одиннадцать двенадцать');
    assert.equal(verdict(many).ok, false);
});

test('extra heard words are reported but do not fail on their own', () => {
    const diff = diffWords('Открыла шкаф', 'ну открыла шкаф');
    assert.deepEqual(diff.extra, ['ну']);
    assert.equal(verdict(diff).ok, true);
});

test('expected text comes from manifest voice scenes; silent reels are rejected', () => {
    assert.equal(expectedFromManifest({voice: {scenes: [{text: 'Шкаф полный.'}, {text: 'Начни сегодня.'}]}}), 'Шкаф полный. Начни сегодня.');
    assert.throws(() => expectedFromManifest({audio: 'bed.m4a'}), /без озвучки/);
});

test('brand name matches however whisper spells it, but a clipped brand still fails', () => {
    const script = 'Начни с пяти вещей в цифровом гардеробе Веар бейс.';
    assert.equal(verdict(diffWords(script, 'Начни с пяти вещей в цифровом гардеробе Вербейс.')).ok, true);
    assert.equal(verdict(diffWords(script, 'Начни с пяти вещей в цифровом гардеробе в Airbase.')).ok, true);
    const clipped = diffWords(script, 'Начни с пяти вещей в цифровом гардеробе');
    assert.deepEqual(clipped.tailMissing, ['WEARBASE']);
    assert.equal(verdict(clipped).ok, false);
});
