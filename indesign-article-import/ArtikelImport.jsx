//@target indesign
/*
 * Artikel-Import (Article Import) for Adobe InDesign
 * Version 1.0.0
 *
 * Imports a Word article (.docx) written for the Article Import Wizard into the open InDesign
 * document. It reads the same markers as the WordPress plugin ([TITLE: ...], [SUBTITLE: ...],
 * [HEADER 3 ...], [Bild 1: caption], [BIO: ...], [REISEZEIT: ...], ...) and fills the same
 * template placeholders as the plugin's InDesign export ([TITEL], [UNTERTITEL], [AUTOR],
 * [REISEZEIT], [VORSPANN], [TEXT], [BIO], [BILDUNTERSCHRIFT n], [AUFMACHERBILD], [BILD n],
 * [AUTORENBILD]).
 *
 * A document without placeholders also works: the article is then set into the selected text
 * frame or at the text cursor, with paragraph styles chosen under "Styles...".
 *
 * Install: copy this file into InDesign's Scripts folder (Window > Utilities > Scripts, right-click
 * "User" > Reveal in Explorer/Finder) and double-click it in the Scripts panel.
 *
 * The file is plain ASCII on purpose (German text uses \u escapes), so ExtendScript reads it the
 * same way on every system.
 */

// =================================================================================================
// Core: DOCX paragraphs -> article -> print paragraphs. No InDesign calls, so it runs in Node tests.
// Ported from AIW_DOCX_Parser and AIW_ICML_Writer of the WordPress plugin; keep them in sync.
// =================================================================================================

