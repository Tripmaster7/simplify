# Artikel-Import for InDesign

An InDesign script that imports a Word article (`.docx`) into the open InDesign document. It reads the same Word file as the [Article Import Wizard](../article-import-wizard/) for WordPress, with the same markers and rules, and fills the same template placeholders as the plugin's InDesign export.

- **Template with placeholders:** the article goes into the frames marked `[TITEL]`, `[TEXT]`, `[BILD 1]` and so on. The pictures are placed as well.
- **Any other document:** select a text frame (or click into a text) and the whole article is set there, using the document's own paragraph styles.

**Version:** 1.0.0 · **File:** [ArtikelImport.jsx](ArtikelImport.jsx) · **Tested with:** InDesign 2027 on Windows. Written for InDesign CS6 and newer on Windows and Mac; other versions are untested.

The dialogs and messages are in German when InDesign runs in German, otherwise in English.

## Installation

1. In InDesign, open **Window > Utilities > Scripts**.
2. Right-click **User** and choose **Reveal in Explorer** (Windows) or **Reveal in Finder** (Mac).
3. Copy `ArtikelImport.jsx` into the `Scripts Panel` folder that opens.
4. The script now appears under **User** in the Scripts panel.

## Importing an Article

1. Open the template, or the document the article should go into.
   Without placeholders: select the text frame for the article, or click into a text where it should start.
