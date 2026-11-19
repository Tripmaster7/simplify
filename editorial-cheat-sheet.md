# Editorial Cheat Sheet: WordPress Article Import Wizard

Print this page and keep it next to your workstation.

## 1) Quick Workflow
1. Prepare article in Word (.docx).
2. Log in to WordPress. The Article Import Wizard opens directly (otherwise click **Article Import** in the menu).
3. Enter the name or membership number of the real author.
4. Upload DOCX.
5. Check the categories. Some may already be preselected.
6. Upload headline image and inline images.
7. Click **Generate Preview**.
8. Check warnings (author, links, images).
9. Under **After review**, check the choice: save as draft or publish immediately.
10. Click **Complete Import**.

The menu only shows **Posts**, **Media** and **Article Import**. Your profile and log out are at the top right under your name.

## 2) Golden Rules
- Always save as DOCX (.docx), not .doc.
- The author must be right: enter the full name or the membership number.
- If no matching author is found, the article gets the default author and the preview shows a warning. Correct the entry and generate the preview again.
- If several authors match the name, enter a more specific name or the membership number.
- The logged-in editor is internal only.
- To update an article, upload it again with the same title and author. Title and author cannot change; text, pictures, categories and bio are replaced. Only upload pictures that changed.

## 3) Metadata Tags (inside DOCX)
Use these tags exactly as written.

Required or strongly recommended:
- [TITLE: Your headline]
- [SUBTITLE: Your sub-headline]
- [WRITING_DATE: 2026-08-04] (becomes the post date; 04.08.2026 also works)
- [AUTHOR_MEMBERSHIP: 01234]

Optional:
- [BIO: Short author bio text] (may span several paragraphs; end it with ])
- [RESTRICT] (start of the members-only part; without it: automatically, by default after the 2nd paragraph or earlier once the text reaches 1,000 characters)
- [HEADER Section headline]
- [HEADER 3 Section headline]
- [HEADER 4 Section headline]

Heading rule:
- H1 is reserved for the article title.
- H2 comes from the subtitle or from a plain `[HEADER ...]` in the body text.
- For deeper levels in the body text, use `[HEADER 3 ...]` and `[HEADER 4 ...]`.

The end of the members-only part is added automatically after the last paragraph; the author bio is always public.

Legacy compatibility only:
- [RESTRICT_START] (same as [RESTRICT])
- [RESTRICT_END] (ignored)

## 4) Inline Image Markers (inside article text)
Use markers where images should appear:
- [Bild 1]
- [Bild 2: Short caption]
- [Image 3: English caption]

Rules:
- Numbering starts at 1.
- Upload images in the same order.
- If marker and upload count do not match, preview shows warning.

Formatting:
- Use Word's normal formatting: **bold**, *italic*, links, bullet and numbered lists (also nested) are kept.
- Underline, colours, fonts and tables are not kept.
- Do not type Markdown (`**bold**`, `# Heading`); it appears literally.

## 5) Copy/Paste DOCX Template
Copy this block into a new Word document and fill it in.

[TITLE: ]
[SUBTITLE: ]
[WRITING_DATE: ]
[AUTHOR_MEMBERSHIP: ]

First paragraph...

Second paragraph with image marker [Bild 1: Caption].

[RESTRICT]  (optional; without it the restricted part starts automatically, by default after the 2nd paragraph)

Another paragraph with [Bild 2].

[HEADER Section headline]
Body paragraph for level H2.

[HEADER 3 Background]
Body paragraph for level H3.

[HEADER 4 Detail]
Body paragraph for level H4.

[BIO: ]

## 6) Image Checklist
- Headline image uploaded
- Inline images uploaded in correct order
- Author bio image uploaded (if needed)

## 7) Link Checklist
Before finalizing draft:
- Check preview warnings for invalid links
- If CHECK LINK appears in red, fix that link before publishing

## 8) Final Pre-Upload Checklist
- DOCX format confirmed
- Author confirmed (name or membership number), no author warning in the preview
- Categories checked
- Metadata tags filled
- Image markers and uploads match
- Preview reviewed
- Draft or publish chosen on purpose
- Import completed

## 9) Common Mistakes
- Wrong file type (.doc instead of .docx)
- Author missing or misspelled: the article then gets the default author
- Preselected categories or "Publish immediately" not checked
- Broken marker format (missing closing ])
- Image numbers skipped (Bild 1, Bild 3)
- Forgetting preview step
