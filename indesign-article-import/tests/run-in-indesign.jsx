//@target indesign
/*
 * Test harness: imports an article into a template without dialogs and exports the result.
 *
 * Define AIW_TEST before running this file, e.g. from a small script:
 *   var AIW_TEST = {
 *       script: '/path/ArtikelImport.jsx',
 *       template: '/path/DemoVorlage-Platzhalter.idml',
 *       docx: '/path/article.docx',
 *       pictures: '/path/to/pictures',      // optional, default: folder of the DOCX
 *       author: 'Christel Loock',           // optional
 *       travelPeriod: 'Mai 2024',           // optional
 *       selection: false,                   // true: insert into a new frame on page 1 instead
 *       dialogs: false,                     // true: also open (and auto-close) the dialogs
 *       output: '/path/result'              // writes result.pdf, result.idml and result.txt
 *   };
 *   $.evalFile('/path/run-in-indesign.jsx');
 */

(function () {
    var report = [];
    var interaction = app.scriptPreferences.userInteractionLevel;
    app.scriptPreferences.userInteractionLevel = UserInteractionLevels.NEVER_INTERACT;
    var doc = app.open(new File(AIW_TEST.template), true);
    app.scriptPreferences.userInteractionLevel = interaction;
    try {
        AIW_AUTOMATION = {
            docx: AIW_TEST.docx,
            pictures: AIW_TEST.pictures,
            author: AIW_TEST.author,
            travelPeriod: AIW_TEST.travelPeriod
        };
        if (AIW_TEST.dialogs) {
            // Open the main and the styles dialog; both close at once with their default action.
            AIW_DIALOG_AUTOCLOSE = true;
        }
        if (AIW_TEST.selection) {
            // A new frame inside the margins of page 1, as if the user had selected it.
            var page = doc.pages[0];
            var bounds = page.bounds;
            var margins = page.marginPreferences;
            var frame = page.textFrames.add({
                geometricBounds: [bounds[0] + margins.top, bounds[1] + margins.left, bounds[2] - margins.bottom, bounds[3] - margins.right]
            });
            var story = frame.parentStory;
            AIW_AUTOMATION.target = { story: story, start: 0, end: 0 };
        }

        $.evalFile(new File(AIW_TEST.script));
        var result = AIW_AUTOMATION.result;

        report.push('error: ' + (result ? result.error : 'no result'));
        report.push('placed: ' + (result ? result.placed : ''));
        report.push('warnings:');
        for (var w = 0; result && w < result.warnings.length; w++) {
            report.push('  - ' + result.warnings[w]);
        }

        report.push('stories:');
        for (var s = 0; s < doc.stories.length; s++) {
            var st = doc.stories[s];
            report.push('  story ' + st.id + ' frames=' + st.textContainers.length + ' overset=' + st.overflows);
            for (var p = 0; p < st.paragraphs.length && p < 60; p++) {
                var paragraph = st.paragraphs[p];
                var text = String(paragraph.contents).replace(/\r$/, '').replace(/\n/g, '\\n');
                var styles = [];
                var ranges = paragraph.textStyleRanges;
                for (var r = 0; r < ranges.length; r++) {
                    var fs = String(ranges[r].fontStyle);
                    var cs = ranges[r].appliedCharacterStyle.name;
                    styles.push(fs + (cs.charAt(0) === '[' ? '' : '/' + cs) + ':' + String(ranges[r].contents).substring(0, 12));
                }
                report.push('    [' + paragraph.appliedParagraphStyle.name + '] ' + text.substring(0, 90)
                    + (ranges.length > 1 ? '   {' + styles.join(' | ') + '}' : ''));
            }
        }

        report.push('graphics:');
        for (var g = 0; g < doc.allGraphics.length; g++) {
            var graphic = doc.allGraphics[g];
            report.push('  ' + graphic.itemLink.name + ' in frame ' + graphic.parent.geometricBounds.join(',') + ' page ' + (graphic.parentPage ? graphic.parentPage.name : '-'));
        }
        report.push('hyperlinks: ' + doc.hyperlinks.length);

        doc.exportFile(ExportFormat.PDF_TYPE, new File(AIW_TEST.output + '.pdf'), false);

        // One small JPEG per spread, to look at the result.
        var jpeg = app.jpegExportPreferences;
        jpeg.exportResolution = 50;
        jpeg.jpegExportRange = ExportRangeOrAllPages.EXPORT_RANGE;
        jpeg.exportingSpread = true;
        for (var sp = 0; sp < doc.spreads.length; sp++) {
            jpeg.pageString = doc.spreads[sp].pages[0].name;
            doc.exportFile(ExportFormat.JPG, new File(AIW_TEST.output + '-spread' + (sp + 1) + '.jpg'), false);
        }

        // The import is one undo step: one undo brings the placeholders back.
        if (!AIW_TEST.selection) {
            doc.undo();
            var restored = 0;
            for (var u = 0; u < doc.stories.length; u++) {
                if (/\[(TEXT|TITEL)\]/.test(String(doc.stories[u].contents))) {
                    restored++;
                }
            }
            report.push('after one undo, stories with [TEXT]/[TITEL]: ' + restored + ', graphics: ' + doc.allGraphics.length);
        }
        doc.exportFile(ExportFormat.INDESIGN_MARKUP, new File(AIW_TEST.output + '.idml'), false);
    } catch (e) {
        report.push('HARNESS ERROR: ' + e.message + ' line ' + e.line);
    } finally {
        doc.close(SaveOptions.NO);
        AIW_DIALOG_AUTOCLOSE = undefined;
    }

    var file = new File(AIW_TEST.output + '.txt');
    file.encoding = 'UTF-8';
    file.open('w');
    file.write(report.join('\n'));
    file.close();
})();
