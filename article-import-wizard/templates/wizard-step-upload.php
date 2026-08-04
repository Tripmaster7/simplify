<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap aiw-wrap">
    <div class="aiw-header-row">
        <h1><?php esc_html_e('Article Import Wizard', 'article-import-wizard'); ?></h1>
        <a class="button" href="<?php echo esc_url((string) $help_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Help', 'article-import-wizard'); ?></a>
    </div>

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

    <?php if (is_array($preview_data)) : ?>
        <div class="aiw-card">
            <h2><?php esc_html_e('Preview Before Draft Creation', 'article-import-wizard'); ?></h2>
            <p><?php esc_html_e('Review this generated article. If everything looks good, create the draft.', 'article-import-wizard'); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Restriction Start Shortcode', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['restriction_start']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Restriction End Shortcode', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['restriction_end']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Membership Number', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['membership_number']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Resolved Public Author', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['attributed_author_name']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Title', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['post_title']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Source Document', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['source_filename']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Author Bio for Profile', 'article-import-wizard'); ?></th>
                    <td>
                        <?php if ((string) $preview_data['final_author_bio'] !== '') : ?>
                            <?php echo esc_html((string) $preview_data['final_author_bio']); ?>
                        <?php else : ?>
                            <em><?php esc_html_e('No bio provided.', 'article-import-wizard'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Validation', 'article-import-wizard'); ?></h3>
            <?php
            $invalid_links = isset($preview_data['validation_report']['invalid_links']) && is_array($preview_data['validation_report']['invalid_links'])
                ? $preview_data['validation_report']['invalid_links']
                : [];
            $missing_slots = isset($preview_data['validation_report']['missing_image_slots']) && is_array($preview_data['validation_report']['missing_image_slots'])
                ? $preview_data['validation_report']['missing_image_slots']
                : [];
            $metadata_warnings = isset($preview_data['validation_report']['metadata_warnings']) && is_array($preview_data['validation_report']['metadata_warnings'])
                ? $preview_data['validation_report']['metadata_warnings']
                : [];
            ?>
            <p>
                <strong><?php esc_html_e('Invalid Links:', 'article-import-wizard'); ?></strong>
                <?php echo esc_html((string) count($invalid_links)); ?>
            </p>
            <?php if (!empty($invalid_links)) : ?>
                <ul class="aiw-list">
                    <?php foreach ($invalid_links as $invalid_link) : ?>
                        <?php if (isset($invalid_link['url'])) : ?>
                            <li>
                                <?php echo esc_html((string) $invalid_link['url']); ?>
                                <?php if (isset($invalid_link['reason'])) : ?>
                                    (<?php echo esc_html((string) $invalid_link['reason']); ?>)
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>
                <strong><?php esc_html_e('Missing Image Slots:', 'article-import-wizard'); ?></strong>
                <?php echo !empty($missing_slots) ? esc_html(implode(', ', array_map('strval', $missing_slots))) : esc_html__('None', 'article-import-wizard'); ?>
            </p>

            <p>
                <strong><?php esc_html_e('Metadata Warnings:', 'article-import-wizard'); ?></strong>
                <?php echo !empty($metadata_warnings) ? esc_html(implode(' | ', array_map('strval', $metadata_warnings))) : esc_html__('None', 'article-import-wizard'); ?>
            </p>

            <h3><?php esc_html_e('Generated Content Preview', 'article-import-wizard'); ?></h3>
            <div class="aiw-preview-content">
                <?php echo wp_kses_post(do_blocks((string) $preview_data['working_content'])); ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('aiw_create_draft_nonce'); ?>
                <input type="hidden" name="action" value="aiw_create_draft" />
                <input type="hidden" name="aiw_preview_token" value="<?php echo esc_attr($preview_token); ?>" />
                <?php submit_button(__('Create Draft From Preview', 'article-import-wizard'), 'primary', 'submit', false); ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=aiw-import-wizard')); ?>"><?php esc_html_e('Start New Import', 'article-import-wizard'); ?></a>
            </form>
        </div>
    <?php else : ?>
        <div class="aiw-card">
            <h2><?php esc_html_e('Guided Article Import', 'article-import-wizard'); ?></h2>
            <p><?php esc_html_e('Upload a DOCX file or provide content manually. Public attribution always uses the membership number user account.', 'article-import-wizard'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('aiw_preview_import_nonce'); ?>
                <input type="hidden" name="action" value="aiw_preview_import" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aiw_restriction_start"><?php esc_html_e('Restriction Start Shortcode', 'article-import-wizard'); ?></label></th>
                        <td>
                            <input id="aiw_restriction_start" name="aiw_restriction_start" type="text" class="regular-text" value="<?php echo esc_attr((string) $default_restriction_start); ?>" />
                            <p class="description"><?php esc_html_e('Inserted at the [RESTRICT] anchor inside the article.', 'article-import-wizard'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiw_restriction_end"><?php esc_html_e('Restriction End Shortcode', 'article-import-wizard'); ?></label></th>
                        <td>
                            <input id="aiw_restriction_end" name="aiw_restriction_end" type="text" class="regular-text" value="<?php echo esc_attr((string) $default_restriction_end); ?>" />
                            <p class="description"><?php esc_html_e('Inserted after the article body and before the author bio.', 'article-import-wizard'); ?></p>
                        </td>
                    </tr>
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
                        <th scope="row"><?php esc_html_e('Inline Pictures', 'article-import-wizard'); ?></th>
                        <td>
                            <div id="aiw-inline-image-inputs"></div>
                            <p class="description"><?php esc_html_e('One upload field is generated for each selected picture slot.', 'article-import-wizard'); ?></p>
                        </td>
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
                            <p class="description"><?php esc_html_e('Restriction shortcodes come from plugin settings and DOCX markers.', 'article-import-wizard'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Generate Preview', 'article-import-wizard')); ?>
            </form>

            <script>
                (function () {
                    var countInput = document.getElementById('aiw_inline_image_count');
                    var container = document.getElementById('aiw-inline-image-inputs');

                    if (!countInput || !container) {
                        return;
                    }

                    function renderInlineImageInputs() {
                        var count = parseInt(countInput.value || '0', 10);
                        if (isNaN(count) || count < 0) {
                            count = 0;
                        }
                        if (count > 20) {
                            count = 20;
                        }

                        container.innerHTML = '';

                        for (var i = 1; i <= count; i++) {
                            var wrapper = document.createElement('div');
                            wrapper.className = 'aiw-inline-upload-row';

                            var label = document.createElement('label');
                            label.setAttribute('for', 'aiw_inline_image_' + i);
                            label.textContent = '<?php echo esc_js(__('Picture Slot', 'article-import-wizard')); ?> ' + i;

                            var input = document.createElement('input');
                            input.type = 'file';
                            input.accept = 'image/*';
                            input.id = 'aiw_inline_image_' + i;
                            input.name = 'aiw_inline_image_' + i;

                            wrapper.appendChild(label);
                            wrapper.appendChild(input);
                            container.appendChild(wrapper);
                        }
                    }

                    countInput.addEventListener('input', renderInlineImageInputs);
                    countInput.addEventListener('change', renderInlineImageInputs);
                    renderInlineImageInputs();
                })();
            </script>
        </div>
    <?php endif; ?>
</div>
