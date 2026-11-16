# Simplify WordPress Article Import Wizard

A guided WordPress admin workflow for turning structured DOCX files into review-ready draft posts. The plugin is designed for editors and authors who should not need to assemble an article manually in the block editor.

**Current version:** 1.1.0

**Author:** GitHub Copilot and Jens

## What It Does

- Imports article text and metadata from DOCX files, keeping bold, italic, hyperlinks, and bullet or numbered lists (including nested lists)
- Generates a full preview before creating a post
- Resolves the public author from a required membership number (WordPress username)
- Keeps the importing operator in private audit metadata
- Requires at least one existing or newly created category
- Uploads a featured image, configurable inline images, and an author image
- Replaces numbered image markers with WordPress image blocks
- Restricts every article with configurable shortcodes: the start goes at the `[RESTRICT]` marker or automatically after the 2nd paragraph (earlier if the first paragraph reaches 1,000 characters); the end always goes after the last paragraph, so the author bio stays public
- Reuses and updates author biographies stored in WordPress user metadata
- Validates HTTP and HTTPS links before draft creation
- Replaces broken links with `CHECK LINK` or reports them without changing the link
- Creates the finished article as a WordPress draft
- Sends an import summary email with a link to the draft
- Includes German admin translations and a printable editorial cheat sheet

## Requirements

- WordPress 7.0 or newer
- PHP 8.3 or newer
- PHP `ZipArchive` extension for DOCX parsing
- A WordPress user for each public author, with the membership number as the username (five digits starting with 0, for example `01234`)
- `edit_posts` and `upload_files` capabilities for import operators

## Installation

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
7. Confirm to create the draft and send the notification email.

Preview data is temporary. If it expires, generate a new preview before creating the draft. Images uploaded for a preview that never becomes a draft are deleted automatically by a daily cleanup task after 24 hours.

## Updating an Article

Uploading an article again updates the existing one instead of creating a duplicate. An upload matches an earlier import when it has the same title (ignoring upper/lower case and extra spaces) and the same author (membership number). Trashed articles are not matched.

On an update:

- **Unchanged:** title, author and status. A draft stays a draft; a published article stays published.
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

`[WRITING_DATE: ...]` becomes the post date. It accepts `2025-01-15` or `15.01.2025`; an invalid or future date is reported in the preview. Without the marker, WordPress uses the publish date.

The first valid metadata value wins. Duplicate values and a membership number that differs from the wizard entry are reported as preview warnings. The wizard membership number remains the source of truth.

### Restricted content

Every imported article gets a restricted part:

- **Start:** at the `[RESTRICT]` marker. Without a marker, the start is inserted automatically after the 2nd text paragraph, or after the 1st paragraph if it alone reaches 1,000 characters (spaces included). Headings, images and lists are not counted. The preview warns when the automatic position was used.
- **End:** always directly after the last part of the article and before the author bio box, so the bio is always public. A `[RESTRICT_END]` marker in the document is ignored.
- If nothing follows the start (for example `[RESTRICT]` at the very end), the preview warns that the restricted part is empty.

The legacy `[RESTRICT_START]` marker is still accepted and treated like `[RESTRICT]`.

See [editorial-cheat-sheet.md](editorial-cheat-sheet.md) for the short authoring guide or [editorial-cheat-sheet-print.html](editorial-cheat-sheet-print.html) for the printable version.

## Settings

The settings page controls:

- Restriction start shortcode, required (default: `[restrict]`)
- Restriction end shortcode, required (default: `[/restrict]`). If it is left empty, the closing tag is derived from the start shortcode, for example `[restrict level="2"]` gives `[/restrict]`.
- Default inline image slots, from 0 to 20 (default: 3)
- Import notification email address
- Link-check timeout per link, from 1,000 to 20,000 ms (default: 6,000 ms). The whole link check is capped at 20 seconds; links not checked in that time are listed as a preview warning.
- Broken-link handling: replace with `CHECK LINK` or flag only

The inline image slots and broken-link handling can be adjusted for an individual import before generating its preview.

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
- Generated posts always use `draft` status.
- Link checks use WordPress's safe HTTP API, so URLs pointing at private or loopback addresses are not requested.
- The operator identity is never inserted into public article content.

## Repository Layout

```text
article-import-wizard/
|-- article-import-wizard.php
|-- assets/                 Admin and frontend CSS/JS
|-- docs/                   Editorial cheat sheets bundled with the plugin
|-- includes/               Import, parsing, author, link, and settings services
|-- languages/              German translation files
`-- templates/              Wizard step templates
```

The detailed implementation blueprint is available in [wordpress-article-importer-blueprint.md](wordpress-article-importer-blueprint.md).

## Current Scope

- DOCX parsing is intentionally based on structured paragraphs and markers; it is not a general-purpose Word-to-HTML converter. Bold, italic, links and lists are kept; underline, colours, fonts, tables and text boxes are not. Markdown syntax is not interpreted.
- Link checks run during preview generation, so documents with many links can take longer to process.
- Hyperlinks inserted with Word's link feature are imported as links and checked. Only `http`, `https` and `mailto` targets become links; links to bookmarks inside the document, and any other target, are kept as plain text.
- Updates match on title and author only. Changing the title in the document creates a new article.
