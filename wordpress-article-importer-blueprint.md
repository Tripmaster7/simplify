# WordPress Article Importer Plugin Blueprint

## 1) Goal
Build a guided import wizard for non-technical staff that creates draft articles from DOCX files, while separating:
- Internal operator (logged-in user running import)
- Public author (real author selected by membership number)

Public frontend output must never expose the operator identity.

## 2) Core Rules
- Input format: DOCX only (reject DOC and others)
- Membership number doubles as username
- Membership number is required for each import
- Public author is resolved from membership number
- Operator is stored for audit only
- Imported posts are always saved as draft

## 3) Plugin Structure
- wp-content/plugins/article-import-wizard/
  - article-import-wizard.php
  - includes/
    - class-aiw-admin-menu.php
    - class-aiw-wizard-controller.php
    - class-aiw-docx-parser.php
    - class-aiw-image-mapper.php
    - class-aiw-link-validator.php
    - class-aiw-author-service.php
    - class-aiw-content-builder.php
    - class-aiw-notifier.php
    - class-aiw-shortcode-service.php
    - class-aiw-frontend-author.php
    - class-aiw-settings.php
  - templates/
    - wizard-step-upload.php
    - wizard-step-author.php
    - wizard-step-images.php
    - wizard-step-bio.php
    - wizard-step-review.php
  - assets/
    - css/admin.css
    - js/admin.js

## 4) Data Model

### 4.1 Post Meta
Store the following on each imported post:
- _aiw_imported: 1
- _aiw_import_status: pending|completed|errors
- _aiw_attributed_author_user_id: int (real public author)
- _aiw_imported_by_user_id: int (operator, internal only)
- _aiw_imported_at: UTC datetime string
- _aiw_doc_source_filename: original filename
- _aiw_validation_report: JSON

### 4.2 User Meta (for real author profile)
Store reusable author profile data on the real author user account:
- aiw_author_bio: long text
- aiw_author_image_id: attachment ID
- aiw_author_job_title: optional
- aiw_author_short_bio: optional

Username itself is the membership number (no extra field required unless future needs change).

### 4.3 Options (Plugin Settings)
- aiw_restriction_shortcode_start
- aiw_restriction_shortcode_end
- aiw_inline_image_slots (default 3, configurable)
- aiw_notify_email
- aiw_link_check_timeout_ms
- aiw_replace_broken_links_mode (replace|flag_only)

## 5) Wizard Flow (Admin)

### Step 1: Upload DOCX
- Validate nonce and capability
- Validate MIME and extension
- Parse document body and placeholders
- Extract: title, subtitle, writing date, markers, links

### Step 2: Resolve Public Author by Membership Number
- Prompt for membership number
- Lookup by username
- If not found: block completion and show actionable error
- Show resolved author name to operator for confirmation

### Step 3: Headline + Inline Images
- Ask for headline image
- Ask for configurable number of inline images
- Save attachments in Media Library

### Step 4: Bio Data
- Prefill bio/image from resolved author user meta
- Allow operator to update bio/image for this author
- Save updates back to resolved author user meta

### Step 5: Build Content
- Insert restriction shortcode start/end in configured positions
- Replace image hints like [Bild 1: Beschreibung] with uploaded image blocks
- Append public bio box

### Step 6: Validate Links
- Check HTTP(S) links
- If invalid and mode=replace: replace anchor text with span CHECK LINK (red)
- If invalid and mode=flag_only: keep link and add warning entry

### Step 7: Save Draft + Notify
- Create/update post as draft
- Set internal audit meta
- Set attributed author meta
- Email configured recipient with summary and link to draft

## 6) Author Identity Separation (Critical)

### Internal identity (never public)
- current_user_id stored in _aiw_imported_by_user_id only
- Used for audit trail and troubleshooting

### Public identity (frontend)
- Derived from _aiw_attributed_author_user_id
- Used for byline and bio box rendering

### Do not trust default theme byline behavior
Many themes output post_author directly. If post_author remains operator, it leaks wrong author publicly.

Use frontend filters to override displayed author for imported posts.

## 7) WordPress Hooks and Filters

### 7.1 Admin + Routing
- admin_menu: register wizard page
- admin_post_aiw_start_import: handle uploads
- admin_post_aiw_save_step: handle wizard step submissions
- admin_enqueue_scripts: load wizard JS/CSS

### 7.2 Settings
- admin_init: register settings and fields

### 7.3 Post Save / Metadata
- save_post: optional final validation marker updates

### 7.4 Frontend Author Override (important)
Use these filters so imported posts display attributed author, not operator:
- the_author
- get_the_author_display_name
- author_link
- get_the_archive_title (if author archive title is used contextually)

Practical logic in each filter:
- if post has _aiw_imported=1 and _aiw_attributed_author_user_id exists:
  - return data from attributed author user object
- else:
  - return default

### 7.5 Content Injection
- the_content: append bio box only when imported flag exists and not in admin

### 7.6 Async/Background Link Check (recommended)
- action_scheduler or wp_cron custom hook:
  - aiw_run_link_validation_job

## 8) Capabilities and Security

### 8.1 Who can use wizard
Allow roles Author and Editor through custom capability check:
- map to edit_posts and upload_files minimum

### 8.2 Security controls
- Nonce on every step
- sanitize_text_field for membership number and text fields
- wp_kses_post for rich text content
- absint for IDs
- strict upload validation
- escape output in admin templates

### 8.3 Privacy and audit
- Operator identity only in post meta and optional private log table
- Never render operator fields in frontend templates

## 9) Placeholder Strategy
Supported inline marker pattern examples:
- [Bild 1]
- [Bild 1: Beschreibung]
- [Image 1]

Regex suggestion:
- /\[(Bild|Image)\s*(\d+)\s*(?::[^\]]*)?\]/i

Replacement logic:
- Parse marker number N
- Map to uploaded image slot N
- Replace token with figure/img HTML block
- If missing upload for N, insert warning marker in content and validation report

## 10) Bio Box Rendering
Use a generated block appended at end of content:
- author image from aiw_author_image_id
- display name from attributed user
- bio from aiw_author_bio

Recommendation:
- Render via template partial and escape fields
- Add CSS class namespace aiw-bio-box to avoid theme collisions

## 11) Link Validation Rules
- Validate only http and https links
- Use wp_remote_head first; fallback wp_remote_get
- Timeout configurable (short, e.g. 5-8s)
- Consider 200-399 valid
- 400+, timeout, DNS failures invalid

When invalid:
- replace mode: substitute anchor text with <span class="aiw-check-link">CHECK LINK</span>
- flag mode: keep link and add to validation report

## 12) Notification Email
Send one summary email on completion:
- post title
- draft edit URL
- attributed author (membership number + display name)
- operator user ID (internal)
- link validation summary counts

Use wp_mail with configurable recipient option.

## 13) Suggested MVP Build Order
1. Admin menu + wizard shell
2. DOCX upload + parser integration
3. Membership lookup by username
4. Draft creation with meta fields
5. Inline image replacement
6. Bio profile read/write + bio box output
7. Frontend author override filters
8. Link validation and notification

## 14) Acceptance Criteria
- Operator can import without touching normal post editor
- Membership number required and validated
- Public byline and bio always show attributed author, never operator
- Imported post saved as draft with full audit meta
- Broken links handled according to setting
- Notification email sent on completion

## 15) What to do differently from naive implementation
- Enforce structured DOCX template instead of free-form parsing
- Do link checks asynchronously to avoid request timeout
- Keep author attribution separate from post ownership for role safety
- Persist author bio data on user profile for reuse
- Include review screen before final draft creation
