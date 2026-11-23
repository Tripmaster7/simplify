# Simplify WordPress Article Import Wizard

A guided WordPress admin workflow for turning structured DOCX files into review-ready posts. The plugin is designed for editors and authors who should not need to assemble an article manually in the block editor.

**Current version:** 1.4.0

**Author:** GitHub Copilot and Jens

## Contributors

- **Jens** - Product owner and repository maintainer
- **GitHub Copilot** - AI coding and documentation contributor

## What It Does

- Imports article text and metadata from DOCX files, keeping bold, italic, hyperlinks, and bullet or numbered lists (including nested lists)
- Generates a full preview before saving or publishing a post
- Lets editors with publishing permission choose draft or immediate publication
- Prevents users without `publish_posts` permission from publishing
- Resolves the public author from a membership number or author name
- Falls back to a configurable default publisher (any editor or author, chosen in the settings) when no matching author is found, or to the currently logged-in WordPress user if none is set. Several matching names can either stop the import or also use the default publisher.
- Keeps the importing operator in private audit metadata
- Requires at least one existing or newly created category
- Uploads a featured image, configurable inline images, and an author image
- Replaces numbered image markers with WordPress image blocks
- Restricts every article with configurable shortcodes: the start goes at the `[RESTRICT]` marker or automatically after the 2nd paragraph (earlier if the first paragraph reaches 1,000 characters; both limits are configurable); the end always goes after the last paragraph, so the author bio stays public
- Reuses and updates author biographies stored in WordPress user metadata
- Validates HTTP and HTTPS links before draft creation
- Replaces broken links with `CHECK LINK` or reports them without changing the link
- Creates the finished article as a draft or publishes it immediately, depending on the editor's choice
- Sends an import summary email with a link to the draft
- Exports the article for the printed magazine: fills the InDesign article template (`.idml`) with text, captions and pictures, or provides an InCopy story (`.icml`) when no template is set
- Adds a "back to overview" link to sub-pages of selected pages, such as the chapters of a multi-page guide
- Includes German admin translations and a printable editorial cheat sheet

## Requirements

- WordPress 7.0 or newer
- PHP 8.3 or newer
- PHP `ZipArchive` extension for DOCX parsing
- A WordPress user for each public author, with the membership number as the username (five digits starting with 0, for example `01234`)
- `edit_posts` and `upload_files` capabilities for import operators

## Installation

