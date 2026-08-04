<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap aiw-wrap">
    <h1><?php esc_html_e('Article Import Wizard', 'article-import-wizard'); ?></h1>

    <?php if (!empty($error)) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html(rawurldecode($error)); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($message)) : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html(rawurldecode($message)); ?></p>
            <?php if (isset($_GET['aiw_edit_url'])) : ?>
                <?php $decoded_edit_url = esc_url_raw(rawurldecode(sanitize_text_field(wp_unslash($_GET['aiw_edit_url'])))); ?>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url($decoded_edit_url); ?>">
                        <?php esc_html_e('Open Draft', 'article-import-wizard'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="aiw-card">
        <h2><?php esc_html_e('Guided Article Import', 'article-import-wizard'); ?></h2>
        <p><?php esc_html_e('Upload a DOCX file or provide content manually. Public attribution always uses the membership number user account.', 'article-import-wizard'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('aiw_create_draft_nonce'); ?>
            <input type="hidden" name="action" value="aiw_create_draft" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="aiw_membership_number"><?php esc_html_e('Membership Number', 'article-import-wizard'); ?></label></th>
                    <td>
                        <input id="aiw_membership_number" name="aiw_membership_number" type="text" class="regular-text" required />
                        <p class="description"><?php esc_html_e('Must match the author username exactly.', 'article-import-wizard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_post_title"><?php esc_html_e('Article Title', 'article-import-wizard'); ?></label></th>
                    <td>
                        <input id="aiw_post_title" name="aiw_post_title" type="text" class="regular-text" />
                        <p class="description"><?php esc_html_e('Optional if title is available in DOCX.', 'article-import-wizard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_docx_file"><?php esc_html_e('DOCX File', 'article-import-wizard'); ?></label></th>
                    <td>
                        <input id="aiw_docx_file" name="aiw_docx_file" type="file" accept=".docx" />
                        <p class="description"><?php esc_html_e('DOCX is parsed for title/content/placeholders.', 'article-import-wizard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_post_content"><?php esc_html_e('Article Content', 'article-import-wizard'); ?></label></th>
                    <td>
                        <textarea id="aiw_post_content" name="aiw_post_content" rows="12" class="large-text" placeholder="Paste parsed or prepared content here..."></textarea>
                        <p class="description"><?php esc_html_e('Optional if content is available in DOCX.', 'article-import-wizard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_restriction_start"><?php esc_html_e('Restriction Start Shortcode', 'article-import-wizard'); ?></label></th>
                    <td><input id="aiw_restriction_start" name="aiw_restriction_start" type="text" class="regular-text" value="<?php echo esc_attr($default_restriction_start); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_restriction_end"><?php esc_html_e('Restriction End Shortcode', 'article-import-wizard'); ?></label></th>
                    <td><input id="aiw_restriction_end" name="aiw_restriction_end" type="text" class="regular-text" value="<?php echo esc_attr($default_restriction_end); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_headline_image"><?php esc_html_e('Headline Picture', 'article-import-wizard'); ?></label></th>
                    <td><input id="aiw_headline_image" name="aiw_headline_image" type="file" accept="image/*" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_inline_image_count"><?php esc_html_e('Inline Picture Count', 'article-import-wizard'); ?></label></th>
                    <td>
                        <input id="aiw_inline_image_count" name="aiw_inline_image_count" type="number" min="0" max="20" value="<?php echo esc_attr((string) $default_inline_slots); ?>" />
                        <p class="description"><?php esc_html_e('Upload images in order. They replace [Bild 1], [Bild 2], ... markers.', 'article-import-wizard'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_inline_images"><?php esc_html_e('Inline Pictures', 'article-import-wizard'); ?></label></th>
                    <td><input id="aiw_inline_images" name="aiw_inline_images[]" type="file" accept="image/*" multiple /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_author_bio"><?php esc_html_e('Author Bio', 'article-import-wizard'); ?></label></th>
                    <td>
                        <textarea id="aiw_author_bio" name="aiw_author_bio" rows="4" class="large-text" placeholder="If provided, this updates the resolved author's profile bio."></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_author_image"><?php esc_html_e('Author Bio Picture', 'article-import-wizard'); ?></label></th>
                    <td><input id="aiw_author_image" name="aiw_author_image" type="file" accept="image/*" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiw_broken_link_mode"><?php esc_html_e('Broken Link Handling', 'article-import-wizard'); ?></label></th>
                    <td>
                        <select id="aiw_broken_link_mode" name="aiw_broken_link_mode">
                            <option value="replace" <?php selected($default_broken_link_mode, 'replace'); ?>><?php esc_html_e('Replace with CHECK LINK', 'article-import-wizard'); ?></option>
                            <option value="flag_only" <?php selected($default_broken_link_mode, 'flag_only'); ?>><?php esc_html_e('Flag only', 'article-import-wizard'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Create Draft', 'article-import-wizard')); ?>
        </form>
    </div>
</div>
