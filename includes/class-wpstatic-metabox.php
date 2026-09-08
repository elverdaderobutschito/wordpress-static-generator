<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPStatic_MetaBox {
    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('save_post', [$this, 'saveTemplateSelection']);
    }

    public function register(): void {
        $settings = WPStatic_Settings::getSettings();

        foreach ($settings['post_types'] as $postType) {
            add_meta_box(
                'wpstatic_deploy_single',
                __('Static Deploy', 'wordpress-static-generator'),
                [$this, 'render'],
                $postType,
                'side',
                'high'
            );
        }
    }

    public function render(WP_Post $post): void {
        $settings = WPStatic_Settings::getSettings();

        if (empty($settings['template_path'])) {
            echo '<p>' . sprintf(
                /* translators: %s: "Static Deploy" settings page link text */
                esc_html__('Please set up a template file under %s first.', 'wordpress-static-generator'),
                '<em>' . esc_html__('Static Deploy', 'wordpress-static-generator') . '</em>'
            ) . '</p>';
            return;
        }

        wp_nonce_field('wpstatic_deploy_single_box', 'wpstatic_deploy_single_nonce');
        wp_nonce_field('wpstatic_template_select', 'wpstatic_template_nonce');

        if (!empty($settings['extra_templates'])) {
            $currentTemplateId = get_post_meta($post->ID, '_wpstatic_template_id', true);
            ?>
            <p>
                <label for="wpstatic_template_id"><?php esc_html_e('Template for this page', 'wordpress-static-generator'); ?></label><br>
                <select id="wpstatic_template_id" name="wpstatic_template_id" style="width: 100%;">
                    <option value=""><?php esc_html_e('Default', 'wordpress-static-generator'); ?></option>
                    <?php foreach ($settings['extra_templates'] as $tpl): ?>
                        <option value="<?php echo esc_attr($tpl['id']); ?>" <?php selected($currentTemplateId, $tpl['id']); ?>>
                            <?php echo esc_html($tpl['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('Applied the next time this page is saved.', 'wordpress-static-generator'); ?></span>
            </p>
            <?php
        }
        ?>
        <p>
            <button type="button" class="button button-primary" id="wpstatic-deploy-single-btn"
                    data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
                <?php esc_html_e('Deploy', 'wordpress-static-generator'); ?>
            </button>
        </p>
        <?php if ($settings['target'] === 'netlify'): ?>
            <p class="description">
                <?php esc_html_e('Note: with Netlify as the target, this button triggers a full rebuild and redeploy of the entire site (Netlify cannot update a single page in isolation).', 'wordpress-static-generator'); ?>
            </p>
        <?php endif; ?>
        <div id="wpstatic-deploy-single-status" style="margin-top: 8px; font-size: 12px;"></div>
        <?php
    }

    /**
     * Saves the per-page template selection as post meta - runs through
     * WordPress' normal save process (the "Update"/"Publish" button),
     * NOT through our AJAX deploy button.
     */
    public function saveTemplateSelection(int $postId): void {
        if (!isset($_POST['wpstatic_template_nonce']) || !wp_verify_nonce(wp_unslash($_POST['wpstatic_template_nonce']), 'wpstatic_template_select')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $templateId = sanitize_text_field(wp_unslash($_POST['wpstatic_template_id'] ?? ''));

        if ($templateId === '') {
            delete_post_meta($postId, '_wpstatic_template_id');
        } else {
            update_post_meta($postId, '_wpstatic_template_id', $templateId);
        }
    }

    public function enqueueAssets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_script(
            'wpstatic-deploy-single',
            WPSTATIC_DEPLOY_URL . 'assets/js/single.js',
            ['jquery'],
            WPSTATIC_DEPLOY_VERSION,
            true
        );

        wp_localize_script('wpstatic-deploy-single', 'wpStaticDeploySingle', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpstatic_deploy_ajax'),
            'i18n' => [
                'deploying' => __('Deploying …', 'wordpress-static-generator'),
                'deploy' => __('Deploy', 'wordpress-static-generator'),
                'done' => __('Done.', 'wordpress-static-generator'),
                'error' => __('Error:', 'wordpress-static-generator'),
                'unknownError' => __('Unknown error', 'wordpress-static-generator'),
                'requestError' => __('Error processing the request.', 'wordpress-static-generator'),
            ],
        ]);
    }
}