2. Double-click **ArtikelImport.jsx** in the Scripts panel.
3. Choose the Word file (`.docx`).
4. Check the dialog:
   - **Author** and **Travel period** are filled in from the Word file and can be changed.
   - **Pictures** lists the picture files found for each placeholder. **Folder...** chooses another folder.
   - **Please check** lists problems such as a missing picture, a skipped picture number or a marker that was not recognized.
   - **Styles...** chooses the paragraph and character styles (see [Styles](#styles)).
5. Click **Import**. Enter does not start the import; only the button does.

A message then lists what was done and what needs checking, for example overset text. **Edit > Undo** reverts the whole import in one step.

## The Word File

The Word file is written exactly as for the WordPress import; see the [editorial cheat sheet](../editorial-cheat-sheet.md) (German: [Redaktions-Spickzettel](../article-import-wizard/docs/editorial-cheat-sheet.md)).

| Marker | In InDesign |
| --- | --- |
| `[TITLE: ...]` | `[TITEL]`; without the marker, a paragraph in Word's *Title* or *Heading 1* style, otherwise the first paragraph, which then is not repeated in the body text |
| `[SUBTITLE: ...]` | `[UNTERTITEL]` |
| `[REISEZEIT: ...]` | `[REISEZEIT]`, editable in the dialog |
| `[BIO: ...]` | `[BIO]`. It may span several paragraphs; end it with `]`. |
| `[HEADER ...]`, `[HEADER 3 ...]`, `[HEADER 4 ...]` | Headings in the body text |
| `[Bild n: caption]`, `[Image n]` | `[BILDUNTERSCHRIFT n]` gets the caption; the picture file *Bild n* goes into `[BILD n]` |
| `[AUTHOR_NAME: ...]` | Fills in the author in the dialog. WordPress ignores this marker; it finds the author from the membership number. |
| `[AUTHOR_MEMBERSHIP: ...]`, `[WRITING_DATE: ...]`, `[RESTRICT]` | Only used on the website; removed in print |

As on the website, the first paragraph of the article becomes the Vorspann (intro). Bold, italic, links and bulleted or numbered lists (also nested) are kept. Links become InDesign hyperlinks, so they work in an interactive PDF. Underline, colours, fonts, tables and pictures inside the Word file are not taken over.

## Pictures

The script looks for picture files in the folder of the Word file, and in its subfolders `Links`, `Bilder`, `Images` and `Fotos`. The file name decides where a picture goes; upper and lower case, spaces, `_` and `-` do not matter.

| Placeholder | File names, e.g. |
| --- | --- |
| `[AUFMACHERBILD]` | `Aufmacherbild.jpg`, `Titelbild.jpg` |
| `[BILD n]` | `Bild 1.jpg`, `Bild_1.jpg`, `Bild-01.tif`, `Image 1.png` |
| `[AUTORENBILD]` | `Autorenbild.jpg`, `BioPic.jpg` |

JPEG, PNG, TIFF, PSD, PDF, EPS and AI files are placed, linked to the original file. The ZIP file from the WordPress **Download for InDesign** has this layout too (`Links/Aufmacherbild.jpg`, `Links/Bild-1.jpg`, ...).

## Template with Placeholders

The placeholders are the same as for the WordPress InDesign export (see [Preparing the Article Template](../README.md#preparing-the-article-template)). A template prepared for one works with the other.

| Placeholder | Where | Filled with |
| --- | --- | --- |
| `[TITEL]` | Title frame | Article title |
| `[UNTERTITEL]` | Subtitle frame | `[SUBTITLE]` from the Word file |
| `[AUTOR]` | Anywhere in a line, e.g. `Text und Fotos: [AUTOR]` | Author name from the dialog |
| `[REISEZEIT]` | Anywhere in a line, e.g. `Reisezeit: [REISEZEIT]` | Travel period from the dialog |
| `[VORSPANN]` | Own paragraph in the intro frame | First paragraph of the article |
| `[TEXT]` | Every body text frame | The body text. All `[TEXT]` frames are linked into one story in reading order: spread by spread, left to right, top to bottom. Other text in these frames is replaced. |
| `[BIO]` | Own paragraph in the author box | Author bio; the rest of the box stays |
| `[BILDUNTERSCHRIFT n]` | Caption frame | Caption from `[Bild n: caption]` |
| `[BILD n]`, `[AUFMACHERBILD]`, `[AUTORENBILD]` | Small text frame on top of an empty picture frame | The picture is placed into the picture frame below and the small label frame is removed. Without *Frame Fitting Options* on the frame, the picture fills it proportionally, centred. |

- A line whose placeholder has no value is removed, for example `Reisezeit: [REISEZEIT]` without a travel period.
- Picture frames without a picture stay empty; text that does not fit stays overset. The layout team adjusts the page count by hand.
- Text placeholders keep their paragraph style and local formatting. Headings, lists and captions in the body text use the styles chosen under **Styles...**.
- Everything else in the template (rubrics, side tabs, footers, master pages) stays as it is.

## Any Other Document

If the document has no `[TEXT]` placeholder, the script needs a target: a selected text frame (the article is added at the end of its story), a text cursor, or selected text (which is replaced). The whole article is set there in this order: title, subtitle, author, Vorspann, body text with `[Bild n]` markers and captions, author name and bio. Pictures are not placed, because the script cannot know where they go; the `[Bild n]` lines show where they belong.

## Styles

**Styles...** in the dialog chooses a style of the open document for each part of the article. The choice is saved in the document. A template saved after the choice keeps it, and the last choice is also the starting point for other documents.

| Article part | Default style | Without that style |
| --- | --- | --- |
| Title | Artikeltitel | Body text |
| Subtitle, author line | Untertitel | Body text |
| Vorspann | Vorspann | Body text |
| Body text | Fliesstext | The style of the `[TEXT]` placeholder or of the text at the cursor |
| First body paragraph, and the first after each heading | *(none)* | Body text |
| `[HEADER]` / `[HEADER 3]` / `[HEADER 4]` | Zwischenueberschrift | Body text / level 2 / level 3 |
| Bulleted and numbered lists, level 1 and 2 | *(none)* | Body text, with the bullet or number as text |
| Picture marker `[Bild n]` (only without placeholders) | *(none)* | Body text |
| Caption | Bildunterschrift | Body text |
| Author box: name, text | Zwischenueberschrift, Vorspann | Heading, body text |
| Bold, italic, bold italic, links | *(none)* | Font style of the paragraph's font, e.g. *Bold Italic* in an italic paragraph |

These are the defaults of the WordPress plugin. With placeholders, title, subtitle, author line, Vorspann, captions and author bio always take the style of their placeholder; the body text takes the style of `[TEXT]`.

When the paragraph's font has no bold or italic style, the text is still marked with it, so InDesign shows it as a missing font and the emphasis is not lost. The message names the font; install it, or choose a character style for bold and italic under **Styles...**.

## Messages

Before the import, the dialog lists problems in the Word file and the folder:

- no title; a marker that appears twice; `[BIO:` without a closing `]`
- a marker that was not recognized and stays in the text, e.g. `[HEADER Warnungen}` with the wrong bracket
- skipped or repeated picture numbers, a `[Bild n]` without a picture file, a picture file without a marker (it is placed without a caption), or a picture the template has no frame for
- style names that the document does not have

After the import, the message adds overset text and missing font styles.

## Differences from the WordPress Import

- The author is not looked up: the name comes from `[AUTHOR_NAME: ...]` or is typed into the dialog. A bio stored in WordPress is not available; only `[BIO: ...]` from the Word file is used.
- Links are not checked.
- The pictures come from a folder instead of an upload.
- The Word file is read with InDesign's own Word import. Its result is converted to the same paragraphs as in WordPress. List levels are taken from the indentation, because InDesign's import does not keep them.

## For Developers

The script has two parts:

- **`AIW` (core):** the import rules, without InDesign. It is a JavaScript port of `AIW_DOCX_Parser`, `AIW_ICML_Writer` and the placeholder handling of `AIW_IDML_Builder` in the plugin. **A change to the import format or the template rules must be made in both places.**
- **InDesign part:** reads the Word file into a hidden document, shows the dialogs and writes into the open document as one undo step.

The file is plain ASCII: German text and special characters are written as `\u` escapes, so ExtendScript reads the file the same way on every system. The script must stay ExtendScript (ES3): no `let`, `const`, arrow functions, `Array.prototype.indexOf`, `String.prototype.trim` or `JSON`.

### Tests

```text
node indesign-article-import/tests/core.test.js          Rules of the core (Node.js, no InDesign needed)
python indesign-article-import/tests/make-test-docx.py   Rebuilds tests/fixtures/Testartikel.docx
indesign-article-import/tests/run-in-indesign.jsx        Imports into a template in InDesign without dialogs
```

`run-in-indesign.jsx` opens a template, imports an article, and writes a report (`.txt`), a PDF, an IDML file and a JPEG of each spread. It also checks that one undo reverts the import. See the comment at the top of the file for its settings. On Windows it can be started from PowerShell:

```powershell
$indesign = New-Object -ComObject InDesign.Application
$indesign.DoScript('C:\path\to\runner.jsx', 1246973031)   # 1246973031 = JavaScript
```

`Testartikel.docx` uses every rule of the format: markers, headings, bold and italic, a link, a nested list, picture markers, an unrecognized marker, a bio over two paragraphs and an emoji, which checks the character counting.

### Package for Others

`python indesign-article-import/package/build-package.py` writes `ArtikelImport-InDesign-v<version>.zip` to the repository root. It is a standalone package for people without access to this repository: the script, a German and an English guide (`package/Anleitung.html`, `package/Guide.html`, also as PDF) and an example article with pictures. It needs Pillow, and Edge or Chrome for the PDFs.
