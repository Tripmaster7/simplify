# Simplify WordPress Article Import Wizard

A guided WordPress admin workflow for turning structured DOCX files into review-ready draft posts. The plugin is designed for editors and authors who should not need to assemble an article manually in the block editor.

**Current version:** 0.1.5

**Author:** GitHub Copilot and Jens

## What It Does

- Imports article text and metadata from DOCX files
- Generates a full preview before creating a post
- Resolves the public author from a required membership number (WordPress username)
- Keeps the importing operator in private audit metadata
- Requires at least one existing or newly created category
- Uploads a featured image, configurable inline images, and an author image
- Replaces numbered image markers with WordPress image blocks
- Inserts configurable content-restriction shortcodes at the article boundary
- Reuses and updates author biographies stored in WordPress user metadata
- Validates HTTP and HTTPS links before draft creation
- Replaces broken links with `CHECK LINK` or reports them without changing the link
- Creates the finished article as a WordPress draft
- Sends an import summary email with a link to the draft
- Includes German admin translations and a printable editorial cheat sheet

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- PHP `ZipArchive` extension for DOCX parsing
- A WordPress user for each public author, with the membership number as the username
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
4. Review or update the author's biography.
5. Generate the preview. The plugin parses the document, maps images, builds block markup, inserts restriction shortcodes, and checks links.
6. Review warnings, categories, author attribution, and the generated article.
7. Confirm to create the draft and send the notification email.

Preview data is temporary. If it expires, generate a new preview before creating the draft.

## DOCX Markers

The importer recognizes these metadata markers:

```text
[TITLE: Article headline]
[SUBTITLE: Article subtitle]
[AUTHOR_MEMBERSHIP: 123456]
[AUTHOR_NAME: Max Mustermann]
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

The first valid metadata value wins. Duplicate values and a membership number that differs from the wizard entry are reported as preview warnings. The wizard membership number remains the source of truth.

The legacy `[RESTRICT_START]` and `[RESTRICT_END]` markers are accepted for compatibility. New documents should use `[RESTRICT]`; the configured closing shortcode is inserted immediately before the generated author bio box.

See [editorial-cheat-sheet.md](editorial-cheat-sheet.md) for the short authoring guide or [editorial-cheat-sheet-print.html](editorial-cheat-sheet-print.html) for the printable version.

## Settings

The settings page controls:

- Restriction start shortcode (default: `[restrict]`)
- Restriction end shortcode (default: `[/restrict]`)
- Default inline image slots, from 0 to 20 (default: 3)
- Import notification email address
- Link-check timeout, from 1,000 to 20,000 ms (default: 6,000 ms)
- Broken-link handling: replace with `CHECK LINK` or flag only

These defaults can be adjusted for an individual import before generating its preview.

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

- DOCX parsing is intentionally based on structured paragraphs and markers; it is not a general-purpose Word-to-HTML converter.
- Link checks run during preview generation, so documents with many links can take longer to process.
- Imports create new drafts rather than updating existing posts.