var AIW = (function () {
    var A = {};

    A.VERSION = '1.0.0';

    /*
     * Paragraph text carries inline markup as private-use characters (as in AIW_DOCX_Parser):
     * - Links: LINK_OPEN <index into linkTargets> LINK_SEPARATOR <link text> LINK_CLOSE.
     * - Formatting: FORMAT_MARKERS[mask] whenever bold/italic changes (mask: 1 bold, 2 italic).
     * "\n" is a line break (Shift+Enter).
     */
    A.LINK_OPEN = '\uE000';
    A.LINK_SEPARATOR = '\uE001';
    A.LINK_CLOSE = '\uE002';
    A.FORMAT_MARKERS = ['\uE010', '\uE011', '\uE012', '\uE013'];
    A.FORMAT_BOLD = 1;
    A.FORMAT_ITALIC = 2;

    /** Line break inside a paragraph; InDesign turns "\n" into a forced line break. */
    var LINE_BREAK = '\n';

    /** Default style names: the names of the demo article template (as in the plugin). */
    A.DEFAULT_STYLES = {
        title: 'Artikeltitel',
        subtitle: 'Untertitel',
        author: 'Untertitel',
        vorspann: 'Vorspann',
        body_first: '',
        body: 'Fliesstext',
        heading2: 'Zwischenueberschrift',
        heading3: '',
        heading4: '',
        list_bullet: '',
        list_bullet_2: '',
        list_number: '',
        list_number_2: '',
        image: '',
        caption: 'Bildunterschrift',
        bio_name: 'Zwischenueberschrift',
        bio: 'Vorspann',
        bold: '',
        italic: '',
        bold_italic: '',
        link: ''
    };

    A.PARAGRAPH_ROLES = ['title', 'subtitle', 'author', 'vorspann', 'body_first', 'body', 'heading2', 'heading3', 'heading4',
        'list_bullet', 'list_bullet_2', 'list_number', 'list_number_2', 'image', 'caption', 'bio_name', 'bio'];
    A.CHARACTER_ROLES = ['bold', 'italic', 'bold_italic', 'link'];

    /** Roles that always have a style; an empty name falls back to the default. */
    A.REQUIRED_ROLES = ['title', 'body'];

    /** Role used when a role has no style. Chains end at "body". */
    var FALLBACK_ROLES = {
        subtitle: 'body',
        author: 'subtitle',
        vorspann: 'body',
        body_first: 'body',
        heading2: 'body',
        heading3: 'heading2',
        heading4: 'heading3',
        list_bullet: 'body',
        list_bullet_2: 'list_bullet',
        list_number: 'body',
        list_number_2: 'list_number',
        image: 'body',
        caption: 'body',
        bio_name: 'heading2',
        bio: 'body'
    };

    var LIST_ROLES = ['list_bullet', 'list_bullet_2', 'list_number', 'list_number_2'];

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /** PHP trim(): only ASCII whitespace and NUL. */
    function trim(text) {
        return String(text).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    }

    function stripMarkup(text) {
        return String(text).replace(/\uE000\d+\uE001|[\uE000-\uE002\uE010-\uE013]/g, '');
    }

    function stripFormat(text) {
        return String(text).replace(/[\uE010-\uE013]/g, '');
    }

    function collapseWhitespace(text) {
        return trim(String(text).replace(/\s+/g, ' '));
    }

    function contains(haystack, needle) {
        return haystack.indexOf(needle) !== -1;
    }

    function inArray(value, list) {
        for (var i = 0; i < list.length; i++) {
            if (list[i] === value) {
                return true;
            }
        }
        return false;
    }

    A.trim = trim;
    A.stripMarkup = stripMarkup;
    A.inArray = inArray;

    /** Style names with surrounding spaces removed, also around the ":" of style groups. */
    A.normalizeStyleName = function (name) {
        var parts = String(name || '').split(':');
        var kept = [];
        for (var i = 0; i < parts.length; i++) {
            var part = trim(parts[i]);
            if (part !== '') {
                kept.push(part);
            }
        }
        return kept.join(':');
    };

    /** The configured styles over the defaults; empty required roles use their default. */
    A.completeStyles = function (styles) {
        var result = {};
        for (var role in A.DEFAULT_STYLES) {
            if (A.DEFAULT_STYLES.hasOwnProperty(role)) {
                var name = styles && styles.hasOwnProperty(role) ? A.normalizeStyleName(styles[role]) : A.DEFAULT_STYLES[role];
                if (name === '' && inArray(role, A.REQUIRED_ROLES)) {
                    name = A.DEFAULT_STYLES[role];
                }
                result[role] = name;
            }
        }
        return result;
    };

    // ---------------------------------------------------------------------------------------------
    // Metadata markers (AIW_DOCX_Parser::extract_metadata_from_text)
    // ---------------------------------------------------------------------------------------------

    var META_TAGS = [
        ['title', 'TITLE|HEADLINE|H1'],
        ['subtitle', 'SUBTITLE|SUBHEADER|H2'],
        ['author_membership', 'AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID'],
        ['writing_date', 'WRITING_DATE|DATE'],
        ['bio', 'BIO'],
        ['travel_period', 'REISEZEIT|TRAVEL_PERIOD']
    ];

    var NORMALIZE_TAGS = 'TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|WRITING_DATE|DATE|BIO';

    function extractMetadata(text, metadata) {
        // The WordPress importer drops [AUTHOR_NAME: ...]; here it pre-fills the author name.
        text = text.replace(/\[AUTHOR_NAME\s*:\s*([^\]]*)\]/gi, function (match, value) {
            value = trim(value);
            if (value !== '' && metadata.author_name === undefined) {
                metadata.author_name = value;
            }
            return '';
        });

        for (var t = 0; t < META_TAGS.length; t++) {
            var key = META_TAGS[t][0];
            var pattern = new RegExp('\\[(' + META_TAGS[t][1] + ')\\s*:\\s*([^\\]]+)\\]', 'gi');
            text = text.replace(pattern, function (match, tag, value) {
                value = trim(value);
                if (value !== '') {
                    if (metadata[key] === undefined) {
                        metadata[key] = value;
                    } else {
                        metadata[key + '_duplicate'] = true;
                    }
                }
                return '';
            });
        }

        if (/\[\s*RESTRICT(?:_START)?\s*\]/i.test(text)) {
            metadata.restriction_anchor = true;
        }

        if (/\[\s*(RESTRICT_START|RESTRICT_END)\s*\]/i.test(text)) {
            text = text.replace(/\[\s*RESTRICT_(?:START|END)\s*\]/gi, '');
        }

        return text;
    }

    function normalizeMetadataText(text) {
        text = trim(text).replace(new RegExp('^\\[(' + NORMALIZE_TAGS + ')\\s*:\\s*([^"]+?)\\]$', 'i'), '$2');
        text = text.replace(new RegExp('^\\[(' + NORMALIZE_TAGS + ')\\s*:\\s*([^\\]]+?)\\]$', 'i'), '$2');
        return trim(text);
    }

    function extractBodyHeaderTag(text) {
        var match = /^\[\s*HEADER(?:\s+([1-6]))?\s*:?\s+(.+?)\s*\]$/i.exec(trim(text));
        if (!match) {
            return null;
        }
        return {
            level: match[1] ? parseInt(match[1], 10) : 2,
            text: trim(match[2] || '')
        };
    }

    /**
     * [BIO: written over several paragraphs ... ] becomes one paragraph, the parts separated by
     * blank lines. Without a closing "]" nothing is joined and metadata.bio_unclosed is set.
     */
    function mergeMultiParagraphBio(paragraphs, metadata) {
        var merged = [];
        var count = paragraphs.length;

        for (var i = 0; i < count; i++) {
            var plain = trim(stripMarkup(paragraphs[i].text));
            if (!/\[\s*BIO\s*:[^\]]*$/i.test(plain)) {
                merged.push(paragraphs[i]);
                continue;
            }

            var close = -1;
            for (var j = i + 1; j < count; j++) {
                if (stripMarkup(paragraphs[j].text).indexOf(']') !== -1) {
                    close = j;
                    break;
                }
            }

            if (close === -1) {
                metadata.bio_unclosed = true;
                merged.push(paragraphs[i]);
                continue;
            }

            var parts = [];
            for (var k = i; k <= close; k++) {
                var part = trim(stripMarkup(paragraphs[k].text));
                if (part !== '') {
                    parts.push(part);
                }
            }

            merged.push({ text: parts.join('\n\n'), style: '', list: null });
            i = close;
        }

        return merged;
    }

    /**
     * Word style names as InDesign imports them ("Title", "Heading 1", or localized names) in
     * the form the plugin compares (Word style IDs such as "Title", "Heading1").
     */
    function canonicalStyle(name) {
        return String(name || '').toLowerCase().replace(/\s+/g, '')
            .replace('untertitel', 'subtitle')
            .replace('\u00FCberschrift', 'heading')
            .replace('uberschrift', 'heading')
            .replace('titel', 'title');
    }

    // ---------------------------------------------------------------------------------------------
    // Document (AIW_DOCX_Parser::parse)
    // ---------------------------------------------------------------------------------------------

    /**
     * @param paragraphs [{text, style, list: null | {level, ordered}}]
     * @return {title, subtitle, metadata, blocks}; blocks are
     *         {type: 'paragraph', text}, {type: 'heading', level, text}, {type: 'list', items: [{level, ordered, text}]}
     */
    A.parseDocument = function (paragraphs) {
        var title = '';
        var subtitle = '';
        var metadata = {};
        var blocks = [];
        var firstBodyText = '';
        var fallbackTitleBlock = -1;
        var listItems = [];

        function flushList() {
            if (listItems.length) {
                blocks.push({ type: 'list', items: listItems });
                listItems = [];
            }
        }

        paragraphs = mergeMultiParagraphBio(paragraphs, metadata);

        for (var i = 0; i < paragraphs.length; i++) {
            var paragraph = paragraphs[i];
            var text = trim(paragraph.text);
            var plain = trim(stripMarkup(text));
            if (plain === '') {
                continue;
            }

            // Markers are matched on the plain text. A paragraph that contained markers continues
            // without its links and formatting.
            var withoutMarkers = extractMetadata(plain, metadata);
            if (withoutMarkers !== plain) {
                text = withoutMarkers;
            }

            text = trim(text);
            if (trim(stripMarkup(text)) === '') {
                continue;
            }

            // Bold/italic on a [HEADER n ...] line is dropped; links are kept.
            var header = extractBodyHeaderTag(trim(stripFormat(text)));
            if (header) {
                flushList();
                if (trim(stripMarkup(header.text)) === '') {
                    continue;
                }
                if (header.level === 1 && title === '') {
                    title = stripMarkup(header.text);
                    continue;
                }
                blocks.push({ type: 'heading', level: header.level, text: header.text });
                continue;
            }

            if (paragraph.list) {
                // A top-level item that switches between bullets and numbers starts a new list.
                if (listItems.length && paragraph.list.level === 0 && paragraph.list.ordered !== listItems[0].ordered) {
                    flushList();
                }
                if (firstBodyText === '') {
                    firstBodyText = stripMarkup(text);
                }
                listItems.push({ level: paragraph.list.level, ordered: paragraph.list.ordered, text: text });
                continue;
            }

            flushList();

            var style = canonicalStyle(paragraph.style);
            if (title === '' && (contains(style, 'title') || contains(style, 'heading1'))) {
                title = stripMarkup(text);
                continue;
            }

            if (subtitle === '' && (contains(style, 'subtitle') || contains(style, 'heading2'))) {
                subtitle = stripMarkup(text);
                continue;
            }

            if (firstBodyText === '') {
                firstBodyText = stripMarkup(text);
                fallbackTitleBlock = blocks.length;
            }

            blocks.push({ type: 'paragraph', text: text });
        }

        flushList();

        // [TITLE: ...] wins over the "first paragraph" fallback.
        if (title === '' && metadata.title) {
            title = metadata.title;
        }
        // The paragraph that became the title is not repeated in the body (a list item stays).
        if (title === '' && firstBodyText !== '') {
            title = firstBodyText;
            if (fallbackTitleBlock >= 0) {
                blocks.splice(fallbackTitleBlock, 1);
            }
        }

        title = normalizeMetadataText(collapseWhitespace(title));
        if (metadata.subtitle) {
            subtitle = metadata.subtitle;
        }
        subtitle = normalizeMetadataText(collapseWhitespace(subtitle));

        return { title: title, subtitle: subtitle, metadata: metadata, blocks: blocks };
    };

    /** Picture markers in reading order: [{number, caption}]. */
    A.imageMarkers = function (parsed) {
        var texts = [];
        for (var i = 0; i < parsed.blocks.length; i++) {
            var block = parsed.blocks[i];
            if (block.type === 'list') {
                for (var j = 0; j < block.items.length; j++) {
                    texts.push(block.items[j].text);
                }
            } else {
                texts.push(block.text);
            }
        }

        var markers = [];
        var pattern = /\[(Bild|Image)\s*(\d+)\s*(?::([^\]]*))?\]/gi;
        var match;
        var all = stripMarkup(texts.join('\n'));
        while ((match = pattern.exec(all)) !== null) {
            markers.push({ number: parseInt(match[2], 10), caption: trim(match[3] || '') });
        }
        return markers;
    };

    /**
     * Marker-like text that stayed in the article because it was not recognized, e.g.
     * "[HEADER Warnungen}" (wrong closing bracket) or "[Bild: caption]" (no number).
     */
    A.unrecognizedMarkers = function (parsed) {
        var texts = [];
        for (var i = 0; i < parsed.blocks.length; i++) {
            var block = parsed.blocks[i];
            if (block.type === 'list') {
                for (var j = 0; j < block.items.length; j++) {
                    texts.push(block.items[j].text);
                }
            } else {
                texts.push(block.text);
            }
        }

        var found = [];
        var all = stripMarkup(texts.join('\n'));
        var pattern = /\[\s*(HEADER|TITLE|HEADLINE|SUBTITLE|SUBHEADER|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|AUTHOR_NAME|WRITING_DATE|DATE|BIO|REISEZEIT|TRAVEL_PERIOD|Bild|Image)\b[^\]\n]{0,40}\]?/gi;
        var match;
        while ((match = pattern.exec(all)) !== null) {
            if (/^\[\s*(?:Bild|Image)\s*\d+\s*(?::[^\]]*)?\]/i.test(all.substring(match.index))) {
                continue;
            }
            found.push(trim(match[0]));
        }
        return found;
    };

    // ---------------------------------------------------------------------------------------------
    // Print paragraphs (AIW_ICML_Writer)
    // ---------------------------------------------------------------------------------------------

    /**
     * Paragraph: {style: role, runs: [{text, format, link}], restart, number?, marker?}.
     * run.link is the link URL or ''.
     */
    function Writer(styles, linkTargets) {
        this.styles = styles || {};
        this.linkTargets = linkTargets || [];
        this.paragraphs = [];
        this.nextIsFirst = true;
    }

    A.Writer = Writer;

    Writer.prototype.styleName = function (role) {
        var name = this.styles[role];
        return name ? trim(name) : '';
    };

    /** The role whose style is used for role, following FALLBACK_ROLES; '' when none. */
    Writer.prototype.resolveRole = function (role, exists) {
        var seen = {};
        while (role && !seen[role]) {
            seen[role] = true;
            var name = this.styleName(role);
            if (name !== '' && (!exists || exists(name))) {
                return role;
            }
            role = FALLBACK_ROLES[role] || '';
        }
        return '';
    };

    /** List items whose role has no list style get their bullet or number as text. */
    Writer.prototype.outputRuns = function (paragraph, resolvedRole) {
        var runs = paragraph.runs.slice(0);
        if (paragraph.marker && inArray(paragraph.style, LIST_ROLES) && !inArray(resolvedRole, LIST_ROLES)) {
            runs.unshift({ text: paragraph.marker + '\t', format: 0, link: '' });
        }
        return runs;
    };

    /** The character style for a run, or '' (then bold/italic become a font style). */
    Writer.prototype.characterStyleFor = function (run) {
        if (run.link && this.styleName('link') !== '') {
            return this.styleName('link');
        }
        var role = ['', 'bold', 'italic', 'bold_italic'][run.format & 3];
        return role !== '' ? this.styleName(role) : '';
    };

    /** Join neighbouring runs with the same formatting and link. */
    Writer.prototype.mergeRuns = function (runs) {
        var merged = [];
        for (var i = 0; i < runs.length; i++) {
            var run = runs[i];
            var last = merged.length - 1;
            if (last >= 0
                && this.characterStyleFor(merged[last]) === this.characterStyleFor(run)
                && (merged[last].format & 3) === (run.format & 3)
                && merged[last].link === run.link) {
                merged[last] = { text: merged[last].text + run.text, format: run.format, link: run.link };
            } else {
                merged.push({ text: run.text, format: run.format, link: run.link });
            }
        }
        return merged;
    };

    /** Runs of a marked-up paragraph text. */
    Writer.prototype.runsFromMarkup = function (text) {
        var links = this.linkTargets;

        // Word often splits a marker such as [Bild 1] into differently formatted runs. Move any
        // formatting change out of bracketed spans so placeholders stay recognisable.
        text = String(text).replace(/\[[^\]\uE000-\uE002]*\]/g, function (span) {
            var found = span.match(/[\uE010-\uE013]/g);
            return found ? stripFormat(span) + found[found.length - 1] : span;
        });

        var runs = [];
        var format = 0;
        var link = '';

        function push(piece) {
            if (piece === '') {
                return;
            }
            var lines = piece.split('\n');
            for (var l = 0; l < lines.length; l++) {
                if (l > 0) {
                    runs.push({ text: LINE_BREAK, format: format, link: link });
                }
                var collapsed = lines[l].replace(/[ \t\r\f\v]+/g, ' ');
                if (collapsed !== '') {
                    runs.push({ text: collapsed, format: format, link: link });
                }
            }
        }

        var pattern = /\uE000(\d+)\uE001|\uE002|[\uE010-\uE013]/g;
        var last = 0;
        var match;
        while ((match = pattern.exec(text)) !== null) {
            push(text.substring(last, match.index));
            last = pattern.lastIndex;

            var token = match[0];
            if (token.charAt(0) === A.LINK_OPEN) {
                var target = links[parseInt(match[1], 10)] || '';
                if (target !== '' && link === '') {
                    link = target;
                }
            } else if (token === A.LINK_CLOSE) {
                link = '';
            } else {
                for (var f = 0; f < 4; f++) {
                    if (A.FORMAT_MARKERS[f] === token) {
                        format = f;
                    }
                }
            }
        }
        push(text.substring(last));

        return runs;
    };

    Writer.prototype.addPlain = function (style, text, number) {
        return this.pushParagraph(style, [{ text: String(text), format: 0, link: '' }], false, number || 0, '');
    };

    Writer.prototype.addBlocks = function (blocks) {
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            if (block.type === 'paragraph') {
                this.addRunsParagraph('body', this.runsFromMarkup(block.text), false, '');
            } else if (block.type === 'heading') {
                var role = block.level <= 2 ? 'heading2' : (block.level === 3 ? 'heading3' : 'heading4');
                this.addRunsParagraph(role, this.runsFromMarkup(block.text), false, '');
            } else if (block.type === 'list') {
                this.addList(block.items);
            }
        }
    };

    /** Nested lists as the plugin builds them (AIW_DOCX_Parser::flush_list + AIW_ICML_Writer::add_list). */
    Writer.prototype.addList = function (items) {
        var open = [];
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            // At most one level deeper than the previous item; the first item is top level.
            var depth = i === 0 ? 1 : Math.max(1, Math.min(item.level + 1, open.length + 1));
            if (depth > open.length) {
                while (open.length < depth) {
                    open.push({ ordered: item.ordered, counter: 0, restart: item.ordered });
                }
            } else {
                while (open.length > depth) {
                    open.pop();
                }
            }

            var list = open[depth - 1];
            list.counter++;
            var role = list.ordered
                ? (depth === 1 ? 'list_number' : 'list_number_2')
                : (depth === 1 ? 'list_bullet' : 'list_bullet_2');
            var marker = list.ordered ? list.counter + '.' : (depth === 1 ? '\u2022' : '\u2013');
            if (this.addRunsParagraph(role, this.runsFromMarkup(item.text), list.restart, marker)) {
                list.restart = false;
            }
        }
    };

    /**
     * Add one paragraph. Picture markers split it: text before the marker, an "image" paragraph
     * and its "caption", then the rest of the text in the same style. [RESTRICT] is removed.
     */
    Writer.prototype.addRunsParagraph = function (style, runs, restart, marker) {
        var added = false;
        var current = [];

        for (var r = 0; r < runs.length; r++) {
            var run = runs[r];
            var text = run.text.replace(/\[\s*RESTRICT(?:_START|_END)?\s*\]/gi, '');
            var pattern = /\[\s*(?:Bild|Image)\s*(\d+)\s*(?::([^\]]*))?\]/gi;
            var offset = 0;
            var match;

            while ((match = pattern.exec(text)) !== null) {
                var before = text.substring(offset, match.index);
                if (before !== '') {
                    current.push({ text: before, format: run.format, link: run.link });
                }
                offset = pattern.lastIndex;

                if (this.pushParagraph(style, current, restart, 0, added ? '' : marker)) {
                    added = true;
                    restart = false;
                }
                current = [];

                var number = parseInt(match[1], 10);
                this.addPlain('image', '[Bild ' + number + ']', number);
                this.addPlain('caption', trim(match[2] || ''), number);
            }

            var rest = text.substring(offset);
            if (rest !== '') {
                current.push({ text: rest, format: run.format, link: run.link });
            }
        }

        if (this.pushParagraph(style, current, restart, 0, added ? '' : marker)) {
            added = true;
        }

        return added;
    };

    Writer.prototype.pushParagraph = function (style, runs, restart, number, marker) {
        runs = trimRuns(runs);
        if (!runs.length) {
            return false;
        }

        if (style === 'body' && this.nextIsFirst && this.styleName('body_first') !== '') {
            style = 'body_first';
        }

        if (style === 'body' || style === 'body_first') {
            this.nextIsFirst = false;
        } else if (inArray(style, ['title', 'subtitle', 'author', 'vorspann', 'heading2', 'heading3', 'heading4'])) {
            this.nextIsFirst = true;
        }

        var paragraph = { style: style, runs: runs, restart: !!restart };
        if (number > 0) {
            paragraph.number = number;
        }
        if (marker) {
            paragraph.marker = marker;
        }
        this.paragraphs.push(paragraph);

        return true;
    };

    function trimRuns(runs) {
        var kept = [];
        for (var i = 0; i < runs.length; i++) {
            if (runs[i].text !== '') {
                kept.push({ text: runs[i].text, format: runs[i].format, link: runs[i].link });
            }
        }

        while (kept.length) {
            kept[0].text = kept[0].text.replace(/^[\s\u2028]+/, '');
            if (kept[0].text !== '') {
                break;
            }
            kept.shift();
        }

        while (kept.length) {
            var last = kept.length - 1;
            kept[last].text = kept[last].text.replace(/[\s\u2028]+$/, '');
            if (kept[last].text !== '') {
                break;
            }
            kept.pop();
        }

        return kept;
    }

    /**
     * The body as paragraphs. The first paragraph is the Vorspann when the article starts with
     * a normal paragraph.
     */
    Writer.prototype.splitArticle = function (blocks) {
        this.paragraphs = [];
        this.nextIsFirst = true;
        this.addBlocks(blocks);
        var paragraphs = this.paragraphs;
        this.paragraphs = [];

        var vorspann = null;
        if (paragraphs.length && (paragraphs[0].style === 'body' || paragraphs[0].style === 'body_first')) {
            vorspann = paragraphs.shift();
            vorspann.style = 'vorspann';

            // The paragraph after the Vorspann opens the body text.
            for (var i = 0; i < paragraphs.length; i++) {
                if (paragraphs[i].style === 'body' && this.styleName('body_first') !== '') {
                    paragraphs[i].style = 'body_first';
                }
                if (paragraphs[i].style === 'body' || paragraphs[i].style === 'body_first') {
                    break;
                }
            }
        }

        return { vorspann: vorspann, body: paragraphs };
    };

    /** Author name and bio. Blank lines separate bio paragraphs; single line breaks stay. */
    Writer.prototype.bioParagraphs = function (authorName, bio, withName) {
        this.paragraphs = [];
        bio = trim(bio || '');
        if (bio !== '') {
            if (withName) {
                this.addPlain('bio_name', authorName || '');
            }
            var parts = bio.split(/(?:\r\n|\r|\n)\s*(?:\r\n|\r|\n)/);
            for (var i = 0; i < parts.length; i++) {
                this.addPlain('bio', trim(parts[i]).replace(/[ \t]*(?:\r\n|\r|\n)[ \t]*/g, LINE_BREAK));
            }
        }
        var paragraphs = this.paragraphs;
        this.paragraphs = [];
        return paragraphs;
    };

    /**
     * Everything the import needs, for the given style names.
     *
     * @param parsed result of parseDocument
     * @param details {authorName, travelPeriod, bio}
     */
    A.printModel = function (parsed, styles, linkTargets, details) {
        var writer = new Writer(styles, linkTargets);
        var split = writer.splitArticle(parsed.blocks);
        var captions = {};
        var body = [];

        for (var i = 0; i < split.body.length; i++) {
            var paragraph = split.body[i];
            if (paragraph.style === 'caption' && paragraph.number) {
                var caption = '';
                for (var r = 0; r < paragraph.runs.length; r++) {
                    caption += paragraph.runs[r].text;
                }
                captions[paragraph.number] = caption;
            } else if (paragraph.style !== 'image') {
                body.push(paragraph);
            }
        }

        // The whole article in one story, for documents without placeholders (like the ICML file).
        var full = [];
        writer.paragraphs = [];
        writer.nextIsFirst = true;
        writer.addPlain('title', parsed.title);
        writer.addPlain('subtitle', parsed.subtitle);
        writer.addPlain('author', details.authorName || '');
        full = writer.paragraphs;
        writer.paragraphs = [];
        if (split.vorspann) {
            full.push(split.vorspann);
        }
        full = full.concat(split.body, writer.bioParagraphs(details.authorName, details.bio, true));

        return {
            writer: writer,
            vorspann: split.vorspann ? [split.vorspann] : [],
            body: body,
            captions: captions,
            bio: writer.bioParagraphs(details.authorName, details.bio, false),
            full: full
        };
    };

    // ---------------------------------------------------------------------------------------------
    // Template placeholders and picture files
    // ---------------------------------------------------------------------------------------------

    A.TOKEN_PATTERN = '\\[\\s*(TITEL|UNTERTITEL|AUTOR|REISEZEIT|VORSPANN|TEXT|BIO|AUFMACHERBILD|AUTORENBILD|BILDUNTERSCHRIFT\\s*\\d+|BILD\\s*\\d+)\\s*\\]';

    A.normalizeToken = function (token) {
        return String(token).toUpperCase().replace(/^\s+|\s+$/g, '').replace(/^(BILDUNTERSCHRIFT|BILD)\s*(\d+)$/, '$1 $2');
    };

    /** Placeholders in a text: [{token, index, length}] with JS string indexes. */
    A.findTokens = function (text) {
        var pattern = new RegExp(A.TOKEN_PATTERN, 'gi');
        var found = [];
        var match;
        while ((match = pattern.exec(text)) !== null) {
            found.push({ token: A.normalizeToken(match[1]), index: match.index, length: match[0].length });
        }
        return found;
    };

    A.isPictureToken = function (token) {
        return /^(AUFMACHERBILD|AUTORENBILD|BILD \d+)$/.test(token);
    };

    /**
     * The placeholder a picture file belongs to, from its name: Aufmacherbild/Titelbild,
     * Autorenbild/BioPic, Bild 1/Bild_1/Bild-01/Image 1. Returns '' for other files.
     */
    A.pictureKey = function (fileName) {
        if (!/\.(jpe?g|png|tiff?|psd|pdf|eps|ai|gif|bmp|webp|heic)$/i.test(fileName)) {
            return '';
        }
        var base = fileName.replace(/\.[^.]+$/, '').toLowerCase().replace(/[\s_.\-]+/g, '');
        if (/^(aufmacherbild|aufmacher|titelbild|headlineimage|headline|heroimage|hero)$/.test(base)) {
            return 'AUFMACHERBILD';
        }
        if (/^(autorenbild|autorbild|autorinbild|authorimage|authorphoto|authorpicture|biopic|biobild|biofoto)$/.test(base)) {
            return 'AUTORENBILD';
        }
        var match = /^(?:bild|image|img|foto|photo|abb|abbildung)0*(\d+)$/.exec(base);
        return match ? 'BILD ' + parseInt(match[1], 10) : '';
    };

    return A;
})();

