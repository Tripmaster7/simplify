/*
 * Tests for the InDesign-independent part of ArtikelImport.jsx (DOCX rules and print paragraphs).
 * Run: node indesign-article-import/tests/core.test.js
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'ArtikelImport.jsx'), 'utf8');
const context = vm.createContext({});
vm.runInContext(source + '\n;this.AIW = AIW;', context);
const AIW = context.AIW;

// Values from the script's VM realm, as plain values of this realm.
const plain = (value) => JSON.parse(JSON.stringify(value));
const eq = (actual, expected) => assert.deepStrictEqual(plain(actual), expected);

const B = AIW.FORMAT_MARKERS[1];
const I = AIW.FORMAT_MARKERS[2];
const N = AIW.FORMAT_MARKERS[0];

function p(text, style, list) {
    return { text: text, style: style || 'Normal', list: list || null };
}

function model(paragraphs, styles, details, links) {
    const parsed = AIW.parseDocument(paragraphs);
    const print = AIW.printModel(parsed, AIW.completeStyles(styles || {}), links || [], details || { authorName: 'Anna Autorin', bio: parsed.metadata.bio || '' });
    return { parsed, print };
}

function texts(paragraphs) {
    return paragraphs.map((paragraph) => paragraph.style + ': ' + paragraph.runs.map((run) => run.text).join(''));
}

const tests = [];
function test(name, fn) {
    tests.push([name, fn]);
}

test('metadata markers are read and removed', () => {
    const { parsed } = model([
        p('[TITLE: Pleiten, Pech und Pannen]'),
        p('[SUBTITLE:Stolpersteine unterwegs]'),
        p('[AUTHOR_MEMBERSHIP: 01234] [WRITING_DATE: 2026-05-14]'),
        p('[REISEZEIT: Mai bis August 2024]'),
        p('[AUTHOR_NAME: Christel Loock]'),
        p('Erster Absatz.'),
    ]);
    assert.strictEqual(parsed.title, 'Pleiten, Pech und Pannen');
    assert.strictEqual(parsed.subtitle, 'Stolpersteine unterwegs');
    assert.strictEqual(parsed.metadata.author_membership, '01234');
    assert.strictEqual(parsed.metadata.writing_date, '2026-05-14');
    assert.strictEqual(parsed.metadata.travel_period, 'Mai bis August 2024');
    assert.strictEqual(parsed.metadata.author_name, 'Christel Loock');
    eq(parsed.blocks.map((b) => b.text), ['Erster Absatz.']);
});

test('first value wins, duplicates are flagged', () => {
    const { parsed } = model([p('[TITLE: Eins]'), p('[TITLE: Zwei]'), p('Text')]);
    assert.strictEqual(parsed.title, 'Eins');
    assert.strictEqual(parsed.metadata.title_duplicate, true);
});

test('title falls back to Word title style, then first paragraph', () => {
    assert.strictEqual(model([p('Mein Titel', 'Title'), p('Text')]).parsed.title, 'Mein Titel');
    assert.strictEqual(model([p('Mein Titel', 'Überschrift 1'), p('Text')]).parsed.title, 'Mein Titel');
    assert.strictEqual(model([p('[AUTHOR_MEMBERSHIP: 1]'), p('Erster  Absatz')]).parsed.title, 'Erster Absatz');
});

test('fallback title is removed only from its source paragraph (plugin 1.3.8)', () => {
    eq(model([p('Beispieltitel'), p('Text'), p('Beispieltitel')]).parsed.blocks.map((b) => b.text), ['Text', 'Beispieltitel']);
    eq(model([p('[TITLE: Titel]'), p('Erster Absatz')]).parsed.blocks.map((b) => b.text), ['Erster Absatz']);
    eq(model([p('Erster Absatz'), p('[TITLE: Titel]')]).parsed.blocks.map((b) => b.text), ['Erster Absatz']);
    const list = { level: 0, ordered: false };
    const { parsed } = model([p('Punkt eins', null, list), p('Punkt zwei', null, list), p('Schluss')]);
    assert.strictEqual(parsed.title, 'Punkt eins');
    eq(parsed.blocks[0].items.map((item) => item.text), ['Punkt eins', 'Punkt zwei']);
});

test('header tags become heading levels; level 1 is the title', () => {
    const { parsed, print } = model([
        p('[HEADER 1 Titel aus Header]'),
        p('Vorspann.'),
        p('[HEADER:  Ankunft in Cancun]'),
        p('Text 1'),
        p('[HEADER 3 Hintergrund]'),
        p('[Header 4 Detail]'),
        p('[HEADER Warnungen}'),
    ]);
    assert.strictEqual(parsed.title, 'Titel aus Header');
    eq(texts(print.body), [
        'heading2: Ankunft in Cancun',
        'body: Text 1',
        'heading3: Hintergrund',
        'heading4: Detail',
        'body: [HEADER Warnungen}',
    ]);
});

test('unrecognized markers are reported', () => {
    const { parsed } = model([p('[TITLE: T]'), p('V'), p('[HEADER Warnungen}'), p('Ein Bild [Bild 1: ok] und [Bild: ohne Nummer].'), p('[RESTRICT]')]);
    eq(AIW.unrecognizedMarkers(parsed), ['[HEADER Warnungen}', '[Bild: ohne Nummer]']);
});

test('first paragraph is the Vorspann; body_first only when configured', () => {
    let { print } = model([p('[TITLE: T]'), p('Vorspann.'), p('Erster.'), p('Zweiter.')]);
    eq(texts(print.vorspann), ['vorspann: Vorspann.']);
    eq(texts(print.body), ['body: Erster.', 'body: Zweiter.']);

    ({ print } = model([p('[TITLE: T]'), p('Vorspann.'), p('Erster.'), p('Zweiter.'), p('[HEADER Z]'), p('Nach Z.'), p('Mehr.')], { body_first: 'Ohne Einzug' }));
    eq(texts(print.body), ['body_first: Erster.', 'body: Zweiter.', 'heading2: Z', 'body_first: Nach Z.', 'body: Mehr.']);
});

test('picture markers split paragraphs; captions are collected', () => {
    const { parsed, print } = model([
        p('[TITLE: T]'),
        p('Vorspann.'),
        p('Vorher [Bild 1: Der Markt] nachher.'),
        p('[Image 2]'),
    ]);
    eq(AIW.imageMarkers(parsed), [{ number: 1, caption: 'Der Markt' }, { number: 2, caption: '' }]);
    eq(texts(print.body), ['body: Vorher', 'body: nachher.']);
    assert.deepStrictEqual(Object.assign({}, print.captions), { 1: 'Der Markt' });
    eq(texts(print.full).filter((t) => /^(image|caption)/.test(t)), ['image: [Bild 1]', 'caption: Der Markt', 'image: [Bild 2]']);
});

test('formatting split inside a marker does not break it', () => {
    const { print } = model([p('[TITLE: T]'), p('V'), p('Text ' + B + '[Bild' + N + ' 1: Fett]' + N + ' weiter')]);
    eq(texts(print.body), ['body: Text', 'body: weiter']);
    assert.strictEqual(print.captions[1], 'Fett');
});

test('[RESTRICT] is removed in print', () => {
    const { print } = model([p('[TITLE: T]'), p('V'), p('Eins.'), p('[RESTRICT] '), p('Zwei [RESTRICT] drei.')]);
    // Same as the plugin's InDesign export: the spaces on both sides of the marker stay.
    eq(texts(print.body), ['body: Eins.', 'body: Zwei  drei.']);
});

test('bold and italic become runs; links carry their URL', () => {
    const links = ['https://example.com'];
    const text = 'Normal ' + B + 'fett' + N + ' und ' + I + 'kursiv' + AIW.FORMAT_MARKERS[3] + ' beides' + N + ' ' +
        AIW.LINK_OPEN + '0' + AIW.LINK_SEPARATOR + 'Link' + AIW.LINK_CLOSE + '.';
    const { print } = model([p('[TITLE: T]'), p('V'), p(text)], {}, null, links);
    const runs = print.writer.mergeRuns(print.body[0].runs);
    eq(runs.map((r) => [r.text, r.format, r.link]), [
        ['Normal ', 0, ''],
        ['fett', 1, ''],
        [' und ', 0, ''],
        ['kursiv', 2, ''],
        [' beides', 3, ''],
        [' ', 0, ''],
        ['Link', 0, 'https://example.com'],
        ['.', 0, ''],
    ]);
});

test('paragraph with a marker loses its formatting', () => {
    const { print } = model([p('[TITLE: T]'), p('V'), p(B + 'Fett [REISEZEIT: Mai]' + N + ' Rest')]);
    eq(print.body[0].runs.map((r) => r.format), [0]);
});

test('multi-paragraph bio is joined; blank lines separate bio paragraphs', () => {
    const { parsed, print } = model([p('[TITLE: T]'), p('V'), p('[BIO: Erste Zeile'), p('zweiter Absatz'), p('dritter.]'), p('Nach der Bio.')]);
    assert.strictEqual(parsed.metadata.bio, 'Erste Zeile\n\nzweiter Absatz\n\ndritter.');
    eq(texts(print.bio), ['bio: Erste Zeile', 'bio: zweiter Absatz', 'bio: dritter.']);
    eq(texts(print.body), ['body: Nach der Bio.']);
    eq(texts(print.full).slice(-4), ['bio_name: Anna Autorin', 'bio: Erste Zeile', 'bio: zweiter Absatz', 'bio: dritter.']);
});

test('unclosed bio is reported and kept as text', () => {
    const { parsed } = model([p('[TITLE: T]'), p('V'), p('[BIO: Ohne Ende'), p('Weiter.')]);
    assert.strictEqual(parsed.metadata.bio_unclosed, true);
    assert.strictEqual(parsed.metadata.bio, undefined);
});

test('lists: bullets/numbers as text without list styles, restart and nesting', () => {
    const bullet = (level) => ({ level, ordered: false });
    const number = (level) => ({ level, ordered: true });
    const { print } = model([
        p('[TITLE: T]'), p('V'),
        p('Eins', 'List Paragraph', number(0)),
        p('Zwei', 'List Paragraph', number(0)),
        p('Unterpunkt', 'List Paragraph', bullet(1)),
        p('Drei', 'List Paragraph', number(0)),
        p('Text'),
        p('Punkt', 'List Paragraph', bullet(0)),
        p('Neu eins', 'List Paragraph', number(0)),
    ]);
    const writer = print.writer;
    const out = print.body.map((paragraph) => paragraph.style + (paragraph.restart ? '*' : '') + ': ' +
        writer.outputRuns(paragraph, writer.resolveRole(paragraph.style)).map((r) => r.text).join(''));
    eq(out, [
        'list_number*: 1.\tEins',
        'list_number: 2.\tZwei',
        'list_bullet_2: –\tUnterpunkt',
        'list_number: 3.\tDrei',
        'body: Text',
        'list_bullet: •\tPunkt',
        'list_number*: 1.\tNeu eins',
    ]);
});

test('role fallback follows the plugin', () => {
    const writer = new AIW.Writer(AIW.completeStyles({}));
    const exists = (name) => ['Fliesstext', 'Zwischenueberschrift'].indexOf(name) !== -1;
    assert.strictEqual(writer.resolveRole('heading4', exists), 'heading2');
    assert.strictEqual(writer.resolveRole('caption', exists), 'body');
    assert.strictEqual(writer.resolveRole('author', exists), 'body');
    assert.strictEqual(writer.resolveRole('body', () => false), '');
});

test('required styles fall back to their defaults', () => {
    const styles = AIW.completeStyles({ title: '', body: ' Text : Haupttext ', caption: '' });
    assert.strictEqual(styles.title, 'Artikeltitel');
    assert.strictEqual(styles.body, 'Text:Haupttext');
    assert.strictEqual(styles.caption, '');
});

test('template placeholders are found and normalized', () => {
    const tokens = AIW.findTokens('Text und Fotos: [AUTOR]\r[ bild  2 ]\r[BILDUNTERSCHRIFT3][AUTORENBILD][TEXT]');
    eq(tokens.map((t) => t.token), ['AUTOR', 'BILD 2', 'BILDUNTERSCHRIFT 3', 'AUTORENBILD', 'TEXT']);
    assert.strictEqual(tokens[0].index, 16);
    assert.ok(AIW.isPictureToken('BILD 2') && AIW.isPictureToken('AUFMACHERBILD') && !AIW.isPictureToken('BILDUNTERSCHRIFT 3'));
});

test('picture files are matched by name', () => {
    assert.strictEqual(AIW.pictureKey('Titelbild.jpg'), 'AUFMACHERBILD');
    assert.strictEqual(AIW.pictureKey('Aufmacherbild.PNG'), 'AUFMACHERBILD');
    assert.strictEqual(AIW.pictureKey('BioPic.jpg'), 'AUTORENBILD');
    assert.strictEqual(AIW.pictureKey('Bild_1.jpg'), 'BILD 1');
    assert.strictEqual(AIW.pictureKey('Bild-02.tif'), 'BILD 2');
    assert.strictEqual(AIW.pictureKey('Image 12.psd'), 'BILD 12');
    assert.strictEqual(AIW.pictureKey('Bild_1.docx'), '');
    assert.strictEqual(AIW.pictureKey('Urlaub.jpg'), '');
});

let failed = 0;
for (const [name, fn] of tests) {
    try {
        fn();
        console.log('ok   ' + name);
    } catch (error) {
        failed++;
        console.log('FAIL ' + name + '\n     ' + String(error.message).split('\n').join('\n     '));
    }
}
console.log(`\n${tests.length - failed}/${tests.length} passed`);
process.exit(failed ? 1 : 0);