Download `article-import-wizard-v1.4.0.zip` from the [latest GitHub release](https://github.com/Tripmaster7/simplify/releases/latest) and upload it under **Plugins > Add New > Upload Plugin** (replace the existing plugin when prompted), or install manually:

1. Copy the `article-import-wizard` directory to `wp-content/plugins/`.
2. Activate **Article Import Wizard** in WordPress.
3. Open **Settings > Article Import Wizard** and review the defaults.
4. Open the article import wizard from the WordPress admin menu.

## Import Workflow

1. Enter the author's membership number and select at least one category.
2. Upload a DOCX file and optionally override the extracted title or content.
3. Upload the featured image, inline images, and author image as needed.
4. Review or update the author's biography. If the field is left empty, a `[BIO: ...]` marker from the DOCX is used, and otherwise the bio already stored on the author's profile. The `[BIO: ...]` marker may span several paragraphs; everything up to the closing `]` is the bio, and each paragraph stays a paragraph in the bio box. Web addresses in the bio (`https://...` or `www....`) become links and are included in the link check.
5. Generate the preview. The plugin parses the document, maps images, builds block markup, inserts restriction shortcodes, and checks links.
6. Review warnings, categories, author attribution, and the generated article.
7. Choose whether to save the article as a draft or publish it immediately, then confirm. Users without publishing permission always save drafts.
8. Open the resulting article or start another import.

Preview data is temporary. If it expires, generate a new preview before creating the draft. Images uploaded for a preview that never becomes a draft are deleted automatically by a daily cleanup task after 24 hours.

Immediate publication requires a confirmation dialog, including when it is the administrator's configured default. Cancelling leaves the preview open without saving.

If a DOCX has no explicit title marker or title style, its first text paragraph becomes the title and is not repeated in the body. Prefer a `[TITLE: ...]` marker or Word title style when the opening paragraph is intended as article text.

Inline pictures require matching `[Bild N]` or `[Image N]` markers. The preview warns about uploaded or reused inline picture slots without a matching marker; it does not insert those pictures automatically.

## Updating an Article

Uploading an article again updates the existing one instead of creating a duplicate. An upload matches an earlier import when it has the same title (ignoring upper/lower case and extra spaces) and the same author (membership number). Trashed articles are not matched.

On an update:

- **Unchanged:** title and author. A draft stays a draft; an existing published article stays published unless it is explicitly recreated as a new article.
- **Replaced:** article text, categories (the wizard selection), restriction placement, and the bio in the bio box (and on the author's profile).
- **Pictures:** a newly uploaded picture replaces that slot; slots without a new upload keep their previous picture. A new headline picture replaces the featured image; otherwise it is kept.
- **Post date:** set from `[WRITING_DATE]` if present, otherwise kept.

The preview names the article that will be updated and warns when it is published (the change goes live on confirmation), when it was edited in WordPress after its last import (those edits are overwritten), or when several articles match (the most recently changed one is used). WordPress keeps the previous version as a revision. The editor can tick **Create as a new article instead** to leave the existing article untouched, and needs permission to edit the matched article.

Articles imported before version 1.1.0 have no saved picture slots, so all pictures must be uploaded again on their first update.

The update is recorded in `_aiw_updated_at`, `_aiw_updated_by_user_id` and `_aiw_update_count`; the original `_aiw_imported_at` and `_aiw_imported_by_user_id` are kept.

## DOCX Markers

The importer recognizes these metadata markers:

```text
[TITLE: Article headline]
[SUBTITLE: Article subtitle]
[AUTHOR_MEMBERSHIP: 01234]
[WRITING_DATE: 2026-08-04]
[BIO: Short author biography]
[REISEZEIT: Mai bis August 2024]
[RESTRICT]
```

Supported aliases include `HEADLINE` or `H1`, `SUBHEADER` or `H2`, `MEMBERSHIP` or `MEMBER_ID`, and `DATE`.

Use explicit body headings in their own DOCX paragraphs:

```text
[HEADER 3 Section heading]
[HEADER 4 Subsection heading]
```

Use numbered image placeholders where inline images should appear:

```text
[Bild 1]
[Bild 2: Optional caption]
[Image 3]
```

`[REISEZEIT: ...]` (alias `TRAVEL_PERIOD`) is only used in print, for the travel period in the byline. In print, the first paragraph of the article also becomes the Vorspann (intro); on the website it stays the first paragraph.

`[WRITING_DATE: ...]` becomes the post date. It accepts `2025-01-15` or `15.01.2025`; an invalid or future date is reported in the preview. Without the marker, WordPress uses the publish date.

The first valid metadata value wins. Duplicate values and a membership number that differs from the wizard entry are reported as preview warnings. The wizard membership number remains the source of truth.

### Restricted content

Every imported article gets a restricted part:

- **Start:** at the `[RESTRICT]` marker. Without a marker, the start is inserted automatically after the 2nd text paragraph, or earlier after the paragraph in which the text reaches 1,000 characters (spaces included). Both limits can be changed in the settings. Headings, images and lists are not counted. The preview warns when the automatic position was used.
- **End:** always directly after the last part of the article and before the author bio box, so the bio is always public. A `[RESTRICT_END]` marker in the document is ignored.
- If nothing follows the start (for example `[RESTRICT]` at the very end), the preview warns that the restricted part is empty.

The legacy `[RESTRICT_START]` marker is still accepted and treated like `[RESTRICT]`.

See [editorial-cheat-sheet.md](editorial-cheat-sheet.md) for the short authoring guide or [editorial-cheat-sheet-print.html](editorial-cheat-sheet-print.html) for the printable version.

## Settings

The settings page (Settings → Article Import Wizard) is only available to administrators (`manage_options`).

**Restricted Content**

- Restriction start shortcode, required (default: `[restrict]`)
- Restriction end shortcode, required (default: `[/restrict]`). If it is left empty, the closing tag is derived from the start shortcode, for example `[restrict level="2"]` gives `[/restrict]`.
- Paragraphs before restriction, from 1 to 10 (default: 2): without a `[RESTRICT]` marker, the restricted part starts after this many text paragraphs at the latest
- Characters before restriction, from 100 to 10,000 (default: 1,000): without a `[RESTRICT]` marker, the restricted part starts earlier, after the paragraph in which the text reaches this many characters

Shortcodes must form a single opening/closing pair, for example `[restrict subscription="1" message="Members only"]` and `[/restrict]`. Nested brackets, incomplete quotes, self-closing opening tags, and mismatched closing tags are rejected during preview generation and import confirmation. Invalid opening settings retain the previous value and display an error; missing or mismatched closing settings are derived from a valid opening tag with a warning. A site administrator must correct existing malformed settings before importing again.

**Import Defaults**

- Default status after review: save as draft or publish immediately (default: draft). Only preselects the choice for users with `publish_posts`; everyone else always creates drafts.
- Default categories, preselected in the wizard (default: none)
- Default inline image slots, from 0 to 20 (default: 3)
- Link-check timeout per link, from 1,000 to 20,000 ms (default: 6,000 ms). The whole link check is capped at 20 seconds; links not checked in that time are listed as a preview warning.
- Broken-link handling: replace with `CHECK LINK` or flag only

**Authors**

- Default publisher: the editor or author used when no author is entered or found (default: the logged-in user)
- Several matching authors: show an error and ask for a more specific name (default), or use the default publisher. The preview warns whenever the default publisher was used for an entered name.
- Membership number format: a regular expression the whole entry must match (default: `0\d{4}`). An entry in this format is looked up as a username only.
- Update author profile: always save a new bio and image (default), only if the profile has none yet, or never. The article itself always uses the uploaded bio and image.
- Author bio box: add it at the end of imported articles (default: on). When it is off, the restriction still ends after the last paragraph.

**Access**

- Who may use the wizard: the roles allowed to open it. Administrators always have access. With no role selected (default), every role that has `edit_posts` and `upload_files` can use it. The menu entry is hidden from everyone else.
- Simplified admin menu (default: on): everyone except administrators sees only **Posts**, **Media** and **Article Import** in the admin menu, also hiding menus added by other plugins. The dashboard is hidden and redirects to the wizard (or to the post list for users who may not use it), and the top toolbar keeps only the site link and the account menu (profile, log out). This only tidies up the screen; what users may do is still decided by their WordPress role.

After a normal WordPress login, Authors and Editors with wizard access land on **Article Import**, regardless of the simplified-menu setting. Administrators (including users with `manage_options`), other roles, and failed logins keep the normal redirect. Explicit destination links through the standard WordPress login, such as an article editing link, are preserved.

The Restrict Content Pro `[login_form]` frontend login is also supported through its `rcp_login_redirect_url` filter. Eligible Authors and Editors go directly to **Article Import** instead of the form's return URL, including when that URL points back to the login page. Administrators, other roles, and users without wizard access keep the membership plugin's destination.

If a frontend login handler reloads a page containing `[login_form]` instead of using a redirect filter, eligible logged-in Authors and Editors are sent to **Article Import**. Other pages and login recovery requests with `rcp_action` or `action` query parameters are left unchanged.

**Notifications**

- Send an email after each completed import (default: on)
- Notification email address

**InDesign Export**

- InDesign download (default: on): shows **Download for InDesign** in the wizard and an **InDesign** link in the post list
- Article template (IDML) with placeholders, checked on upload (see [Preparing the Article Template](#preparing-the-article-template))
- Style names in the template: the paragraph and character style used for each part of the article (see [Style Names](#style-names))

The inline image slots and broken-link handling can be adjusted for an individual import before generating its preview.

## Page Navigation

Under **Page Navigation > Back Link on Sub-Pages**, the settings page lists every page that has sub-pages. For each selected page, its published sub-pages show a link back to it at the top and bottom, for example "← Zurück zur Übersicht: dzg Ausrüstungs Ratgeber" on each chapter of a guide.

The link is added when the page is displayed and is not stored in the page content, so editing or replacing a sub-page never removes or duplicates it, and it always uses the overview's current title and address. It only appears while the overview page is published and not password-protected. It uses the CSS class `aiw-back-link`.

## InDesign Export

Articles can also be laid out in the printed magazine. **Download for InDesign** is offered in the preview (before the import is completed), next to **Open Article** after the import, and as an **InDesign** link under the title of imported posts in the post list. It downloads a ZIP file:

```text
<Title>.zip
|-- <Title>.idml            The article template, filled with this article
|-- Links/
|   |-- Aufmacherbild.jpg   Headline picture
|   |-- Bild-1.jpg          Picture for [Bild 1], Bild-2.jpg for [Bild 2], ...
|   `-- Autorenbild.jpg     Author picture
`-- Hinweise.txt            Only when something could not be placed
```

Unpack the ZIP and open the `.idml` file in InDesign. If InDesign reports missing links, select all links in the Links panel and use **Relink to Folder** with the `Links` folder.

### Preparing the Article Template

An administrator uploads the template under **Settings > Article Import Wizard > InDesign Export > Article Template (IDML)**. Save it in InDesign with **File > Export > InDesign Markup (IDML)**. Before that, the layout team types placeholders into the template's frames, each in the paragraph style the text should get:

| Placeholder | Where | Filled with |
| --- | --- | --- |
| `[TITEL]` | Title frame | Article title |
| `[UNTERTITEL]` | Subtitle frame | `[SUBTITLE]` from the DOCX |
| `[AUTOR]` | Anywhere in a line, e.g. `Text und Fotos: [AUTOR]` | Public author name |
| `[REISEZEIT]` | Anywhere in a line, e.g. `Reisezeit: [REISEZEIT]` | `[REISEZEIT: ...]` from the DOCX, otherwise month and year of the wizard's travel date |
| `[VORSPANN]` | Own paragraph in the intro frame | First paragraph of the article |
| `[TEXT]` | Every body text frame | The body text. All `[TEXT]` frames are linked into one story in reading order: spread by spread, left to right, top to bottom. Other text in these frames is replaced. |
| `[BIO]` | Own paragraph in the author box | Author bio; the rest of the box (e.g. the "Unterwegs-Sein" rubric) stays |
| `[BILDUNTERSCHRIFT n]` | Caption frame | Caption from `[Bild n: caption]` |
| `[BILD n]`, `[AUFMACHERBILD]`, `[AUTORENBILD]` | Small text frame on top of an empty picture frame | The picture is placed into the picture frame below, filling it proportionally; the small label frame is removed |

A line whose placeholder has no value is removed, for example `Reisezeit: [REISEZEIT]` without a travel period, or a caption frame for a picture the article does not have. Picture frames without a picture stay empty. Everything else in the template (rubrics, side tabs, footers, master pages) stays as it is. After the upload, the settings page lists the placeholders it found; a template without `[TEXT]` is rejected.

The body text uses the styles of the template: body paragraphs keep the style of the `[TEXT]` placeholder, headings use the heading style from **Style Names in the Template** (default: `Zwischenueberschrift`). Bold and italic become the matching font style of the paragraph's font (for example *Bold Italic* in an italic paragraph), unless character styles are named in the settings. Without list styles, list items keep their bullet or number as text. Picture markers (`[Bild n]`) are taken out of the body text, because the pictures sit in their own frames. Text that does not fit stays overset; frames left over stay empty, so the layout team adjusts the page count by hand.

The file `DemoVorlage-Platzhalter.idml` in the repository is the demo layout with all placeholders, as an example for preparing the real template.

### Without a Template

Without a template, the ZIP contains `<Title>.icml`, an InCopy story with the whole article (title, subtitle, author, Vorspann, body, captions, bio), instead of the `.idml`. Place it into a text frame with **File > Place**; InDesign uses the document's own definition of every style with the same name.

### Style Names

| Article part | Default style |
| --- | --- |
| Title | Artikeltitel |
| Subtitle, author line | Untertitel |
| Vorspann (first paragraph) | Vorspann |
| Body text | Fliesstext |
| First body paragraph and after each heading | *(empty: body text)* |
| `[HEADER]`, `[HEADER 3]`, `[HEADER 4]` | Zwischenueberschrift *(levels 3 and 4 empty: same style)* |
| Bulleted and numbered lists | *(empty: body text with bullet or number as text)* |
| Picture caption | Bildunterschrift |
| Author box: name, text | Zwischenueberschrift, Vorspann |
| Bold, italic, bold italic, links | *(empty: font style of the paragraph's font)* |

The defaults are the style names of the demo template. All names can be changed under **Settings > Article Import Wizard > InDesign Export**; a style inside a style group is written `Group:Style`. With a template, title, subtitle, author line, Vorspann, captions and author box always take the style of their placeholder, so these names only matter for the InCopy file.

Large photos are packed as the full-size original, not the copy WordPress scales down for the website. The print data is stored with the post (`_aiw_print_article`) when the import is completed, so the download stays available later. Posts imported before this feature have no print data; import them again to get the download.

### InDesign Script

The InDesign script in [indesign-article-import/](indesign-article-import/) imports the same Word file directly in InDesign, without WordPress: into a template with the placeholders above, or into a selected text frame of any document. See its [README](indesign-article-import/README.md).

## Author Attribution and Audit Data

The attributed author and importing operator are intentionally separate:

- The draft's WordPress author is the user resolved from the membership number.
- `_aiw_attributed_author_user_id` stores the public author relationship.
- `_aiw_imported_by_user_id` stores the operator for internal auditing only.
- Author bio and image data are stored on the attributed user's profile for reuse.

The plugin also records the source filename, import time, selected categories, restriction shortcodes, and a JSON validation report in post metadata.

## Security and Content Safety

- Every import action uses WordPress nonce verification and capability checks.
- Membership numbers, settings, text fields, IDs, and uploaded filenames are sanitized.
- Only `.docx` files are accepted by the import handler.
- Uploaded DOCX archives are size- and entry-count limited.
- New posts use the editor's selected status: draft or publish.
- Publishing is enforced server-side and requires the WordPress `publish_posts` capability.
- Link checks use WordPress's safe HTTP API, so URLs pointing at private or loopback addresses are not requested.
- The operator identity is never inserted into public article content.

## Regression Checks


With PHP 8.3+ (including DOM, SimpleXML and ZipArchive) and Node.js available:

```powershell
php tests\regression.php
node tests\publish-confirmation.cjs
```

The JavaScript check renders the real PHP preview template and exercises its confirmation handlers. An optional first argument selects a PHP executable outside `PATH`.

## Repository Layout

```text
article-import-wizard/
|-- article-import-wizard.php
|-- assets/                 Admin and frontend CSS/JS
|-- docs/                   Editorial cheat sheets bundled with the plugin
|-- includes/               Import, parsing, author, link, and settings services
|-- languages/              German translation files
`-- templates/              Wizard step templates
indesign-article-import/
|-- ArtikelImport.jsx       InDesign script: imports the same DOCX into an InDesign document
`-- tests/                  Node tests of the import rules, test DOCX, InDesign test harness
```

The detailed implementation blueprint is available in [wordpress-article-importer-blueprint.md](wordpress-article-importer-blueprint.md).

## Current Scope

- DOCX parsing is intentionally based on structured paragraphs and markers; it is not a general-purpose Word-to-HTML converter. Bold, italic, links and lists are kept; underline, colours, fonts, tables and text boxes are not. Markdown syntax is not interpreted.
- Link checks run during preview generation, so documents with many links can take longer to process.
- Hyperlinks inserted with Word's link feature are imported as links and checked. Only `http`, `https` and `mailto` targets become links; links to bookmarks inside the document, and any other target, are kept as plain text.
- Updates match on title and author only. Changing the title in the document creates a new article.