// =================================================================================================
// InDesign
// =================================================================================================

(function () {
    if (typeof app === 'undefined' || typeof AIW_NO_MAIN !== 'undefined') {
        return;
    }

    var SCRIPT_NAME = 'Artikel-Import';
    var LABEL_STYLES = 'aiwArticleImportStyles';
    var GERMAN = /^de/i.test(String($.locale || app.locale || ''));
    var AUTOMATION = typeof AIW_AUTOMATION !== 'undefined' ? AIW_AUTOMATION : null;
    // Tests: dialogs open and close at once with their default action.
    var AUTOCLOSE_DIALOGS = typeof AIW_DIALOG_AUTOCLOSE !== 'undefined';

    /** Text in the user's language; %1, %2 ... are replaced by the following arguments. */
    function T(english, german) {
        var text = GERMAN ? german : english;
        for (var i = 2; i < arguments.length; i++) {
            text = text.split('%' + (i - 1)).join(String(arguments[i]));
        }
        return text;
    }

    var ROLE_LABELS = {
        title: ['Title', 'Titel'],
        subtitle: ['Subtitle', 'Untertitel'],
        author: ['Author line', 'Autorzeile'],
        vorspann: ['Vorspann (intro)', 'Vorspann'],
        body_first: ['First body paragraph', 'Erster Absatz im Flie\u00DFtext'],
        body: ['Body text', 'Flie\u00DFtext'],
        heading2: ['Heading ([HEADER])', 'Zwischen\u00FCberschrift ([HEADER])'],
        heading3: ['Heading level 3', '\u00DCberschrift Ebene 3'],
        heading4: ['Heading level 4', '\u00DCberschrift Ebene 4'],
        list_bullet: ['Bulleted list', 'Aufz\u00E4hlung'],
        list_bullet_2: ['Bulleted list, level 2', 'Aufz\u00E4hlung Ebene 2'],
        list_number: ['Numbered list', 'Nummerierung'],
        list_number_2: ['Numbered list, level 2', 'Nummerierung Ebene 2'],
        image: ['Picture marker [Bild n]', 'Bildmarke [Bild n]'],
        caption: ['Caption', 'Bildunterschrift'],
        bio_name: ['Author box: name', 'Autorenkasten: Name'],
        bio: ['Author box: text', 'Autorenkasten: Text'],
        bold: ['Bold', 'Fett'],
        italic: ['Italic', 'Kursiv'],
        bold_italic: ['Bold italic', 'Fett kursiv'],
        link: ['Link', 'Link']
    };

    function roleLabel(role) {
        return GERMAN ? ROLE_LABELS[role][1] : ROLE_LABELS[role][0];
    }

    function pictureLabel(key) {
        if (key === 'AUFMACHERBILD') {
            return T('Headline picture', 'Aufmacherbild');
        }
        if (key === 'AUTORENBILD') {
            return T('Author picture', 'Autorenbild');
        }
        return T('Picture %1', 'Bild %1', key.replace('BILD ', ''));
    }

    function asArray(value) {
        return value instanceof Array ? value : [value];
    }

    /** Text properties can come back as an array (one value per character); take the first. */
    function firstValue(value) {
        return value instanceof Array ? value[0] : value;
    }

    function errorText(error) {
        return String(error && error.message ? error.message : error) + (error && error.line ? ' (line ' + error.line + ')' : '');
    }

    // ---------------------------------------------------------------------------------------------
    // Settings: style names per document (label) and a small settings file per user
    // ---------------------------------------------------------------------------------------------

    function settingsFile() {
        var folder = new Folder(Folder.userData + '/ArtikelImport');
        if (!folder.exists) {
            folder.create();
        }
        return new File(folder.fsName + '/settings.txt');
    }

    function parseLines(text) {
        var values = {};
        var lines = String(text || '').split(/\r\n|\r|\n/);
        for (var i = 0; i < lines.length; i++) {
            var at = lines[i].indexOf('=');
            if (at > 0) {
                values[lines[i].substring(0, at)] = lines[i].substring(at + 1);
            }
        }
        return values;
    }

    function readSettings() {
        var file = settingsFile();
        if (!file.exists) {
            return {};
        }
        file.encoding = 'UTF-8';
        if (!file.open('r')) {
            return {};
        }
        var text = file.read();
        file.close();
        return parseLines(text);
    }

    function writeSettings(values) {
        var lines = [];
        for (var key in values) {
            if (values.hasOwnProperty(key)) {
                lines.push(key + '=' + String(values[key]).replace(/[\r\n]+/g, ' '));
            }
        }
        var file = settingsFile();
        file.encoding = 'UTF-8';
        if (file.open('w')) {
            file.write(lines.join('\n'));
            file.close();
        }
    }

    function loadStyles(doc) {
        var styles = {};
        var role;
        var settings = readSettings();
        for (role in AIW.DEFAULT_STYLES) {
            if (AIW.DEFAULT_STYLES.hasOwnProperty(role)) {
                styles[role] = settings.hasOwnProperty('style.' + role) ? settings['style.' + role] : AIW.DEFAULT_STYLES[role];
            }
        }

        var label = '';
        try {
            label = doc.extractLabel(LABEL_STYLES);
        } catch (e) {}
        var own = parseLines(label);
        for (role in own) {
            if (own.hasOwnProperty(role) && AIW.DEFAULT_STYLES.hasOwnProperty(role)) {
                styles[role] = own[role];
            }
        }

        return AIW.completeStyles(styles);
    }

    function saveStyles(doc, styles) {
        var lines = [];
        var settings = readSettings();
        for (var role in styles) {
            if (styles.hasOwnProperty(role)) {
                lines.push(role + '=' + styles[role]);
                settings['style.' + role] = styles[role];
            }
        }
        try {
            doc.insertLabel(LABEL_STYLES, lines.join('\n'));
        } catch (e) {}
        writeSettings(settings);
    }

    // ---------------------------------------------------------------------------------------------
    // Styles of the target document
    // ---------------------------------------------------------------------------------------------

    function stylePath(style) {
        var parts = [style.name];
        var parent = style.parent;
        while (parent && (parent.constructor.name === 'ParagraphStyleGroup' || parent.constructor.name === 'CharacterStyleGroup')) {
            parts.unshift(parent.name);
            parent = parent.parent;
        }
        return parts.join(':');
    }

    function indexStyles(collection) {
        var index = { byName: {}, names: [] };
        for (var i = 0; i < collection.length; i++) {
            var path = stylePath(collection[i]);
            if (!index.byName.hasOwnProperty(path)) {
                index.byName[path] = collection[i];
                index.names.push(path);
            }
        }
        return index;
    }

    function documentStyles(doc) {
        return {
            paragraph: indexStyles(doc.allParagraphStyles),
            character: indexStyles(doc.allCharacterStyles)
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Reading the DOCX with InDesign's Word import
    // ---------------------------------------------------------------------------------------------

    var WORD_IMPORT_SETTINGS = {
        convertBulletsAndNumbersToText: false,
        preserveLocalOverrides: true,
        removeFormatting: false,
        importFootnotes: false,
        importEndnotes: false,
        importIndex: false,
        importTOC: false,
        importUnusedStyles: false,
        preserveGraphics: false,
        preserveTrackChanges: false,
        useTypographersQuotes: false
    };

    function isBold(fontStyle) {
        return /bold|black|heavy|semibold|demi/i.test(String(fontStyle));
    }

    function isItalic(fontStyle) {
        return /italic|oblique|kursiv/i.test(String(fontStyle));
    }

    /** Index conversion between InDesign text indexes and JS string indexes (surrogate pairs). */
    function indexMap(text, codePoints) {
        if (!codePoints || !/[\uD800-\uDBFF][\uDC00-\uDFFF]/.test(text)) {
            return {
                toJs: function (index) { return index; },
                toId: function (index) { return index; }
            };
        }
        var jsOf = [];
        var idOf = [];
        var id = 0;
        for (var js = 0; js < text.length; js++) {
            idOf[js] = id;
            var code = text.charCodeAt(js);
            if (code >= 0xD800 && code <= 0xDBFF && js + 1 < text.length) {
                jsOf[id] = js;
                idOf[js + 1] = id;
                js++;
            } else {
                jsOf[id] = js;
            }
            id++;
        }
        jsOf[id] = text.length;
        idOf[text.length] = id;
        return {
            toJs: function (index) { return jsOf[index] !== undefined ? jsOf[index] : text.length; },
            toId: function (index) { return idOf[index] !== undefined ? idOf[index] : id; }
        };
    }

    /**
     * @return {paragraphs: [{text, style, list}], linkTargets: [], codePoints: bool}
     */
    function readDocx(file) {
        var prefs = app.wordRTFImportPreferences;
        var saved = {};
        var key;
        for (key in WORD_IMPORT_SETTINGS) {
            if (WORD_IMPORT_SETTINGS.hasOwnProperty(key)) {
                try {
                    saved[key] = prefs[key];
                    prefs[key] = WORD_IMPORT_SETTINGS[key];
                } catch (e) {}
            }
        }
        try {
            saved.convertPageBreaks = prefs.convertPageBreaks;
            prefs.convertPageBreaks = ConvertPageBreaks.NONE;
        } catch (e) {}

        // No "missing fonts" or import dialogs for the hidden helper document.
        var interaction = app.scriptPreferences.userInteractionLevel;
        app.scriptPreferences.userInteractionLevel = UserInteractionLevels.NEVER_INTERACT;

        var temp = null;
        try {
            temp = app.documents.add(false);
            var page = temp.pages[0];

            // Does InDesign count a character outside the BMP (emoji) as one character or two?
            var probe = page.textFrames.add({ geometricBounds: [0, 0, 50, 50] });
            probe.contents = '\uD83D\uDE00';
            var codePoints = probe.parentStory.characters.length === 1;
            probe.remove();

            var frame = page.textFrames.add({ geometricBounds: [0, 0, 200, 200] });
            frame.place(file, false);
            var result = readStory(temp, frame.parentStory, codePoints);
            result.codePoints = codePoints;
            return result;
        } finally {
            if (temp) {
                try {
                    temp.close(SaveOptions.NO);
                } catch (e) {}
            }
            app.scriptPreferences.userInteractionLevel = interaction;
            for (key in saved) {
                if (saved.hasOwnProperty(key)) {
                    try {
                        prefs[key] = saved[key];
                    } catch (e) {}
                }
            }
        }
    }

    function readStory(doc, story, codePoints) {
        var full = String(story.contents);
        var length = full.length;
        var map = indexMap(full, codePoints);

        // Bold/italic per character, relative to the paragraph style (as Word's run formatting).
        var formats = [];
        var baseFonts = {};
        var ranges = story.textStyleRanges;
        if (ranges.length) {
            var rangeIndexes = asArray(ranges.everyItem().index);
            var rangeFonts = asArray(ranges.everyItem().fontStyle);
            var rangeStyles = asArray(ranges.everyItem().appliedParagraphStyle);
            for (var r = 0; r < rangeIndexes.length; r++) {
                var style = rangeStyles[r];
                var styleId = String(style.id);
                if (!baseFonts.hasOwnProperty(styleId)) {
                    var styleFont = '';
                    try {
                        styleFont = String(style.fontStyle);
                    } catch (e) {}
                    baseFonts[styleId] = styleFont;
                }
                var font = String(rangeFonts[r]);
                var format = (isBold(font) && !isBold(baseFonts[styleId]) ? AIW.FORMAT_BOLD : 0)
                    | (isItalic(font) && !isItalic(baseFonts[styleId]) ? AIW.FORMAT_ITALIC : 0);
                var start = map.toJs(rangeIndexes[r]);
                var end = r + 1 < rangeIndexes.length ? map.toJs(rangeIndexes[r + 1]) : length;
                if (format) {
                    for (var c = start; c < end; c++) {
                        formats[c] = format;
                    }
                }
            }
        }

        // Web and mail links.
        var linkTargets = [];
        var linkAt = [];
        for (var h = 0; h < doc.hyperlinks.length; h++) {
            try {
                var hyperlink = doc.hyperlinks[h];
                var source = hyperlink.source;
                var destination = hyperlink.destination;
                if (!source || source.constructor.name !== 'HyperlinkTextSource') {
                    continue;
                }
                if (!destination || destination.constructor.name !== 'HyperlinkURLDestination') {
                    continue;
                }
                var url = AIW.trim(destination.destinationURL);
                if (!/^(https?:\/\/\S+|mailto:\S+)$/i.test(url)) {
                    continue;
                }
                var text = source.sourceText;
                if (text.parentStory.id !== story.id) {
                    continue;
                }
                var first = map.toJs(text.index);
                var last = map.toJs(text.index + text.characters.length);
                linkTargets.push(url);
                for (var l = first; l < last; l++) {
                    linkAt[l] = linkTargets.length - 1;
                }
            } catch (e) {}
        }

        // Paragraph styles and lists.
        var paragraphStyles = [];
        var listTypes = [];
        var listLevels = [];
        var indents = [];
        if (story.paragraphs.length) {
            var items = story.paragraphs.everyItem();
            var styles = asArray(items.appliedParagraphStyle);
            listTypes = asArray(items.bulletsAndNumberingListType);
            listLevels = asArray(items.numberingLevel);
            indents = asArray(items.leftIndent);
            for (var s = 0; s < styles.length; s++) {
                paragraphStyles.push(String(styles[s].name));
            }
        }

        function listOf(index) {
            var styleName = String(paragraphStyles[index] || '').toLowerCase();
            // Numbered headings stay headings.
            if (/heading|title|titel|berschrift/.test(styleName)) {
                return null;
            }
            var type = listTypes[index];
            if (type != ListType.BULLET_LIST && type != ListType.NUMBERED_LIST) {
                return null;
            }
            var level = parseInt(listLevels[index], 10) - 1;
            return {
                level: isNaN(level) ? 0 : Math.max(0, Math.min(8, level)),
                ordered: type == ListType.NUMBERED_LIST
            };
        }

        var paragraphs = [];
        var paragraphIndex = 0;
        var current = '';
        var currentFormat = 0;
        var currentLink = -1;

        function finish() {
            if (currentLink !== -1) {
                current += AIW.LINK_CLOSE;
            }
            paragraphs.push({ text: current, style: paragraphStyles[paragraphIndex] || '', list: listOf(paragraphIndex) });
            current = '';
            currentFormat = 0;
            currentLink = -1;
            paragraphIndex++;
        }

        for (var i = 0; i < length; i++) {
            var character = full.charAt(i);
            if (character === '\r') {
                finish();
                continue;
            }

            var link = linkAt[i] === undefined ? -1 : linkAt[i];
            if (link !== currentLink) {
                if (currentLink !== -1) {
                    current += AIW.LINK_CLOSE;
                }
                if (link !== -1) {
                    current += AIW.LINK_OPEN + link + AIW.LINK_SEPARATOR;
                }
                currentLink = link;
            }

            if (character === '\n') {
                current += '\n';
                continue;
            }
            if (character === '\t') {
                character = ' ';
            } else if (/[\x00-\x1F\uFFFC\uFEFF\uE000-\uE013]/.test(character)) {
                // Tables, anchored objects, notes and other special characters.
                continue;
            }

            var charFormat = formats[i] || 0;
            if (charFormat !== currentFormat) {
                current += AIW.FORMAT_MARKERS[charFormat];
                currentFormat = charFormat;
            }
            current += character;
        }
        if (current !== '') {
            finish();
        }

        nestListsByIndent(paragraphs, indents);

        return { paragraphs: paragraphs, linkTargets: linkTargets };
    }

    /**
     * InDesign's Word import reports every list item as level 1; the nesting survives only as
     * the left indent. Within each run of list paragraphs, the n-th smallest indent is level n.
     */
    function nestListsByIndent(paragraphs, indents) {
        var start = 0;
        while (start < paragraphs.length) {
            if (!paragraphs[start].list) {
                start++;
                continue;
            }
            var end = start;
            var distinct = [];
            while (end < paragraphs.length && paragraphs[end].list) {
                var indent = Math.round(Number(indents[end]) * 10) / 10;
                if (!isNaN(indent) && !AIW.inArray(indent, distinct)) {
                    distinct.push(indent);
                }
                end++;
            }
            distinct.sort(function (a, b) { return a - b; });
            for (var i = start; i < end; i++) {
                var rank = 0;
                var own = Math.round(Number(indents[i]) * 10) / 10;
                for (var d = 0; d < distinct.length; d++) {
                    if (distinct[d] === own) {
                        rank = d;
                    }
                }
                paragraphs[i].list.level = Math.min(8, Math.max(paragraphs[i].list.level, rank));
            }
            start = end;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Template analysis
    // ---------------------------------------------------------------------------------------------

    /** Stories with placeholders: [{story, contents, tokens}]. */
    function scanStories(doc) {
        var found = [];
        var stories = doc.stories;
        for (var i = 0; i < stories.length; i++) {
            var story = stories[i];
            var contents = String(story.contents);
            var tokens = AIW.findTokens(contents);
            if (tokens.length) {
                found.push({ story: story, contents: contents, tokens: tokens });
            }
        }
        return found;
    }

    function analyzeTemplate(doc) {
        var counts = {};
        var scanned = scanStories(doc);
        for (var i = 0; i < scanned.length; i++) {
            for (var t = 0; t < scanned[i].tokens.length; t++) {
                var token = scanned[i].tokens[t].token;
                counts[token] = (counts[token] || 0) + 1;
            }
        }
        return { counts: counts, hasText: !!counts.TEXT };
    }

    function selectionTarget() {
        if (app.documents.length === 0 || !app.selection || app.selection.length !== 1) {
            return null;
        }
        var selection = app.selection[0];
        var kind = selection.constructor.name;
        try {
            if (kind === 'InsertionPoint') {
                return { story: selection.parentStory, start: selection.index, end: selection.index };
            }
            if (kind === 'TextFrame') {
                var story = selection.parentStory;
                var length = story.characters.length;
                return { story: story, start: length, end: length };
            }
            if (AIW.inArray(kind, ['Text', 'Character', 'Word', 'Line', 'Paragraph', 'TextStyleRange', 'TextColumn'])) {
                return { story: selection.parentStory, start: selection.index, end: selection.index + selection.characters.length };
            }
        } catch (e) {}
        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // Pictures
    // ---------------------------------------------------------------------------------------------

    /** Picture files by placeholder, from the folder and its Links/Bilder/Images subfolders. */
    function findPictures(folder) {
        var pictures = {};
        if (!folder || !folder.exists) {
            return pictures;
        }
        var folders = [folder];
        var subfolders = ['Links', 'Bilder', 'Images', 'Fotos'];
        for (var s = 0; s < subfolders.length; s++) {
            var sub = new Folder(folder.fsName + '/' + subfolders[s]);
            if (sub.exists) {
                folders.push(sub);
            }
        }

        for (var f = 0; f < folders.length; f++) {
            var files = folders[f].getFiles();
            files.sort(function (a, b) {
                return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0);
            });
            for (var i = 0; i < files.length; i++) {
                if (!(files[i] instanceof File)) {
                    continue;
                }
                var key = AIW.pictureKey(decodeURI(files[i].name));
                if (key !== '' && !pictures.hasOwnProperty(key)) {
                    pictures[key] = files[i];
                }
            }
        }
        return pictures;
    }

    // ---------------------------------------------------------------------------------------------
    // Warnings shown before the import
    // ---------------------------------------------------------------------------------------------

    function collectWarnings(state) {
        var warnings = [];
        var parsed = state.parsed;
        var metadata = parsed.metadata;
        var counts = state.template.counts;
        var placeholderMode = state.mode === 'placeholders';

        if (parsed.title === '') {
            warnings.push(T('No title found. Add [TITLE: ...] to the Word file.', 'Kein Titel gefunden. Bitte [TITLE: ...] in die Word-Datei schreiben.'));
        }
        var duplicates = [['title', 'TITLE'], ['subtitle', 'SUBTITLE'], ['bio', 'BIO'], ['travel_period', 'REISEZEIT']];
        for (var d = 0; d < duplicates.length; d++) {
            if (metadata[duplicates[d][0] + '_duplicate']) {
                warnings.push(T('[%1] appears more than once; the first one is used.', '[%1] kommt mehrfach vor; der erste Wert wird verwendet.', duplicates[d][1]));
            }
        }
        if (metadata.bio_unclosed) {
            warnings.push(T('[BIO: has no closing ]. The bio was not recognized.', '[BIO: hat keine schlie\u00DFende ]. Die Bio wurde nicht erkannt.'));
        }
        var unrecognized = AIW.unrecognizedMarkers(parsed);
        for (var x = 0; x < unrecognized.length; x++) {
            warnings.push(T('Marker not recognized, it stays in the text (check the brackets [ ]): %1', 'Marke nicht erkannt, sie bleibt im Text (Klammern [ ] pr\u00FCfen): %1', unrecognized[x]));
        }
        if (placeholderMode && counts.AUTOR && AIW.trim(state.authorName) === '') {
            warnings.push(T('No author name: the [AUTOR] line will be removed.', 'Kein Autorname: Die Zeile mit [AUTOR] wird entfernt.'));
        }

        // Picture markers and files.
        var markers = AIW.imageMarkers(parsed);
        var seen = {};
        var highest = 0;
        for (var m = 0; m < markers.length; m++) {
            var number = markers[m].number;
            if (seen[number]) {
                warnings.push(T('[Bild %1] appears more than once.', '[Bild %1] kommt mehrfach vor.', number));
            }
            seen[number] = true;
            highest = Math.max(highest, number);
        }
        for (var n = 1; n <= highest; n++) {
            if (!seen[n]) {
                warnings.push(T('Picture numbers skip [Bild %1].', 'Die Bildnummern \u00FCberspringen [Bild %1].', n));
            }
        }

        for (var number2 in seen) {
            if (!seen.hasOwnProperty(number2)) {
                continue;
            }
            if (placeholderMode && !state.pictures['BILD ' + number2]) {
                warnings.push(T('No picture file for [Bild %1].', 'Keine Bilddatei f\u00FCr [Bild %1].', number2));
            }
            if (placeholderMode && !counts['BILD ' + number2]) {
                warnings.push(T('The template has no frame [BILD %1].', 'Die Vorlage hat keinen Rahmen [BILD %1].', number2));
            }
        }
        for (var key in state.pictures) {
            if (placeholderMode && state.pictures.hasOwnProperty(key) && /^BILD \d+$/.test(key) && !seen[key.replace('BILD ', '')]) {
                warnings.push(counts[key]
                    ? T('The text has no marker [Bild %2]: %1 is placed without a caption.', 'Im Text fehlt die Marke [Bild %2]: %1 wird ohne Bildunterschrift platziert.',
                        decodeURI(state.pictures[key].name), key.replace('BILD ', ''))
                    : T('%1 is not placed: the text has no marker [Bild %2] and the template no frame [BILD %2].', '%1 wird nicht platziert: Im Text fehlt die Marke [Bild %2] und in der Vorlage der Rahmen [BILD %2].',
                        decodeURI(state.pictures[key].name), key.replace('BILD ', '')));
            }
        }
        if (placeholderMode) {
            var singles = ['AUFMACHERBILD', 'AUTORENBILD'];
            for (var s = 0; s < singles.length; s++) {
                if (counts[singles[s]] && !state.pictures[singles[s]]) {
                    warnings.push(T('No file for the %1 (e.g. %2.jpg).', 'Keine Datei f\u00FCr das %1 (z. B. %2.jpg).',
                        pictureLabel(singles[s]), singles[s] === 'AUFMACHERBILD' ? 'Aufmacherbild' : 'Autorenbild'));
                } else if (!counts[singles[s]] && state.pictures[singles[s]]) {
                    warnings.push(T('%1 is not placed: the template has no frame [%2].', '%1 wird nicht platziert: Die Vorlage hat keinen Rahmen [%2].',
                        decodeURI(state.pictures[singles[s]].name), singles[s]));
                }
            }
        }

        // Styles that the document does not have.
        var missing = [];
        var roles = AIW.PARAGRAPH_ROLES.concat(AIW.CHARACTER_ROLES);
        for (var r = 0; r < roles.length; r++) {
            var name = state.styles[roles[r]];
            var index = AIW.inArray(roles[r], AIW.CHARACTER_ROLES) ? state.docStyles.character : state.docStyles.paragraph;
            if (name && !index.byName.hasOwnProperty(name)) {
                missing.push(name);
            }
        }
        if (missing.length) {
            var unique = [];
            for (var u = 0; u < missing.length; u++) {
                if (!AIW.inArray(missing[u], unique)) {
                    unique.push(missing[u]);
                }
            }
            warnings.push(T('Styles not in this document (a related style is used): %1', 'Formate fehlen im Dokument (ein verwandtes Format wird verwendet): %1', unique.join(', ')));
        }

        return warnings;
    }

    // ---------------------------------------------------------------------------------------------
    // Dialogs
    // ---------------------------------------------------------------------------------------------

    function chooseDocx(lastFolder) {
        var filter = File.fs === 'Windows'
            ? 'Word (*.docx):*.docx'
            : function (file) {
                return file instanceof Folder || /\.docx$/i.test(file.name);
            };
        var start = lastFolder ? new Folder(lastFolder) : null;
        var file = start && start.exists
            ? new File(start.fsName + '/x.docx').openDlg(T('Choose the article (.docx)', 'Artikel ausw\u00E4hlen (.docx)'), filter, false)
            : File.openDialog(T('Choose the article (.docx)', 'Artikel ausw\u00E4hlen (.docx)'), filter, false);
        return file || null;
    }

    function addInfoRow(parent, label, value) {
        var group = parent.add('group');
        group.alignChildren = ['left', 'top'];
        group.add('statictext', undefined, label).preferredSize.width = 120;
        var text = group.add('statictext', undefined, value === '' ? '\u2013' : value, { truncate: 'end' });
        text.preferredSize.width = 460;
        return text;
    }

    function addFieldRow(parent, label, value) {
        var group = parent.add('group');
        group.add('statictext', undefined, label).preferredSize.width = 120;
        var field = group.add('edittext', undefined, value);
        field.preferredSize.width = 460;
        return field;
    }

    function modeDescription(state) {
        if (state.mode === 'placeholders') {
            var counts = state.template.counts;
            var pictures = [];
            for (var token in counts) {
                if (counts.hasOwnProperty(token) && AIW.isPictureToken(token)) {
                    pictures.push(token);
                }
            }
            return T('Template with placeholders: %1 text frame(s) [TEXT], %2 picture frame(s). The article fills them.',
                'Vorlage mit Platzhaltern: %1 Textrahmen [TEXT], %2 Bildrahmen. Der Artikel wird dort eingesetzt.',
                counts.TEXT, pictures.length);
        }
        return T('No [TEXT] placeholder in this document: the whole article is set into the selected text frame or at the cursor. Pictures are not placed.',
            'Kein [TEXT]-Platzhalter im Dokument: Der ganze Artikel wird in den ausgew\u00E4hlten Textrahmen bzw. an der Einf\u00FCgemarke gesetzt. Bilder werden nicht platziert.');
    }

    /** @return true when the user clicked "Import". */
    function showMainDialog(state, refresh) {
        var dialog = new Window('dialog', SCRIPT_NAME + ' \u2013 ' + decodeURI(state.docx.name));
        dialog.orientation = 'column';
        dialog.alignChildren = ['fill', 'top'];

        var article = dialog.add('panel', undefined, T('Article', 'Artikel'));
        article.alignChildren = ['fill', 'top'];
        addInfoRow(article, T('Title:', 'Titel:'), state.parsed.title);
        addInfoRow(article, T('Subtitle:', 'Untertitel:'), state.parsed.subtitle);
        var authorField = addFieldRow(article, T('Author:', 'Autor:'), state.authorName);
        var travelField = addFieldRow(article, T('Travel period:', 'Reisezeit:'), state.travelPeriod);

        var picturePanel = dialog.add('panel', undefined, T('Pictures', 'Bilder'));
        picturePanel.alignChildren = ['fill', 'top'];
        var folderGroup = picturePanel.add('group');
        var folderText = folderGroup.add('statictext', undefined, state.folder.fsName, { truncate: 'middle' });
        folderText.preferredSize.width = 440;
        var folderButton = folderGroup.add('button', undefined, T('Folder...', 'Ordner...'));
        var pictureList = picturePanel.add('listbox', undefined, [], {
            numberOfColumns: 2,
            showHeaders: true,
            columnTitles: [T('Placeholder', 'Platzhalter'), T('File', 'Datei')],
            columnWidths: [160, 400]
        });
        pictureList.preferredSize = [580, 120];

        var targetPanel = dialog.add('panel', undefined, T('Target', 'Ziel'));
        targetPanel.alignChildren = ['fill', 'top'];
        var targetText = targetPanel.add('statictext', undefined, modeDescription(state), { multiline: true });
        targetText.preferredSize = [580, 36];

        var warningPanel = dialog.add('panel', undefined, T('Please check', 'Bitte pr\u00FCfen'));
        warningPanel.alignChildren = ['fill', 'top'];
        var warningList = warningPanel.add('listbox', undefined, []);
        warningList.preferredSize = [580, 110];

        var buttons = dialog.add('group');
        buttons.alignment = 'right';
        var stylesButton = buttons.add('button', undefined, T('Styles...', 'Formate...'));
        // No button is named "ok", so Enter does not start the import by accident.
        buttons.add('button', undefined, T('Cancel', 'Abbrechen'), { name: 'cancel' });
        var importButton = buttons.add('button', undefined, T('Import', 'Importieren'));

        function update() {
            state.authorName = authorField.text;
            refresh();
            pictureList.removeAll();
            var keys = ['AUFMACHERBILD'];
            var numbers = [];
            for (var key in state.pictures) {
                if (state.pictures.hasOwnProperty(key) && /^BILD \d+$/.test(key)) {
                    numbers.push(parseInt(key.replace('BILD ', ''), 10));
                }
            }
            var markers = AIW.imageMarkers(state.parsed);
            for (var m = 0; m < markers.length; m++) {
                if (!AIW.inArray(markers[m].number, numbers)) {
                    numbers.push(markers[m].number);
                }
            }
            numbers.sort(function (a, b) { return a - b; });
            for (var n = 0; n < numbers.length; n++) {
                keys.push('BILD ' + numbers[n]);
            }
            keys.push('AUTORENBILD');
            for (var k = 0; k < keys.length; k++) {
                var item = pictureList.add('item', pictureLabel(keys[k]));
                item.subItems[0].text = state.pictures[keys[k]] ? decodeURI(state.pictures[keys[k]].name) : '\u2013';
            }

            warningList.removeAll();
            if (!state.warnings.length) {
                warningList.add('item', T('No problems found.', 'Keine Auff\u00E4lligkeiten.'));
            }
            for (var w = 0; w < state.warnings.length; w++) {
                warningList.add('item', state.warnings[w]);
            }
        }

        folderButton.onClick = function () {
            var folder = state.folder.selectDlg(T('Folder with the pictures', 'Ordner mit den Bildern'));
            if (folder) {
                state.folder = folder;
                folderText.text = folder.fsName;
                update();
            }
        };
        stylesButton.onClick = function () {
            var styles = showStylesDialog(state);
            if (styles) {
                state.styles = styles;
                saveStyles(state.doc, styles);
                update();
            }
        };
        authorField.onChange = update;
        importButton.onClick = function () {
            dialog.close(1);
        };

        update();
        if (AUTOCLOSE_DIALOGS) {
            dialog.onShow = function () {
                stylesButton.notify();
                dialog.close(1);
            };
        }
        var result = dialog.show();
        state.authorName = authorField.text;
        state.travelPeriod = travelField.text;
        return result === 1 || AUTOCLOSE_DIALOGS;
    }

    function showStylesDialog(state) {
        var dialog = new Window('dialog', T('Styles for the article', 'Formate f\u00FCr den Artikel'));
        dialog.orientation = 'column';
        dialog.alignChildren = ['fill', 'top'];

        var note = dialog.add('statictext', undefined, T(
            'Choose the style of this document for each part of the article. "Automatic" uses a related style; with placeholders, the style of the placeholder. The choice is saved with the document.',
            'W\u00E4hlen Sie f\u00FCr jeden Teil des Artikels ein Format dieses Dokuments. \u201EAutomatisch\u201C verwendet ein verwandtes Format, bei Platzhaltern das Format des Platzhalters. Die Auswahl wird im Dokument gespeichert.'
        ), { multiline: true });
        note.preferredSize = [560, 48];

        var columns = dialog.add('group');
        columns.alignChildren = ['fill', 'top'];
        var paragraphPanel = columns.add('panel', undefined, T('Paragraph styles', 'Absatzformate'));
        paragraphPanel.alignChildren = ['fill', 'top'];
        var characterPanel = columns.add('panel', undefined, T('Character styles', 'Zeichenformate'));
        characterPanel.alignChildren = ['fill', 'top'];

        var automatic = T('[Automatic]', '[Automatisch]');
        var fontStyle = T('[Font style of the paragraph]', '[Schriftschnitt des Absatzes]');
        var controls = {};

        function addRow(panel, role, names, emptyLabel) {
            var group = panel.add('group');
            group.add('statictext', undefined, roleLabel(role)).preferredSize.width = 190;
            var list = group.add('dropdownlist', undefined, [emptyLabel].concat(names));
            list.preferredSize.width = 220;
            controls[role] = { list: list, names: names };
            select(role, state.styles[role]);
        }

        function select(role, name) {
            var control = controls[role];
            var index = 0;
            for (var i = 0; i < control.names.length; i++) {
                if (control.names[i] === name) {
                    index = i + 1;
                }
            }
            control.list.selection = index;
        }

        for (var p = 0; p < AIW.PARAGRAPH_ROLES.length; p++) {
            addRow(paragraphPanel, AIW.PARAGRAPH_ROLES[p], state.docStyles.paragraph.names, automatic);
        }
        for (var c = 0; c < AIW.CHARACTER_ROLES.length; c++) {
            addRow(characterPanel, AIW.CHARACTER_ROLES[c], state.docStyles.character.names, fontStyle);
        }

        var buttons = dialog.add('group');
        buttons.alignment = 'right';
        var defaultsButton = buttons.add('button', undefined, T('Defaults', 'Standard'));
        buttons.add('button', undefined, T('Cancel', 'Abbrechen'), { name: 'cancel' });
        var applyButton = buttons.add('button', undefined, T('Apply', '\u00DCbernehmen'));

        defaultsButton.onClick = function () {
            for (var role in controls) {
                if (controls.hasOwnProperty(role)) {
                    select(role, AIW.DEFAULT_STYLES[role]);
                }
            }
        };
        applyButton.onClick = function () {
            dialog.close(1);
        };
        if (AUTOCLOSE_DIALOGS) {
            dialog.onShow = function () {
                dialog.close(1);
            };
        }

        if (dialog.show() !== 1 && !AUTOCLOSE_DIALOGS) {
            return null;
        }

        var styles = {};
        for (var role in controls) {
            if (controls.hasOwnProperty(role)) {
                var index = controls[role].list.selection ? controls[role].list.selection.index : 0;
                styles[role] = index > 0 ? controls[role].names[index - 1] : '';
            }
        }
        return AIW.completeStyles(styles);
    }

    // ---------------------------------------------------------------------------------------------
    // Writing into the document
    // ---------------------------------------------------------------------------------------------

    function Context(state) {
        this.doc = state.doc;
        this.docStyles = state.docStyles;
        this.codePoints = state.source.codePoints;
        this.model = state.model;
        this.writer = state.model.writer;
        this.warnings = [];
        this.fontWarnings = {};
        this.placed = 0;
        this.mainStory = null;
        var docStyles = this.docStyles;
        this.paragraphExists = function (name) {
            return docStyles.paragraph.byName.hasOwnProperty(name);
        };
    }

    Context.prototype.paragraphStyle = function (name) {
        return name && this.docStyles.paragraph.byName.hasOwnProperty(name) ? this.docStyles.paragraph.byName[name] : null;
    };

    Context.prototype.characterStyle = function (name) {
        return name && this.docStyles.character.byName.hasOwnProperty(name) ? this.docStyles.character.byName[name] : null;
    };

    /** Length of a JS string in InDesign characters. */
    Context.prototype.idLength = function (text) {
        if (!this.codePoints) {
            return text.length;
        }
        var pairs = text.match(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g);
        return text.length - (pairs ? pairs.length : 0);
    };

    /** InDesign index of a JS index in text. */
    Context.prototype.toId = function (text, index) {
        return this.idLength(text.substring(0, index));
    };

    var installedFonts = null;

    /** app.fonts.itemByName() reports missing styles as valid, so the names are compared. */
    function fontExists(family, fontStyle) {
        if (installedFonts === null) {
            installedFonts = {};
            var names = asArray(app.fonts.everyItem().name);
            for (var i = 0; i < names.length; i++) {
                installedFonts[names[i]] = true;
            }
        }
        return installedFonts.hasOwnProperty(family + '\t' + fontStyle);
    }

    /**
     * The font style for bold/italic text, e.g. "Bold Italic" in an italic paragraph, or '' when
     * the font has no such style.
     */
    function fontStyleFor(range, format) {
        var base = '';
        var family = fontFamilyOf(range);
        try {
            base = String(firstValue(range.fontStyle));
        } catch (e) {}

        var bold = (format & AIW.FORMAT_BOLD) !== 0 || /bold|black|heavy|semibold/i.test(base);
        var italic = (format & AIW.FORMAT_ITALIC) !== 0 || /italic|oblique/i.test(base);
        var candidates = bold && italic
            ? ['Bold Italic', 'Semibold Italic', 'Bold Oblique', 'Black Italic']
            : (bold ? ['Bold', 'Semibold', 'Black', 'Heavy'] : (italic ? ['Italic', 'Oblique', 'It'] : ['Regular', 'Roman', 'Book', 'R']));

        for (var i = 0; i < candidates.length; i++) {
            if (family !== '' && fontExists(family, candidates[i])) {
                return { name: candidates[i], installed: true };
            }
        }
        return { name: candidates[0], installed: false };
    }

    /** The font family of a text; appliedFont is a Font, or a "Family\tStyle" string. */
    function fontFamilyOf(range) {
        try {
            var font = firstValue(range.appliedFont);
            if (typeof font === 'string') {
                return font.split('\t')[0];
            }
            if (font.fontFamily) {
                return String(font.fontFamily);
            }
            return String(font.name).split('\t')[0];
        } catch (e) {
            return '';
        }
    }

    /**
     * Bold/italic as a font style. When the font lacks the style, the text gets it as a missing
     * font (highlighted in InDesign), as in the plugin's IDML export, so the emphasis is not lost.
     */
    function applyFontStyle(ctx, range, format) {
        var fontStyle = fontStyleFor(range, format);
        if (fontStyle.installed) {
            try {
                range.fontStyle = fontStyle.name;
                return;
            } catch (e) {}
        }

        var family = fontFamilyOf(range);
        var kept = false;
        try {
            range.appliedFont = family + '\t' + fontStyle.name;
            kept = true;
        } catch (e) {}

        var key = family + '/' + fontStyle.name;
        if (!ctx.fontWarnings[key]) {
            ctx.fontWarnings[key] = true;
            ctx.warnings.push(kept
                ? T('The font "%1 %2" is not installed; that text is marked as a missing font. Install the font or choose a character style under "Styles...".',
                    'Die Schrift \u201E%1 %2\u201C ist nicht installiert; dieser Text ist als fehlende Schrift markiert. Schrift installieren oder unter \u201EFormate...\u201C ein Zeichenformat w\u00E4hlen.',
                    family, fontStyle.name)
                : T('The font "%1 %2" is not installed; that text stays regular. Choose a character style under "Styles...".',
                    'Die Schrift \u201E%1 %2\u201C ist nicht installiert; dieser Text bleibt normal. Unter \u201EFormate...\u201C ein Zeichenformat w\u00E4hlen.',
                    family, fontStyle.name));
        }
    }

    function addHyperlink(ctx, range, url) {
        try {
            var source = ctx.doc.hyperlinkTextSources.add(range);
            var destination = ctx.doc.hyperlinkURLDestinations.add(url, { hidden: true });
            ctx.doc.hyperlinks.add(source, destination);
        } catch (e) {}
    }

    /**
     * Replace the text [startId, endId) of story by paragraphs (or insert them when the range is
     * empty).
     *
     * options.baseStyle  paragraph style of the placeholder or insertion point
     * options.ownRoles   roles that keep baseStyle (with its local formatting)
     * options.placeholder true: "body" also keeps baseStyle (the [TEXT] placeholder's style wins)
     * options.prefix/suffix extra text (paragraph returns) around the paragraphs
     */
    function writeParagraphs(ctx, story, startId, endId, paragraphs, options) {
        var prepared = [];
        var texts = [];
        for (var i = 0; i < paragraphs.length; i++) {
            var paragraph = paragraphs[i];
            var resolved = ctx.writer.resolveRole(paragraph.style, ctx.paragraphExists);
            var runs = ctx.writer.mergeRuns(ctx.writer.outputRuns(paragraph, resolved));
            var text = '';
            for (var r = 0; r < runs.length; r++) {
                text += runs[r].text;
            }
            prepared.push({ paragraph: paragraph, resolved: resolved, runs: runs });
            texts.push(text);
        }

        var prefix = options.prefix || '';
        var joined = prefix + texts.join('\r') + (options.suffix || '');
        if (endId > startId) {
            story.characters.itemByRange(startId, endId - 1).contents = joined;
        } else if (joined !== '') {
            story.insertionPoints[startId].contents = joined;
        }

        var position = startId + ctx.idLength(prefix);
        for (var p = 0; p < prepared.length; p++) {
            var item = prepared[p];
            var target = story.characters[position].paragraphs[0];

            var style = paragraphStyleFor(ctx, item, options);
            if (style && style.id !== options.baseStyle.id) {
                target.applyParagraphStyle(style, true);
            }
            if (item.paragraph.restart && (item.resolved === 'list_number' || item.resolved === 'list_number_2')) {
                try {
                    target.numberingContinue = false;
                    target.numberingStartAt = 1;
                } catch (e) {}
            }

            for (var k = 0; k < item.runs.length; k++) {
                var run = item.runs[k];
                var length = ctx.idLength(run.text);
                if (length > 0 && ((run.format & 3) || run.link)) {
                    // texts[0]: one Text object; a character range reads properties per character.
                    var range = story.characters.itemByRange(position, position + length - 1).texts[0];
                    var characterStyle = ctx.characterStyle(ctx.writer.characterStyleFor(run));
                    if (characterStyle) {
                        range.appliedCharacterStyle = characterStyle;
                    } else if (run.format & 3) {
                        applyFontStyle(ctx, range, run.format);
                    }
                    if (run.link) {
                        addHyperlink(ctx, range, run.link);
                    }
                }
                position += length;
            }
            position += 1; // paragraph return
        }
    }

    function paragraphStyleFor(ctx, item, options) {
        var own = options.ownRoles || [];
        if (AIW.inArray(item.paragraph.style, own)) {
            return options.baseStyle;
        }
        var resolved = item.resolved;
        if (resolved === '' || (options.placeholder && (AIW.inArray(resolved, own) || resolved === 'body'))) {
            return options.baseStyle;
        }
        return ctx.paragraphStyle(ctx.writer.styleName(resolved)) || options.baseStyle;
    }

    /** Paragraph of a JS index in text: [start, end) without its paragraph return. */
    function paragraphBounds(text, index) {
        var start = index > 0 ? text.lastIndexOf('\r', index - 1) + 1 : 0;
        var end = text.indexOf('\r', index);
        return { start: start, end: end === -1 ? text.length : end };
    }

    /** Remove a paragraph with its return (or the return before it, for the last paragraph). */
    function removeParagraph(ctx, story, text, bounds) {
        if (bounds.end < text.length) {
            story.characters.itemByRange(ctx.toId(text, bounds.start), ctx.toId(text, bounds.end)).remove();
        } else if (bounds.start > 0) {
            story.characters.itemByRange(ctx.toId(text, bounds.start - 1), ctx.toId(text, bounds.end) - 1).remove();
        } else if (bounds.end > 0) {
            story.characters.itemByRange(0, ctx.toId(text, bounds.end) - 1).remove();
        }
    }

    function textFramesOf(story) {
        var frames = [];
        var containers = story.textContainers;
        for (var i = 0; i < containers.length; i++) {
            if (containers[i].constructor.name === 'TextFrame') {
                frames.push(containers[i]);
            }
        }
        return frames;
    }

    function spreadOf(item) {
        var parent = item.parent;
        while (parent) {
            var kind = parent.constructor.name;
            if (kind === 'Spread' || kind === 'MasterSpread') {
                return parent;
            }
            if (kind === 'Document' || kind === 'Application') {
                return null;
            }
            parent = parent.parent;
        }
        return null;
    }

    function spreadOrder(spread) {
        if (!spread) {
            return 200000;
        }
        return (spread.constructor.name === 'MasterSpread' ? 100000 : 0) + spread.index;
    }

    /** Link every [TEXT] frame into one story in reading order and fill it with the body. */
    function fillBody(ctx) {
        var scanned = scanStories(ctx.doc);
        var chains = [];
        for (var i = 0; i < scanned.length; i++) {
            if (!hasToken(scanned[i].tokens, 'TEXT')) {
                continue;
            }
            var frames = textFramesOf(scanned[i].story);
            if (!frames.length) {
                continue;
            }
            var bounds = frames[0].geometricBounds;
            chains.push({
                entry: scanned[i],
                frames: frames,
                key: [spreadOrder(spreadOf(frames[0])), Math.round(bounds[1]), Math.round(bounds[0])]
            });
        }
        if (!chains.length) {
            throw new Error(T('The document has no [TEXT] placeholder.', 'Das Dokument hat keinen [TEXT]-Platzhalter.'));
        }
        chains.sort(function (a, b) {
            for (var k = 0; k < 3; k++) {
                if (a.key[k] !== b.key[k]) {
                    return a.key[k] - b.key[k];
                }
            }
            return 0;
        });

        var main = chains[0].entry;
        var story = main.story;
        var text = main.contents;
        var token = firstToken(main.tokens, 'TEXT');
        var bounds2 = paragraphBounds(text, token.index);
        var baseStyle = story.characters[ctx.toId(text, token.index)].appliedParagraphStyle;

        // The [TEXT] frames hold only the body text; other text in them is template filler.
        for (var c = 1; c < chains.length; c++) {
            chains[c].entry.story.contents = '';
        }
        if (bounds2.end < text.length) {
            story.characters.itemByRange(ctx.toId(text, bounds2.end), ctx.toId(text, text.length) - 1).remove();
        }
        if (bounds2.start > 0) {
            story.characters.itemByRange(0, ctx.toId(text, bounds2.start) - 1).remove();
        }
        var contentLength = ctx.toId(text, bounds2.end) - ctx.toId(text, bounds2.start);

        for (var t = 1; t < chains.length; t++) {
            var previous = chains[t - 1].frames;
            previous[previous.length - 1].nextTextFrame = chains[t].frames[0];
        }

        writeParagraphs(ctx, story, 0, contentLength, ctx.model.body, { baseStyle: baseStyle, ownRoles: ['body'], placeholder: true });
        ctx.mainStory = story;
    }

    function hasToken(tokens, name) {
        return firstToken(tokens, name) !== null;
    }

    function firstToken(tokens, name) {
        for (var i = 0; i < tokens.length; i++) {
            if (tokens[i].token === name) {
                return tokens[i];
            }
        }
        return null;
    }

    /** The smallest graphic frame on the same spread that contains the centre of frame. */
    function frameUnder(frame) {
        var spread = spreadOf(frame);
        if (!spread) {
            return null;
        }
        var bounds = frame.geometricBounds;
        var centerX = (bounds[1] + bounds[3]) / 2;
        var centerY = (bounds[0] + bounds[2]) / 2;
        var best = null;
        var bestArea = Infinity;
        var items = spread.allPageItems;
        for (var i = 0; i < items.length; i++) {
            var kind = items[i].constructor.name;
            if (kind !== 'Rectangle' && kind !== 'Oval' && kind !== 'Polygon') {
                continue;
            }
            var box = items[i].geometricBounds;
            if (centerX < box[1] || centerX > box[3] || centerY < box[0] || centerY > box[2]) {
                continue;
            }
            var area = (box[3] - box[1]) * (box[2] - box[0]);
            if (area < bestArea) {
                best = items[i];
                bestArea = area;
            }
        }
        return best;
    }

    function placePicture(ctx, frame, file) {
        try {
            var fitting = null;
            try {
                fitting = frame.frameFittingOptions.fittingOnEmptyFrame;
            } catch (e) {}
            frame.place(file, false);
            if (fitting === null || fitting == EmptyFrameFittingOptions.NONE) {
                // Fill the frame proportionally, centred.
                frame.fit(FitOptions.FILL_PROPORTIONALLY);
                frame.fit(FitOptions.CENTER_CONTENT);
            }
            ctx.placed++;
        } catch (e) {
            ctx.warnings.push(T('%1 could not be placed: %2', '%1 konnte nicht platziert werden: %2', decodeURI(file.name), errorText(e)));
        }
    }

    /** [AUFMACHERBILD], [BILD n], [AUTORENBILD]: a label frame on top of an empty picture frame. */
    function placePictures(ctx, pictures) {
        var scanned = scanStories(ctx.doc);
        for (var i = 0; i < scanned.length; i++) {
            var story = scanned[i].story;
            if (ctx.mainStory && story.id === ctx.mainStory.id) {
                continue;
            }
            var label = null;
            for (var t = 0; t < scanned[i].tokens.length; t++) {
                if (AIW.isPictureToken(scanned[i].tokens[t].token)) {
                    label = scanned[i].tokens[t].token;
                    break;
                }
            }
            if (!label) {
                continue;
            }

            var frames = textFramesOf(story);
            for (var f = 0; f < frames.length; f++) {
                var target = frameUnder(frames[f]);
                if (!target) {
                    ctx.warnings.push(T('The template has no picture frame under the placeholder [%1].', 'Die Vorlage hat keinen Bildrahmen unter dem Platzhalter [%1].', label));
                } else if (pictures[label]) {
                    placePicture(ctx, target, pictures[label]);
                }
            }
            for (var r = frames.length - 1; r >= 0; r--) {
                frames[r].remove();
            }
        }
    }

    /** [TITEL], [UNTERTITEL], [AUTOR], [REISEZEIT], [BILDUNTERSCHRIFT n], [VORSPANN], [BIO]. */
    function fillPlaceholders(ctx, values) {
        var scanned = scanStories(ctx.doc);
        for (var i = 0; i < scanned.length; i++) {
            var story = scanned[i].story;
            if (ctx.mainStory && story.id === ctx.mainStory.id) {
                continue;
            }

            // From the end of the story backwards: changes never move text that is still to come.
            var limit = Infinity;
            for (var guard = 0; guard < 500; guard++) {
                var text = String(story.contents);
                var tokens = AIW.findTokens(text);
                var token = null;
                for (var t = tokens.length - 1; t >= 0; t--) {
                    if (tokens[t].index < limit && tokens[t].token !== 'TEXT' && !AIW.isPictureToken(tokens[t].token)) {
                        token = tokens[t];
                        break;
                    }
                }
                if (!token) {
                    break;
                }

                var bounds = paragraphBounds(text, token.index);
                var name = token.token;

                if (name === 'VORSPANN' || name === 'BIO') {
                    var generated = name === 'BIO' ? ctx.model.bio : ctx.model.vorspann;
                    if (!generated.length) {
                        removeParagraph(ctx, story, text, bounds);
                    } else {
                        var baseStyle = story.characters[ctx.toId(text, token.index)].appliedParagraphStyle;
                        writeParagraphs(ctx, story, ctx.toId(text, bounds.start), ctx.toId(text, bounds.end), generated, {
                            baseStyle: baseStyle,
                            ownRoles: [name === 'BIO' ? 'bio' : 'vorspann'],
                            placeholder: true
                        });
                    }
                    limit = bounds.start;
                    continue;
                }

                var value = '';
                var caption = /^BILDUNTERSCHRIFT (\d+)$/.exec(name);
                if (caption) {
                    value = ctx.model.captions[parseInt(caption[1], 10)] || '';
                } else {
                    value = values[name] || '';
                }
                value = AIW.trim(String(value).replace(/\s*[\r\n\u2028]+\s*/g, ' '));

                if (value === '') {
                    // No value: the whole line goes, e.g. "Reisezeit: [REISEZEIT]".
                    removeParagraph(ctx, story, text, bounds);
                    limit = bounds.start;
                } else {
                    story.characters.itemByRange(ctx.toId(text, token.index), ctx.toId(text, token.index + token.length) - 1).contents = value;
                    limit = token.index;
                }
            }
        }
    }

    function insertAtSelection(ctx, target) {
        var story = target.story;
        var text = String(story.contents);
        var map = indexMap(text, ctx.codePoints);
        var start = map.toJs(target.start);
        var end = map.toJs(target.end);
        var prefix = start > 0 && text.charAt(start - 1) !== '\r' ? '\r' : '';
        var suffix = end < text.length && text.charAt(end) !== '\r' ? '\r' : '';
        var baseStyle = story.insertionPoints[target.start].appliedParagraphStyle;

        writeParagraphs(ctx, story, target.start, target.end, ctx.model.full, {
            baseStyle: baseStyle,
            ownRoles: [],
            placeholder: false,
            prefix: prefix,
            suffix: suffix
        });
        ctx.mainStory = story;
    }

    function runImport(state) {
        var ctx = new Context(state);
        var view = state.doc.viewPreferences;
        var rulerOrigin = view.rulerOrigin;
        var interaction = app.scriptPreferences.userInteractionLevel;
        try {
            // One coordinate system for all frames of a spread.
            view.rulerOrigin = RulerOrigin.SPREAD_ORIGIN;
            app.scriptPreferences.userInteractionLevel = UserInteractionLevels.NEVER_INTERACT;

            if (state.mode === 'placeholders') {
                fillBody(ctx);
                placePictures(ctx, state.pictures);
                fillPlaceholders(ctx, {
                    TITEL: state.parsed.title,
                    UNTERTITEL: state.parsed.subtitle,
                    AUTOR: state.authorName,
                    REISEZEIT: state.travelPeriod
                });
            } else {
                insertAtSelection(ctx, state.target);
            }

            if (ctx.mainStory && ctx.mainStory.overflows) {
                ctx.warnings.push(T('The text does not fit (overset text). Add pages or frames, or shorten the text.',
                    'Der Text passt nicht (\u00DCbersatztext). Bitte Seiten oder Rahmen erg\u00E4nzen oder k\u00FCrzen.'));
            }
        } finally {
            view.rulerOrigin = rulerOrigin;
            app.scriptPreferences.userInteractionLevel = interaction;
        }
        return ctx;
    }

    // ---------------------------------------------------------------------------------------------
    // Main
    // ---------------------------------------------------------------------------------------------

    function buildModel(state) {
        state.model = AIW.printModel(state.parsed, state.styles, state.source.linkTargets, {
            authorName: state.authorName,
            travelPeriod: state.travelPeriod,
            bio: state.parsed.metadata.bio || ''
        });
    }

    function main() {
        if (app.documents.length === 0) {
            alert(T('Please open the InDesign document or template for the article first.', 'Bitte zuerst das InDesign-Dokument bzw. die Vorlage f\u00FCr den Artikel \u00F6ffnen.'), SCRIPT_NAME);
            return null;
        }

        var doc = app.activeDocument;
        var template = analyzeTemplate(doc);
        var target = template.hasText ? null : selectionTarget();
        if (!template.hasText && !target && !(AUTOMATION && AUTOMATION.target)) {
            alert(T(
                'This document has no [TEXT] placeholder.\n\nEither prepare the template with placeholders ([TITEL], [TEXT], [BILD 1], ...; see the instructions), or select a text frame or click into a text, and run the script again.',
                'Dieses Dokument hat keinen [TEXT]-Platzhalter.\n\nEntweder die Vorlage mit Platzhaltern vorbereiten ([TITEL], [TEXT], [BILD 1] ...; siehe Anleitung) oder einen Textrahmen ausw\u00E4hlen bzw. in einen Text klicken und das Skript erneut starten.'
            ), SCRIPT_NAME);
            return null;
        }

        var settings = readSettings();
        var docx = AUTOMATION ? new File(AUTOMATION.docx) : chooseDocx(settings.lastFolder);
        if (!docx) {
            return null;
        }
        if (!/\.docx$/i.test(docx.name)) {
            alert(T('Please save the article in Word as .docx.', 'Bitte den Artikel in Word als .docx speichern.'), SCRIPT_NAME);
            return null;
        }
        if (!docx.exists) {
            alert(T('The file %1 was not found.', 'Die Datei %1 wurde nicht gefunden.', docx.fsName), SCRIPT_NAME);
            return null;
        }
        settings.lastFolder = docx.parent.fsName;
        writeSettings(settings);

        var state = {
            doc: doc,
            docx: docx,
            template: template,
            target: target,
            mode: template.hasText ? 'placeholders' : 'selection',
            docStyles: documentStyles(doc),
            styles: loadStyles(doc),
            folder: docx.parent
        };

        try {
            state.source = readDocx(docx);
        } catch (e) {
            alert(T('The Word file could not be read: %1', 'Die Word-Datei konnte nicht gelesen werden: %1', errorText(e)), SCRIPT_NAME, true);
            return null;
        }
        state.parsed = AIW.parseDocument(state.source.paragraphs);
        state.authorName = state.parsed.metadata.author_name || '';
        state.travelPeriod = state.parsed.metadata.travel_period || '';

        function refresh() {
            state.pictures = findPictures(state.folder);
            state.warnings = collectWarnings(state);
        }

        if (AUTOMATION) {
            if (AUTOMATION.pictures) {
                state.folder = new Folder(AUTOMATION.pictures);
            }
            if (AUTOMATION.author !== undefined) {
                state.authorName = AUTOMATION.author;
            }
            if (AUTOMATION.travelPeriod !== undefined) {
                state.travelPeriod = AUTOMATION.travelPeriod;
            }
            if (AUTOMATION.styles) {
                state.styles = AIW.completeStyles(AUTOMATION.styles);
            }
            if (AUTOMATION.target) {
                state.target = AUTOMATION.target;
            }
        }
        if ((!AUTOMATION || AUTOCLOSE_DIALOGS) && !showMainDialog(state, refresh)) {
            return null;
        }
        refresh();
        buildModel(state);

        var ctx = null;
        var failure = null;
        app.doScript(function () {
            try {
                ctx = runImport(state);
            } catch (e) {
                failure = e;
            }
        }, ScriptLanguage.JAVASCRIPT, undefined, UndoModes.ENTIRE_SCRIPT, SCRIPT_NAME);

        if (failure) {
            if (!AUTOMATION) {
                alert(T('The import stopped with an error: %1\n\nEdit > Undo reverts the import.', 'Der Import wurde mit einem Fehler abgebrochen: %1\n\nBearbeiten > R\u00FCckg\u00E4ngig macht den Import r\u00FCckg\u00E4ngig.', errorText(failure)), SCRIPT_NAME, true);
            }
            return { error: errorText(failure), warnings: state.warnings };
        }

        var lines = [T('"%1" was imported.', '\u201E%1\u201C wurde importiert.', state.parsed.title)];
        if (state.mode === 'placeholders') {
            lines.push(T('Pictures placed: %1', 'Platzierte Bilder: %1', ctx.placed));
        }
        var notes = ctx.warnings.concat(state.warnings);
        if (notes.length) {
            lines.push('');
            lines.push(T('Please check:', 'Bitte pr\u00FCfen:'));
            for (var n = 0; n < notes.length; n++) {
                lines.push('\u2022 ' + notes[n]);
            }
        }
        lines.push('');
        lines.push(T('Edit > Undo reverts the whole import.', 'Bearbeiten > R\u00FCckg\u00E4ngig macht den ganzen Import r\u00FCckg\u00E4ngig.'));

        if (!AUTOMATION) {
            alert(lines.join('\n'), SCRIPT_NAME);
        }
        return { error: '', placed: ctx.placed, warnings: notes, title: state.parsed.title };
    }

    var outcome = main();
    if (AUTOMATION) {
        AIW_AUTOMATION.result = outcome;
    }
})();
