<?php
/**
 * Plugin Name: HTML to WordPress Page
 * Description: Create standalone HTML pages without WordPress theme header/footer. Perfect for uploading AI-generated HTML.
 * Version: 2.3.0
 * Author: Cuadro Srl
 * Author URI: https://cuadrostudio.com
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class HTML_To_WordPress_Page {

    const META_KEY_CONTENT = '_html_page_content';
    const META_KEY_ENABLED = '_html_page_enabled';
    const META_KEY_LEGACY = '_html_page_legacy';

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Admin menu (old style)
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Render HTML pages
        add_action('template_redirect', array($this, 'render_html_page'), 1);

        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        // Add column to pages list (new feature)
        add_filter('manage_pages_columns', array($this, 'add_pages_column'));
        add_action('manage_pages_custom_column', array($this, 'render_pages_column'), 10, 2);

        // Handle downloads
        add_action('admin_init', array($this, 'handle_download'));

        // Run migration on update (check version)
        add_action('admin_init', array($this, 'check_migration'));

        // Keep /html/ URLs working for backward compatibility
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }

    /**
     * Plugin activation - migrate existing pages from old table
     */
    public function activate() {
        $this->migrate_existing_pages();
        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Check if migration needs to run (for plugin updates)
     */
    public function check_migration() {
        $current_version = '2.3.0';
        $installed_version = get_option('html_to_wp_page_version', '0');

        if (version_compare($installed_version, $current_version, '<')) {
            $this->migrate_existing_pages();
            update_option('html_to_wp_page_version', $current_version);
            flush_rewrite_rules();
        }
    }

    /**
     * Register rewrite rules for /html/ URLs (backward compatibility)
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^html/([^/]+)/?$',
            'index.php?html_page_slug=$matches[1]',
            'top'
        );
    }

    /**
     * Add query vars for rewrite rules
     */
    public function add_query_vars($vars) {
        $vars[] = 'html_page_slug';
        return $vars;
    }

    /**
     * Get the correct URL for an HTML page
     * Legacy pages use /html/slug/, new pages use native WordPress URL
     */
    private function get_page_url($post) {
        $is_legacy = get_post_meta($post->ID, self::META_KEY_LEGACY, true);
        if ($is_legacy === '1') {
            return home_url('/html/' . $post->post_name . '/');
        }
        return get_permalink($post->ID);
    }

    /**
     * Generate unique slug by adding integer suffix if needed
     */
    private function generate_unique_slug($slug, $exclude_post_id = 0) {
        $original_slug = $slug;
        $counter = 1;

        while (true) {
            $existing = get_page_by_path($slug);

            if (!$existing || ($exclude_post_id && $existing->ID == $exclude_post_id)) {
                return $slug;
            }

            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
    }

    /**
     * Migrate pages from old custom table to WordPress pages
     */
    private function migrate_existing_pages() {
        global $wpdb;

        $old_table = $wpdb->prefix . 'html_pages';

        // Check if old table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$old_table}'") === $old_table;

        if (!$table_exists) {
            return;
        }

        // Get all rows from old table
        $old_pages = $wpdb->get_results("SELECT * FROM {$old_table}");

        if (empty($old_pages)) {
            return;
        }

        $migrated = 0;
        $skipped = 0;

        foreach ($old_pages as $old_page) {
            // Check if a page with this slug already exists
            $existing = get_page_by_path($old_page->slug);

            if ($existing) {
                // Check if it already has our meta
                $has_meta = get_post_meta($existing->ID, self::META_KEY_CONTENT, true);
                if ($has_meta) {
                    $skipped++;
                    continue;
                }

                // Update existing page with meta
                update_post_meta($existing->ID, self::META_KEY_CONTENT, $old_page->html_content);
                update_post_meta($existing->ID, self::META_KEY_ENABLED, '1');
                update_post_meta($existing->ID, self::META_KEY_LEGACY, '1');
                $migrated++;
            } else {
                // Generate unique slug
                $unique_slug = $this->generate_unique_slug($old_page->slug);

                // Create new WordPress page
                $page_id = wp_insert_post(array(
                    'post_title'   => $old_page->title,
                    'post_name'    => $unique_slug,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '',
                ));

                if ($page_id && !is_wp_error($page_id)) {
                    update_post_meta($page_id, self::META_KEY_CONTENT, $old_page->html_content);
                    update_post_meta($page_id, self::META_KEY_ENABLED, '1');
                    update_post_meta($page_id, self::META_KEY_LEGACY, '1');
                    $migrated++;
                }
            }
        }

        // Store migration results for admin notice
        if ($migrated > 0) {
            set_transient('html_page_migration_notice', array(
                'migrated' => $migrated,
                'skipped'  => $skipped,
            ), 60);
        }
    }

    /**
     * Show migration notice
     */
    public function show_migration_notice() {
        $notice = get_transient('html_page_migration_notice');
        if ($notice) {
            delete_transient('html_page_migration_notice');
            $message = sprintf(
                'HTML to WordPress Page: Migrated %d page(s) from old table.',
                $notice['migrated']
            );
            if ($notice['skipped'] > 0) {
                $message .= sprintf(' Skipped %d already migrated.', $notice['skipped']);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Add admin menu (old style)
     */
    public function add_admin_menu() {
        add_menu_page(
            'HTML Pages',
            'HTML Pages',
            'manage_options',
            'html-to-wp-page',
            array($this, 'admin_page_list'),
            'dashicons-media-code',
            30
        );

        add_submenu_page(
            'html-to-wp-page',
            'All HTML Pages',
            'All Pages',
            'manage_options',
            'html-to-wp-page',
            array($this, 'admin_page_list')
        );

        add_submenu_page(
            'html-to-wp-page',
            'Add New HTML Page',
            'Add New',
            'manage_options',
            'html-to-wp-page-new',
            array($this, 'admin_page_edit')
        );

        // Show migration notice
        add_action('admin_notices', array($this, 'show_migration_notice'));
    }

    /**
     * Admin page - list all HTML pages
     */
    public function admin_page_list() {
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_html_page_' . $_GET['id'])) {
                $post_id = intval($_GET['id']);
                // Remove meta and optionally trash the page
                delete_post_meta($post_id, self::META_KEY_CONTENT);
                delete_post_meta($post_id, self::META_KEY_ENABLED);
                wp_trash_post($post_id);
                echo '<div class="notice notice-success"><p>Page deleted successfully.</p></div>';
            }
        }

        // Get all pages with HTML content enabled
        $args = array(
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => self::META_KEY_ENABLED,
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $pages = get_posts($args);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">HTML Pages</h1>
            <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new'); ?>" class="page-title-action">Add New</a>
            <?php if (!empty($pages)): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=download_all'), 'download_all_html_pages'); ?>" class="page-title-action">Download All</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 25%;">Title</th>
                        <th style="width: 20%;">Slug</th>
                        <th style="width: 25%;">URL</th>
                        <th style="width: 10%;">Created</th>
                        <th style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pages)): ?>
                        <tr>
                            <td colspan="5">No HTML pages found. <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new'); ?>">Create one</a></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pages as $page): ?>
                            <tr>
                                <td><strong><?php echo esc_html($page->post_title); ?></strong></td>
                                <td><code><?php echo esc_html($page->post_name); ?></code></td>
                                <td>
                                    <a href="<?php echo esc_url($this->get_page_url($page)); ?>" target="_blank">
                                        <?php echo esc_html($this->get_page_url($page)); ?>
                                    </a>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($page->post_date)); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new&id=' . $page->ID); ?>">Edit</a> |
                                    <a href="<?php echo get_edit_post_link($page->ID); ?>">WP Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=download&id=' . $page->ID), 'download_html_page_' . $page->ID); ?>">Download</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=delete&id=' . $page->ID), 'delete_html_page_' . $page->ID); ?>"
                                       onclick="return confirm('Are you sure you want to delete this page?');"
                                       style="color: #a00;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Admin page - add/edit HTML page
     */
    public function admin_page_edit() {
        $page_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $page = null;
        $message = '';
        $error = '';

        // Show message from redirect
        if (isset($_GET['msg'])) {
            if ($_GET['msg'] === 'created') {
                $message = 'created';
            } elseif ($_GET['msg'] === 'updated') {
                $message = 'Page updated successfully.';
            }
        }

        // Load existing page for editing
        if ($page_id) {
            $page = get_post($page_id);
            if (!$page || $page->post_type !== 'page') {
                $page = null;
                $page_id = 0;
            }
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['html_wp_nonce'])) {
            if (wp_verify_nonce($_POST['html_wp_nonce'], 'html_wp_save')) {
                $title = sanitize_text_field(wp_unslash($_POST['title']));
                $slug = sanitize_title(wp_unslash($_POST['slug']));
                $html_content = wp_unslash($_POST['html_content']);

                if (empty($title) || empty($slug) || empty($html_content)) {
                    $error = 'Please fill in all fields.';
                } else {
                    // Generate unique slug
                    $unique_slug = $this->generate_unique_slug($slug, $page_id);

                    if ($page_id) {
                        // Update existing page
                        wp_update_post(array(
                            'ID'         => $page_id,
                            'post_title' => $title,
                            'post_name'  => $unique_slug,
                        ));
                        update_post_meta($page_id, self::META_KEY_CONTENT, $html_content);
                        update_post_meta($page_id, self::META_KEY_ENABLED, '1');

                        // Bust caches for this page
                        clean_post_cache($page_id);
                        if (function_exists('wp_cache_flush_group')) {
                            wp_cache_flush_group('posts');
                        }

                        // Redirect to prevent duplicate submissions and ensure URL has ID
                        wp_redirect(admin_url('admin.php?page=html-to-wp-page-new&id=' . $page_id . '&msg=updated'));
                        exit;
                    } else {
                        // Create new WordPress page
                        $new_page_id = wp_insert_post(array(
                            'post_title'   => $title,
                            'post_name'    => $unique_slug,
                            'post_status'  => 'publish',
                            'post_type'    => 'page',
                            'post_content' => '',
                        ));

                        if ($new_page_id && !is_wp_error($new_page_id)) {
                            update_post_meta($new_page_id, self::META_KEY_CONTENT, $html_content);
                            update_post_meta($new_page_id, self::META_KEY_ENABLED, '1');

                            // Redirect to edit URL with ID so subsequent saves update correctly
                            wp_redirect(admin_url('admin.php?page=html-to-wp-page-new&id=' . $new_page_id . '&msg=created'));
                            exit;
                        } else {
                            $error = 'Failed to create page.';
                        }
                    }
                }
            }
        }

        // Get meta values for display
        $html_content = $page ? get_post_meta($page->ID, self::META_KEY_CONTENT, true) : '';

        ?>
        <div class="wrap">
            <h1><?php echo $page_id ? 'Edit HTML Page' : 'Add New HTML Page'; ?></h1>

            <?php if ($message === 'created' && $page): ?>
                <div class="notice notice-success">
                    <p>Page created successfully. URL: <a href="<?php echo esc_url($this->get_page_url($page)); ?>" target="_blank"><?php echo esc_html($this->get_page_url($page)); ?></a></p>
                </div>
            <?php elseif ($message): ?>
                <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if ($page): ?>
                <div class="html-wp-page-url">
                    <strong>Page URL:</strong>
                    <a href="<?php echo esc_url($this->get_page_url($page)); ?>" target="_blank">
                        <?php echo esc_html($this->get_page_url($page)); ?>
                    </a>
                </div>
            <?php endif; ?>

            <form method="post" id="html-wp-form">
                <?php wp_nonce_field('html_wp_save', 'html_wp_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="title">Title</label></th>
                        <td>
                            <input type="text" id="title" name="title" class="regular-text"
                                   value="<?php echo $page ? esc_attr($page->post_title) : ''; ?>" required>
                            <p class="description">Internal name for this page</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="slug">Slug</label></th>
                        <td>
                            <input type="text" id="slug" name="slug" class="regular-text"
                                   value="<?php echo $page ? esc_attr($page->post_name) : ''; ?>" required>
                            <?php
                            $is_legacy = $page ? get_post_meta($page->ID, self::META_KEY_LEGACY, true) : '';
                            $url_base = ($is_legacy === '1') ? home_url('/html/') : home_url('/');
                            ?>
                            <p class="description">URL will be: <?php echo $url_base; ?><span id="slug-preview"><?php echo $page ? esc_html($page->post_name) : 'your-slug'; ?></span>/</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Import HTML File</label></th>
                        <td>
                            <input type="file" id="html-file-input" accept=".html,.htm">
                            <p class="description">Upload an HTML file to populate the content below</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="html_content">HTML Content</label></th>
                        <td>
                            <textarea id="html_content" name="html_content" rows="25" class="large-text code" required><?php echo esc_textarea($html_content); ?></textarea>
                            <p class="description">Paste your complete HTML code here (including &lt;!DOCTYPE html&gt;, CSS, and JavaScript)</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php echo $page_id ? 'Update Page' : 'Create Page'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=html-to-wp-page'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle file downloads
     */
    public function handle_download() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'html-to-wp-page') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Download single file
        if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['id'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'download_html_page_' . $_GET['id'])) {
                return;
            }

            $post_id = intval($_GET['id']);
            $page = get_post($post_id);

            if (!$page) {
                return;
            }

            $html_content = get_post_meta($post_id, self::META_KEY_CONTENT, true);
            $filename = sanitize_file_name($page->post_name) . '.html';

            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($html_content));
            echo $html_content;
            exit;
        }

        // Download all files as zip
        if (isset($_GET['action']) && $_GET['action'] === 'download_all') {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'download_all_html_pages')) {
                return;
            }

            $args = array(
                'post_type'      => 'page',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => self::META_KEY_ENABLED,
                        'value'   => '1',
                        'compare' => '=',
                    ),
                ),
            );

            $pages = get_posts($args);

            if (empty($pages)) {
                return;
            }

            $zip = new ZipArchive();
            $zip_filename = sys_get_temp_dir() . '/html-pages-' . time() . '.zip';

            if ($zip->open($zip_filename, ZipArchive::CREATE) !== true) {
                return;
            }

            foreach ($pages as $page) {
                $html_content = get_post_meta($page->ID, self::META_KEY_CONTENT, true);
                $filename = sanitize_file_name($page->post_name) . '.html';
                $zip->addFromString($filename, $html_content);
            }

            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="html-pages.zip"');
            header('Content-Length: ' . filesize($zip_filename));
            readfile($zip_filename);
            unlink($zip_filename);
            exit;
        }
    }

    /**
     * Render HTML page if enabled
     */
    public function render_html_page() {
        // Handle /html/slug/ URLs (backward compatibility)
        $html_slug = get_query_var('html_page_slug');
        if (!empty($html_slug)) {
            $page = get_page_by_path($html_slug);
            if ($page) {
                $enabled = get_post_meta($page->ID, self::META_KEY_ENABLED, true);
                $html_content = get_post_meta($page->ID, self::META_KEY_CONTENT, true);

                if ($enabled === '1' && !empty($html_content)) {
                    echo $html_content;
                    exit;
                }
            }

            // Page not found
            status_header(404);
            echo '<!DOCTYPE html><html><head><title>Page Not Found</title></head><body><h1>404 - Page Not Found</h1></body></html>';
            exit;
        }

        // Handle native WordPress page URLs
        if (!is_page()) {
            return;
        }

        $post_id = get_the_ID();

        if (!$post_id) {
            return;
        }

        $enabled = get_post_meta($post_id, self::META_KEY_ENABLED, true);
        $html_content = get_post_meta($post_id, self::META_KEY_CONTENT, true);

        if ($enabled === '1' && !empty($html_content)) {
            // Output raw HTML and exit
            echo $html_content;
            exit;
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts($hook) {
        // Load CSS on pages list for column styling
        if ($hook === 'edit.php') {
            $screen = get_current_screen();
            if ($screen && $screen->post_type === 'page') {
                wp_enqueue_style(
                    'html-to-wp-page-admin',
                    plugin_dir_url(__FILE__) . 'admin-style.css',
                    array(),
                    '2.3.0'
                );
                return;
            }
        }

        // Load full assets on our custom admin pages
        if (strpos($hook, 'html-to-wp-page') === false) {
            return;
        }

        wp_enqueue_style(
            'html-to-wp-page-admin',
            plugin_dir_url(__FILE__) . 'admin-style.css',
            array(),
            '2.3.0'
        );

        wp_enqueue_script(
            'html-to-wp-page-admin',
            plugin_dir_url(__FILE__) . 'admin-script.js',
            array('jquery'),
            '2.3.0',
            true
        );
    }

    /**
     * Add "HTML" column to pages list (at the end)
     */
    public function add_pages_column($columns) {
        $columns['html_page'] = 'HTML';
        return $columns;
    }

    /**
     * Render the HTML Page column content
     */
    public function render_pages_column($column, $post_id) {
        if ($column === 'html_page') {
            $enabled = get_post_meta($post_id, self::META_KEY_ENABLED, true);
            if ($enabled === '1') {
                echo '<span style="color: #00a32a;">Yes</span>';
            } else {
                echo '<span style="color: #999;">-</span>';
            }
        }
    }
}

/**
 * GitHub-based auto-updater for the plugin
 */
class HTML_To_WP_Page_Updater {

    private $slug;
    private $plugin_file;
    private $github_repo;
    private $plugin_data;
    private $github_response;

    public function __construct($plugin_file, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->slug = plugin_basename($plugin_file);
        $this->github_repo = $github_repo;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    private function get_plugin_data() {
        if (!$this->plugin_data) {
            $this->plugin_data = get_plugin_data($this->plugin_file);
        }
        return $this->plugin_data;
    }

    private function get_github_release() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = false;
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body) || !isset($body->tag_name)) {
            $this->github_response = false;
            return false;
        }

        $this->github_response = $body;
        return $body;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $transient;
        }

        $plugin_data = $this->get_plugin_data();
        $remote_version = ltrim($release->tag_name, 'v');
        $current_version = $plugin_data['Version'];

        if (version_compare($remote_version, $current_version, '>')) {
            // Find zip asset or use zipball
            $download_url = $release->zipball_url;
            if (!empty($release->assets)) {
                foreach ($release->assets as $asset) {
                    if (substr($asset->name, -4) === '.zip') {
                        $download_url = $asset->browser_download_url;
                        break;
                    }
                }
            }

            $transient->response[$this->slug] = (object) array(
                'slug'        => dirname($this->slug),
                'plugin'      => $this->slug,
                'new_version' => $remote_version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => $download_url,
            );
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->slug)) {
            return $result;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $result;
        }

        $plugin_data = $this->get_plugin_data();

        $result = (object) array(
            'name'              => $plugin_data['Name'],
            'slug'              => dirname($this->slug),
            'version'           => ltrim($release->tag_name, 'v'),
            'author'            => $plugin_data['AuthorName'],
            'homepage'          => $plugin_data['PluginURI'] ?: "https://github.com/{$this->github_repo}",
            'requires'          => '5.0',
            'tested'            => get_bloginfo('version'),
            'downloaded'        => 0,
            'last_updated'      => $release->published_at,
            'sections'          => array(
                'description'   => $plugin_data['Description'],
                'changelog'     => nl2br(esc_html($release->body)),
            ),
            'download_link'     => $release->zipball_url,
        );

        return $result;
    }

    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug) {
            return $result;
        }

        global $wp_filesystem;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $plugin_dir);
        $result['destination'] = $plugin_dir;

        activate_plugin($this->slug);

        return $result;
    }
}

// Initialize the plugin
new HTML_To_WordPress_Page();

// Initialize auto-updater (change repo to your GitHub repo)
new HTML_To_WP_Page_Updater(
    __FILE__,
    'cuadro-codebase/html-to-wordpress-page'
);

// Flush rewrite rules on deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
