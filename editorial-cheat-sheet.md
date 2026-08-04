# Editorial Cheat Sheet: WordPress Article Import Wizard

Print this page and keep it next to your workstation.

## 1) Quick Workflow
1. Prepare article in Word (.docx).
2. Open WordPress > Article Import Wizard.
3. Enter membership number of the real author.
4. Upload DOCX.
5. Upload headline image and inline images.
6. Click Generate Preview.
7. Check warnings (links/images).
8. Click Create Draft From Preview.

## 2) Golden Rules
- Always save as DOCX (.docx), not .doc.
- Membership number must be correct.
- The logged-in editor is internal only.
- Public author is always the member from membership number.

## 3) Metadata Tags (inside DOCX)
Use these tags exactly as written.

Required or strongly recommended:
- [TITLE: Your headline]
- [SUBTITLE: Your sub-headline]
- [WRITING_DATE: 2026-08-04]
- [AUTHOR_MEMBERSHIP: 123456]

Optional:
- [AUTHOR_NAME: First Last]
- [BIO: Short author bio text]
- [RESTRICT]

Legacy compatibility only:
- [RESTRICT_START]
- [RESTRICT_END]

## 4) Inline Image Markers (inside article text)
Use markers where images should appear:
- [Bild 1]
- [Bild 2: Short caption]
- [Image 3: English caption]

Rules:
- Numbering starts at 1.
- Upload images in the same order.
- If marker and upload count do not match, preview shows warning.

## 5) Copy/Paste DOCX Template
Copy this block into a new Word document and fill it in.

[TITLE: ]
[SUBTITLE: ]
[WRITING_DATE: ]
[AUTHOR_MEMBERSHIP: ]
[AUTHOR_NAME: ]

[RESTRICT]

First paragraph...

Second paragraph with image marker [Bild 1: Caption].

Another paragraph with [Bild 2].

[RESTRICT_END]  (legacy fallback only; the wizard normally adds the closing shortcode before the bio)

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
- Membership number confirmed
- Metadata tags filled
- Image markers and uploads match
- Preview reviewed
- Draft created

## 9) Common Mistakes
- Wrong file type (.doc instead of .docx)
- Missing membership number
- Broken marker format (missing closing ])
- Image numbers skipped (Bild 1, Bild 3)
- Forgetting preview step
