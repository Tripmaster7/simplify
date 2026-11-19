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
                        <?php esc_html_e('Open Article', 'article-import-wizard'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=aiw-import-wizard')); ?>">
                        <?php esc_html_e('Import Another Article', 'article-import-wizard'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($preview_data)) : ?>
        <div class="aiw-card">
            <h2><?php esc_html_e('Preview Before Draft Creation', 'article-import-wizard'); ?></h2>
            <p><?php esc_html_e('Review this generated article. If everything looks good, create the draft.', 'article-import-wizard'); ?></p>

            <?php
            $update_post_id = isset($preview_data['update_post_id']) ? (int) $preview_data['update_post_id'] : 0;
            $update_post_status = isset($preview_data['update_post_status']) ? (string) $preview_data['update_post_status'] : '';
            $update_is_live = in_array($update_post_status, ['publish', 'private'], true);
            $reused_image_slots = isset($preview_data['reused_image_slots']) && is_array($preview_data['reused_image_slots']) ? $preview_data['reused_image_slots'] : [];
            ?>
            <?php if ($update_post_id > 0) : ?>
                <div class="notice inline <?php echo $update_is_live ? 'notice-error' : 'notice-warning'; ?>">
                    <p>
                        <strong><?php esc_html_e('This upload updates an existing article.', 'article-import-wizard'); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: post ID, 2: post status label */
                            esc_html__('Article #%1$d (%2$s):', 'article-import-wizard'),
                            $update_post_id,
                            esc_html((string) (get_post_status_object($update_post_status)->label ?? $update_post_status))
                        );
                        ?>
                        <a href="<?php echo esc_url((string) get_edit_post_link($update_post_id, '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $preview_data['post_title']); ?></a>
                    </p>
                    <p><?php esc_html_e('The text, pictures, categories and bio are replaced by this upload. Title, author and status stay unchanged.', 'article-import-wizard'); ?></p>
                    <?php if (!empty($reused_image_slots)) : ?>
                        <p>
                            <?php
                            printf(
                                /* translators: %s: comma-separated picture slot numbers */
                                esc_html__('Picture slots without a new upload keep their previous picture: %s', 'article-import-wizard'),
                                esc_html(implode(', ', array_map('intval', $reused_image_slots)))
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Protected Section Start', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['restriction_start']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Protected Section End', 'article-import-wizard'); ?></th>
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
                    <th scope="row"><?php esc_html_e('Categories', 'article-import-wizard'); ?></th>
                    <td>
                        <?php
                        $preview_category_names = isset($preview_data['category_names']) && is_array($preview_data['category_names'])
                            ? $preview_data['category_names']
                            : [];
                        ?>
                        <?php echo !empty($preview_category_names) ? esc_html(implode(', ', array_map('strval', $preview_category_names))) : esc_html__('None', 'article-import-wizard'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Title', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['post_title']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Travel Date', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html(isset($preview_data['travel_date']) ? (string) $preview_data['travel_date'] : ''); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post Date', 'article-import-wizard'); ?></th>
                    <td>
                        <?php if (!empty($preview_data['post_date'])) : ?>
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $preview_data['post_date']))); ?>
                            <span class="description">(<?php esc_html_e('from [WRITING_DATE]', 'article-import-wizard'); ?>)</span>
                        <?php else : ?>
                            <?php esc_html_e('Not set. The publish date will be used.', 'article-import-wizard'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Source Document', 'article-import-wizard'); ?></th>
                    <td><?php echo esc_html((string) $preview_data['source_filename']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Author Bio for Profile', 'article-import-wizard'); ?></th>
                    <td>
                        <?php if ((string) $preview_data['final_author_bio'] !== '') : ?>
                            <?php echo nl2br(esc_html((string) $preview_data['final_author_bio'])); ?>
                        <?php else : ?>
                            <em><?php esc_html_e('No bio provided.', 'article-import-wizard'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Problems to Check', 'article-import-wizard'); ?></h3>
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
                <?php if ($update_post_id > 0) : ?>
                    <input type="hidden" name="aiw_confirm_update_post_id" value="<?php echo esc_attr((string) $update_post_id); ?>" />
                    <p>
                        <label>
                            <input type="checkbox" name="aiw_create_new" value="1" id="aiw_create_new" />
                            <?php esc_html_e('Create as a new article instead (the existing article stays unchanged)', 'article-import-wizard'); ?>
                        </label>
                    </p>
                    <?php if (current_user_can('publish_posts')) : ?>
                        <div id="aiw_new_article_status" class="aiw-new-article-status" hidden>
                            <p>
                                <label for="aiw_import_status"><strong><?php esc_html_e('After review', 'article-import-wizard'); ?></strong></label>
                                <select id="aiw_import_status" name="aiw_import_status">
                                    <option value="draft"<?php selected($default_post_status, 'draft'); ?>><?php esc_html_e('Save as draft', 'article-import-wizard'); ?></option>
                                    <option value="publish"<?php selected($default_post_status, 'publish'); ?>><?php esc_html_e('Publish immediately', 'article-import-wizard'); ?></option>
                                </select>
                            </p>
                            <p class="description"><?php esc_html_e('Publishing will make the article publicly visible as soon as you confirm.', 'article-import-wizard'); ?></p>
                        </div>
                    <?php else : ?>
                        <input type="hidden" name="aiw_import_status" value="draft" />
                    <?php endif; ?>
                    <?php submit_button($update_is_live ? __('Update Published Article', 'article-import-wizard') : __('Update Article', 'article-import-wizard'), 'primary', 'submit', false, ['id' => 'aiw_confirm_button']); ?>
                    <script>
                        (function () {
                            var checkbox = document.getElementById('aiw_create_new');
                            var button = document.getElementById('aiw_confirm_button');
                            var form = button ? button.closest('form') : null;
                            var statusBox = document.getElementById('aiw_new_article_status');
                            if (!checkbox || !button || !form) {
                                return;
                            }
                            var updateLabel = button.value;
                            checkbox.addEventListener('change', function () {
                                button.value = checkbox.checked
                                    ? '<?php echo esc_js(__('Create Draft From Preview', 'article-import-wizard')); ?>'
                                    : updateLabel;
                                if (statusBox) {
                                    statusBox.hidden = !checkbox.checked;
                                }
                            });
                            <?php if ($update_is_live) : ?>
                            form.addEventListener('submit', function (event) {
                                if (!checkbox.checked && !window.confirm('<?php echo esc_js(__('This article is published. Confirming replaces its live text, pictures, categories and author bio. Continue?', 'article-import-wizard')); ?>')) {
                                    event.preventDefault();
                                }
                            });
                            <?php endif; ?>
                            form.addEventListener('submit', function (event) {
                                if (checkbox.checked) {
                                    var statusSelect = document.getElementById('aiw_import_status');
                                    if (statusSelect && statusSelect.value === 'publish' && !window.confirm('<?php echo esc_js(__('The new article will be published publicly immediately. Continue?', 'article-import-wizard')); ?>')) {
                                        event.preventDefault();
                                    }
                                }
                            });
                        })();
                    </script>
                <?php else : ?>
                    <?php if (current_user_can('publish_posts')) : ?>
                        <p>
                            <label for="aiw_import_status"><strong><?php esc_html_e('After review', 'article-import-wizard'); ?></strong></label>
                            <select id="aiw_import_status" name="aiw_import_status">
                                <option value="draft"<?php selected($default_post_status, 'draft'); ?>><?php esc_html_e('Save as draft', 'article-import-wizard'); ?></option>
                                <option value="publish"<?php selected($default_post_status, 'publish'); ?>><?php esc_html_e('Publish immediately', 'article-import-wizard'); ?></option>
                            </select>
                        </p>
                        <p class="description"><?php esc_html_e('Publishing will make the article publicly visible as soon as you confirm.', 'article-import-wizard'); ?></p>
                    <?php else : ?>
                        <input type="hidden" name="aiw_import_status" value="draft" />
                        <p><?php esc_html_e('This article will be saved as a draft. An editor with publishing permission can publish it later.', 'article-import-wizard'); ?></p>
                    <?php endif; ?>
                    <?php submit_button(__('Complete Import', 'article-import-wizard'), 'primary', 'submit', false); ?>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=aiw-import-wizard')); ?>"><?php esc_html_e('Start New Import', 'article-import-wizard'); ?></a>
            </form>
        </div>
    <?php else : ?>
        <div class="aiw-card">
            <h2><?php esc_html_e('Guided Article Import', 'article-import-wizard'); ?></h2>
            <p><?php esc_html_e('Prepare one Word document and add the article, pictures and author number below. Public attribution always uses the member account.', 'article-import-wizard'); ?></p>

            <div class="aiw-start-checklist" role="note">
                <strong><?php esc_html_e('Before you start', 'article-import-wizard'); ?></strong>
                <ol>
                    <li><?php esc_html_e('Save the article as a DOCX file in Word.', 'article-import-wizard'); ?></li>
                    <li><?php esc_html_e('Know the author’s name or member number.', 'article-import-wizard'); ?></li>
                    <li><?php esc_html_e('Prepare the headline picture and any pictures used inside the article.', 'article-import-wizard'); ?></li>
                </ol>
                <a href="<?php echo esc_url((string) $help_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Open the short preparation guide', 'article-import-wizard'); ?>
                </a>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('aiw_preview_import_nonce'); ?>
                <input type="hidden" name="action" value="aiw_preview_import" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aiw_membership_number"><?php esc_html_e('Author or Membership Number', 'article-import-wizard'); ?></label></th>
                        <td>
                            <input id="aiw_membership_number" name="aiw_membership_number" type="text" class="regular-text" aria-describedby="aiw-membership-help aiw-membership-status" />
                            <p id="aiw-membership-help" class="description"><?php esc_html_e('Enter the author name or membership number.', 'article-import-wizard'); ?></p>
                            <p id="aiw-membership-status" class="aiw-membership-status" aria-live="polite"></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiw_category_ids"><?php esc_html_e('Categories', 'article-import-wizard'); ?></label></th>
                        <td>
                            <select id="aiw_category_ids" name="aiw_category_ids[]" multiple size="6" required>
                                <?php if (isset($available_categories) && is_array($available_categories)) : ?>
                                    <?php foreach ($available_categories as $category) : ?>
                                        <?php if (isset($category->term_id, $category->name)) : ?>
                                            <option value="<?php echo esc_attr((string) $category->term_id); ?>"<?php selected(in_array((int) $category->term_id, $default_category_ids, true)); ?>><?php echo esc_html((string) $category->name); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (!empty($can_create_categories)) : ?>
                                <p>
                                    <button type="button" class="button" id="aiw-add-category-button"><?php esc_html_e('New Category', 'article-import-wizard'); ?></button>
                                </p>
                                <div id="aiw-new-category-inputs"></div>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Select at least one category. Multiple categories are allowed.', 'article-import-wizard'); ?></p>
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
                        <th scope="row"><label for="aiw_travel_date"><?php esc_html_e('Travel Date', 'article-import-wizard'); ?></label></th>
                        <td>
                            <input id="aiw_travel_date" name="aiw_travel_date" type="date" />
                            <p class="description"><?php esc_html_e('Optionally select the date of the travel.', 'article-import-wizard'); ?></p>
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
                    var addCategoryButton = document.getElementById('aiw-add-category-button');
                    var categoriesSelect = document.getElementById('aiw_category_ids');
                    var newCategoryInputs = document.getElementById('aiw-new-category-inputs');
                    var newCategoryCounter = 0;

                    if (!countInput || !container) {
                        return;
                    }

                    // Upload rows are created once and never removed, so files that were already chosen
                    // survive any change of the count (including the moment the field is empty while
                    // typing a new number). Rows above the count are hidden and disabled; disabled file
                    // inputs are not submitted.
                    function renderInlineImageInputs() {
                        var count = parseInt(countInput.value || '0', 10);
                        if (isNaN(count) || count < 0) {
                            count = 0;
                        }
                        if (count > 20) {
                            count = 20;
                        }

                        for (var i = container.children.length + 1; i <= count; i++) {
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

                        for (var slot = 1; slot <= container.children.length; slot++) {
                            var row = container.children[slot - 1];
                            var active = slot <= count;
                            row.hidden = !active;
                            row.querySelector('input').disabled = !active;
                        }
                    }

                    countInput.addEventListener('input', renderInlineImageInputs);
                    countInput.addEventListener('change', renderInlineImageInputs);
                    renderInlineImageInputs();

                    if (addCategoryButton && categoriesSelect && newCategoryInputs) {
                        addCategoryButton.addEventListener('click', function () {
                            var categoryName = window.prompt('<?php echo esc_js(__('Enter new category name', 'article-import-wizard')); ?>');
                            if (!categoryName) {
                                return;
                            }

                            categoryName = categoryName.trim();
                            if (!categoryName) {
                                return;
                            }

                            for (var i = 0; i < categoriesSelect.options.length; i++) {
                                if ((categoriesSelect.options[i].textContent || '').toLowerCase() === categoryName.toLowerCase()) {
                                    categoriesSelect.options[i].selected = true;
                                    return;
                                }
                            }

                            newCategoryCounter += 1;

                            var option = document.createElement('option');
                            option.value = '__new__' + newCategoryCounter;
                            option.textContent = categoryName + ' (' + AIWAdmin.categoryNewLabel + ')';
                            option.selected = true;
                            categoriesSelect.appendChild(option);

                            var hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'aiw_new_category_names[]';
                            hidden.value = categoryName;
                            newCategoryInputs.appendChild(hidden);
                        });
                    }
                })();
            </script>
        </div>
    <?php endif; ?>
</div>
