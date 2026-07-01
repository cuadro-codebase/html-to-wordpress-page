<?php
/**
 * Plugin Name: HTML to WordPress Page
 * Description: Create standalone HTML pages without WordPress theme header/footer. Perfect for uploading AI-generated HTML.
 * Version: 2.11.0
 * Author: Cuadro Studio
 * Author URI: https://www.cuadrostudio.com
 * License: GPL v2 or later
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class HTML_To_WordPress_Page {

    const META_KEY_CONTENT = '_html_page_content';
    const META_KEY_ENABLED = '_html_page_enabled';
    const META_KEY_LEGACY = '_html_page_legacy';
    const META_KEY_WP_HEAD = '_html_page_wp_head';
    const META_KEY_WP_FOOTER = '_html_page_wp_footer';
    const META_KEY_PINNED = '_html_page_pinned';
    const META_KEY_FOLDER = '_html_page_folder';

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

        // AJAX search for list page
        add_action('wp_ajax_html_page_search', array($this, 'ajax_search_pages'));

        // AJAX import
        add_action('wp_ajax_html_page_import', array($this, 'ajax_import_page'));

        // AJAX toggle pin
        add_action('wp_ajax_html_page_toggle_pin', array($this, 'ajax_toggle_pin'));

        // Folder AJAX handlers
        add_action('wp_ajax_html_page_set_folder', array($this, 'ajax_set_folder'));
        add_action('wp_ajax_html_page_create_folder', array($this, 'ajax_create_folder'));
        add_action('wp_ajax_html_page_rename_folder', array($this, 'ajax_rename_folder'));
        add_action('wp_ajax_html_page_delete_folder', array($this, 'ajax_delete_folder'));
        add_action('wp_ajax_html_page_reorder_folders', array($this, 'ajax_reorder_folders'));

        // Bulk action AJAX handlers
        add_action('wp_ajax_html_page_bulk_delete', array($this, 'ajax_bulk_delete'));
        add_action('wp_ajax_html_page_bulk_pin', array($this, 'ajax_bulk_pin'));
        add_action('wp_ajax_html_page_bulk_set_folder', array($this, 'ajax_bulk_set_folder'));
        add_action('wp_ajax_html_page_bulk_download', array($this, 'ajax_bulk_download'));

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
        $current_version = '2.11.0';
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

        $pages = $this->get_sorted_pages();
        $total_count = count($pages);
        $folder_stats = $this->get_folder_stats();
        $folders = $this->get_all_folders();
        $authors = $this->get_html_authors();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">HTML Pages <span class="title-count html-page-count" aria-hidden="true"><?php echo intval($total_count); ?></span></h1>
            <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new'); ?>" class="page-title-action">Add New</a>
            <button type="button" class="page-title-action" id="html-import-toggle">Import</button>
            <?php if (!empty($pages)): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=download_all'), 'download_all_html_pages'); ?>" class="page-title-action">Download All</a>
            <?php endif; ?>
            <button type="button" class="page-title-action html-filter-toggle" id="html-filter-toggle" aria-expanded="false">
                <span class="html-filter-toggle-icon" aria-hidden="true">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                </span>
                <span>Filter</span>
                <span class="html-filter-badge" id="html-filter-badge" style="display:none;">0</span>
            </button>
            <hr class="wp-header-end">

            <div class="html-filter-panel" id="html-filter-panel" style="display:none;">
                <div class="html-filter-panel-grid">
                    <div class="html-filter-field">
                        <label for="html-filter-date-from">Created after</label>
                        <input type="date" id="html-filter-date-from">
                    </div>
                    <div class="html-filter-field">
                        <label for="html-filter-date-to">Created before</label>
                        <input type="date" id="html-filter-date-to">
                    </div>
                    <div class="html-filter-field">
                        <label for="html-filter-author">Author</label>
                        <select id="html-filter-author">
                            <option value="0">Any author</option>
                            <?php foreach ($authors as $a): ?>
                                <option value="<?php echo intval($a['id']); ?>"><?php echo esc_html($a['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="html-filter-field">
                        <label for="html-filter-pinned">Pinned status</label>
                        <select id="html-filter-pinned">
                            <option value="">Any</option>
                            <option value="pinned">Pinned only</option>
                            <option value="unpinned">Unpinned only</option>
                        </select>
                    </div>
                </div>
                <div class="html-filter-panel-actions">
                    <button type="button" class="button button-primary html-filter-apply" id="html-filter-apply">
                        <span class="html-filter-apply-label">Apply</span>
                        <span class="html-filter-apply-spinner" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="button" id="html-filter-clear">Clear</button>
                </div>
            </div>

            <div id="html-import-panel" style="display:none;">
                <div id="html-import-drop">
                    <div class="html-import-drop-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#8c8f94" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <p class="html-import-drop-text">Drop <code>.html</code> files here</p>
                    <p class="html-import-drop-or">or</p>
                    <label for="html-import-file" class="button button-hero html-import-browse-btn">Select Files</label>
                    <input type="file" id="html-import-file" accept=".html,.htm" multiple style="display:none;">
                    <p class="html-import-drop-hint">Supports multiple files. Title and slug are auto-generated from filenames.</p>
                </div>
                <div id="html-import-queue"></div>
                <div id="html-import-actions" style="display:none;">
                    <button type="button" class="button button-primary button-hero" id="html-import-publish">Publish All</button>
                    <button type="button" class="button button-hero" id="html-import-cancel">Cancel</button>
                </div>
            </div>

            <div id="html-page-list-section">
            <div class="html-page-search-wrap">
                <input type="search" id="html-page-search" placeholder="Search by title or slug..." autocomplete="off">
                <span class="html-page-search-spinner"></span>
                <span class="html-page-search-count"></span>
            </div>

            <div class="html-folder-chips" id="html-folder-chips" data-active="__all">
                <button type="button" class="html-folder-chip is-active" data-folder-key="__all" data-tooltip="Show all pages">
                    <span class="html-folder-chip-label">All</span>
                    <span class="html-folder-chip-count"><?php echo intval($folder_stats['__all']); ?></span>
                </button>
                <button type="button" class="html-folder-chip" data-folder-key="__none" data-tooltip="Pages not in any folder">
                    <span class="html-folder-chip-label">Unfiled</span>
                    <span class="html-folder-chip-count"><?php echo intval($folder_stats['__none']); ?></span>
                </button>
                <?php foreach ($folders as $folder_name): ?>
                    <div class="html-folder-chip" data-folder-key="<?php echo esc_attr($folder_name); ?>" data-tooltip="Show pages in this folder" draggable="true" role="button" tabindex="0">
                        <span class="html-folder-chip-drag" aria-hidden="true" data-tooltip="Drag to reorder">
                            <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2" cy="2" r="1.2"/><circle cx="8" cy="2" r="1.2"/><circle cx="2" cy="7" r="1.2"/><circle cx="8" cy="7" r="1.2"/><circle cx="2" cy="12" r="1.2"/><circle cx="8" cy="12" r="1.2"/></svg>
                        </span>
                        <span class="html-folder-chip-icon" aria-hidden="true">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        </span>
                        <span class="html-folder-chip-label"><?php echo esc_html($folder_name); ?></span>
                        <span class="html-folder-chip-count"><?php echo intval(isset($folder_stats['folders'][$folder_name]) ? $folder_stats['folders'][$folder_name] : 0); ?></span>
                        <button type="button" class="html-folder-chip-kebab" data-folder="<?php echo esc_attr($folder_name); ?>" aria-label="Folder options" tabindex="-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                        </button>
                    </div>
                <?php endforeach; ?>
                <button type="button" class="html-folder-chip-new" id="html-folder-new" data-tooltip="Create a new folder">
                    <span aria-hidden="true">+</span> New Folder
                </button>
            </div>

            <div class="html-bulk-bar" id="html-bulk-bar" style="display:none;" aria-live="polite">
                <span class="html-bulk-count"><span class="html-bulk-count-n">0</span> selected</span>
                <div class="html-bulk-actions">
                    <button type="button" class="button html-bulk-btn" id="html-bulk-move" data-tooltip="Move selected pages to a folder">
                        <span class="html-bulk-btn-label">Move to folder&hellip;</span>
                        <span class="html-bulk-btn-spinner" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="button html-bulk-btn" id="html-bulk-pin" data-tooltip="Pin selected pages to the top">
                        <span class="html-bulk-btn-label">Pin</span>
                        <span class="html-bulk-btn-spinner" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="button html-bulk-btn" id="html-bulk-unpin" data-tooltip="Unpin selected pages">
                        <span class="html-bulk-btn-label">Unpin</span>
                        <span class="html-bulk-btn-spinner" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="button html-bulk-btn" id="html-bulk-download" data-tooltip="Download selected pages as .zip">
                        <span class="html-bulk-btn-label">Download</span>
                        <span class="html-bulk-btn-spinner" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="button html-bulk-btn html-bulk-btn-danger" id="html-bulk-delete" data-tooltip="Move selected pages to trash">
                        <span class="html-bulk-btn-label">Delete</span>
                        <span class="html-bulk-btn-spinner" aria-hidden="true"></span>
                    </button>
                </div>
                <button type="button" class="html-bulk-clear" id="html-bulk-clear" data-tooltip="Clear selection" aria-label="Clear selection">&times;</button>
            </div>

            <table class="wp-list-table widefat fixed striped" id="html-pages-table">
                <thead>
                    <tr>
                        <th class="html-check-col" style="width: 34px;">
                            <label class="html-check-label" data-tooltip="Select all on this page">
                                <input type="checkbox" id="html-check-all" aria-label="Select all">
                                <span class="html-check-box" aria-hidden="true"></span>
                            </label>
                        </th>
                        <th class="html-sortable" data-sort-key="title" style="width: 20%;">
                            <span class="html-sort-label">Title</span>
                            <span class="html-sort-arrows" aria-hidden="true">
                                <span class="html-sort-arrow up">&#9650;</span>
                                <span class="html-sort-arrow down">&#9660;</span>
                            </span>
                        </th>
                        <th class="html-sortable" data-sort-key="slug" style="width: 14%;">
                            <span class="html-sort-label">Slug</span>
                            <span class="html-sort-arrows" aria-hidden="true">
                                <span class="html-sort-arrow up">&#9650;</span>
                                <span class="html-sort-arrow down">&#9660;</span>
                            </span>
                        </th>
                        <th class="html-sortable" data-sort-key="url" style="width: 19%;">
                            <span class="html-sort-label">URL</span>
                            <span class="html-sort-arrows" aria-hidden="true">
                                <span class="html-sort-arrow up">&#9650;</span>
                                <span class="html-sort-arrow down">&#9660;</span>
                            </span>
                        </th>
                        <th class="html-sortable" data-sort-key="folder" style="width: 12%;">
                            <span class="html-sort-label">Folder</span>
                            <span class="html-sort-arrows" aria-hidden="true">
                                <span class="html-sort-arrow up">&#9650;</span>
                                <span class="html-sort-arrow down">&#9660;</span>
                            </span>
                        </th>
                        <th class="html-sortable" data-sort-key="created" style="width: 12%;">
                            <span class="html-sort-label">Created</span>
                            <span class="html-sort-arrows" aria-hidden="true">
                                <span class="html-sort-arrow up">&#9650;</span>
                                <span class="html-sort-arrow down">&#9660;</span>
                            </span>
                        </th>
                        <th style="width: 19%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pages)): ?>
                        <tr>
                            <td colspan="7">No HTML pages found. <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new'); ?>">Create one</a></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pages as $page): ?>
                            <?php echo $this->render_page_row($page); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <?php // Reusable custom modal ?>
            <div class="html-modal-backdrop" id="html-modal-backdrop" style="display:none;">
                <div class="html-modal" role="dialog" aria-modal="true" aria-labelledby="html-modal-title">
                    <div class="html-modal-header">
                        <h2 id="html-modal-title" class="html-modal-title">Title</h2>
                        <button type="button" class="html-modal-close" data-tooltip="Close" aria-label="Close">&times;</button>
                    </div>
                    <div class="html-modal-body" id="html-modal-body"></div>
                    <div class="html-modal-footer">
                        <button type="button" class="button html-modal-cancel">Cancel</button>
                        <button type="button" class="button button-primary html-modal-confirm">
                            <span class="html-modal-confirm-label">Confirm</span>
                            <span class="html-modal-confirm-spinner" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Fetch all enabled HTML pages with pinned items first.
     * Pinned ordered by pin timestamp DESC, unpinned by post_date DESC.
     */
    private function get_sorted_pages($where_filter = null, $folder_key = '__all') {
        $meta_query = array(
            array(
                'key'     => self::META_KEY_ENABLED,
                'value'   => '1',
                'compare' => '=',
            ),
        );

        if ($folder_key === '__none') {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => self::META_KEY_FOLDER,
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => self::META_KEY_FOLDER,
                    'value'   => '',
                    'compare' => '=',
                ),
            );
        } elseif ($folder_key !== '__all' && $folder_key !== '' && $folder_key !== null) {
            $meta_query[] = array(
                'key'     => self::META_KEY_FOLDER,
                'value'   => $folder_key,
                'compare' => '=',
            );
        }

        $args = array(
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => false,
        );

        if (is_callable($where_filter)) {
            add_filter('posts_where', $where_filter);
        }

        $pages = get_posts($args);

        if (is_callable($where_filter)) {
            remove_filter('posts_where', $where_filter);
        }

        $pinned = array();
        $unpinned = array();
        foreach ($pages as $p) {
            $pin_ts = (int) get_post_meta($p->ID, self::META_KEY_PINNED, true);
            if ($pin_ts > 0) {
                $p->__pin_ts = $pin_ts;
                $pinned[] = $p;
            } else {
                $p->__pin_ts = 0;
                $unpinned[] = $p;
            }
        }
        usort($pinned, function($a, $b) {
            return $b->__pin_ts - $a->__pin_ts;
        });

        return array_merge($pinned, $unpinned);
    }

    /**
     * Get all distinct folder names in use (sorted alphabetically, case-insensitive).
     */
    private function get_all_folders() {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value != ''
               AND p.post_status = 'publish'
               AND p.post_type = 'page'",
            self::META_KEY_FOLDER
        );
        $meta_folders = $wpdb->get_col($sql);
        $meta_folders = is_array($meta_folders) ? $meta_folders : array();

        // The option acts as the ordering + registry (includes empty folders).
        $ordered = get_option('html_to_wp_page_folders', array());
        if (!is_array($ordered)) $ordered = array();

        // Start from the saved order, keep only names that are in the option.
        $result = array();
        $seen_lower = array();
        foreach ($ordered as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $key = strtolower($name);
            if (isset($seen_lower[$key])) continue;
            $result[] = $name;
            $seen_lower[$key] = true;
        }

        // Append any folders found in postmeta that aren't in the option yet
        // (case: user added a page to a fresh folder before the option knew about it).
        foreach ($meta_folders as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $key = strtolower($name);
            if (isset($seen_lower[$key])) continue;
            $result[] = $name;
            $seen_lower[$key] = true;
        }

        return $result;
    }

    /**
     * Get list of authors who have at least one HTML page.
     * Returns [ ['id' => int, 'name' => 'Display Name'], ... ]
     */
    private function get_html_authors() {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.post_author
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
               AND pm.meta_value = '1'
               AND p.post_status = 'publish'
               AND p.post_type = 'page'",
            self::META_KEY_ENABLED
        );
        $ids = $wpdb->get_col($sql);
        $ids = is_array($ids) ? array_map('intval', $ids) : array();
        $authors = array();
        foreach ($ids as $id) {
            $u = get_userdata($id);
            if (!$u) continue;
            $authors[] = array('id' => $id, 'name' => $u->display_name ?: $u->user_login);
        }
        usort($authors, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return $authors;
    }

    /**
     * Get counts per folder: total, unfiled, and each folder.
     */
    private function get_folder_stats() {
        $args = array(
            'post_type'      => 'page',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => self::META_KEY_ENABLED,
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        );
        $ids = get_posts($args);
        $stats = array(
            '__all'   => count($ids),
            '__none'  => 0,
            'folders' => array(),
        );
        foreach ($ids as $id) {
            $folder = trim((string) get_post_meta($id, self::META_KEY_FOLDER, true));
            if ($folder === '') {
                $stats['__none']++;
            } else {
                if (!isset($stats['folders'][$folder])) {
                    $stats['folders'][$folder] = 0;
                }
                $stats['folders'][$folder]++;
            }
        }
        ksort($stats['folders'], SORT_NATURAL | SORT_FLAG_CASE);
        return $stats;
    }

    /**
     * Render a single row for the list table (server-side parity with AJAX).
     */
    private function render_page_row($page) {
        $is_pinned = ((int) get_post_meta($page->ID, self::META_KEY_PINNED, true)) > 0;
        $url = $this->get_page_url($page);
        $created_ts = strtotime($page->post_date);
        $created_display = date_i18n('M j, Y g:i a', $created_ts);
        $folder = trim((string) get_post_meta($page->ID, self::META_KEY_FOLDER, true));
        $folder_display = $folder === '' ? 'Unfiled' : $folder;

        $pin_nonce = wp_create_nonce('html_page_toggle_pin_' . $page->ID);
        $folder_nonce = wp_create_nonce('html_page_set_folder_' . $page->ID);
        $pin_label = $is_pinned ? 'Unpin from top' : 'Pin to top';
        $pin_class = 'html-pin-toggle' . ($is_pinned ? ' is-pinned' : '');

        // Folder sort key: put "Unfiled" (empty) last when ASC by using a high-sort char.
        $folder_sort_key = $folder === '' ? '~~~unfiled' : strtolower($folder);

        ob_start();
        ?>
        <tr data-id="<?php echo intval($page->ID); ?>"
            data-pinned="<?php echo $is_pinned ? '1' : '0'; ?>"
            data-created="<?php echo intval($created_ts); ?>"
            data-title="<?php echo esc_attr(strtolower($page->post_title)); ?>"
            data-slug="<?php echo esc_attr(strtolower($page->post_name)); ?>"
            data-url="<?php echo esc_attr(strtolower($url)); ?>"
            data-folder="<?php echo esc_attr($folder); ?>"
            data-folder-sort="<?php echo esc_attr($folder_sort_key); ?>"
            class="<?php echo $is_pinned ? 'html-row-pinned' : ''; ?>">
            <td class="html-check-col">
                <label class="html-check-label" data-tooltip="Select this page">
                    <input type="checkbox" class="html-row-check" value="<?php echo intval($page->ID); ?>" aria-label="Select <?php echo esc_attr($page->post_title); ?>">
                    <span class="html-check-box" aria-hidden="true"></span>
                </label>
            </td>
            <td>
                <?php if ($is_pinned): ?><span class="html-pinned-marker" data-tooltip="Pinned to top">&#128204;</span> <?php endif; ?>
                <strong><?php echo esc_html($page->post_title); ?></strong>
            </td>
            <td><code><?php echo esc_html($page->post_name); ?></code></td>
            <td>
                <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($url); ?></a>
            </td>
            <td class="html-folder-cell">
                <button type="button"
                        class="html-folder-pill<?php echo $folder === '' ? ' is-unfiled' : ''; ?>"
                        data-id="<?php echo intval($page->ID); ?>"
                        data-nonce="<?php echo esc_attr($folder_nonce); ?>"
                        data-current="<?php echo esc_attr($folder); ?>"
                        data-tooltip="Change folder">
                    <?php if ($folder !== ''): ?>
                        <span class="html-folder-pill-icon" aria-hidden="true">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        </span>
                    <?php endif; ?>
                    <span class="html-folder-pill-label"><?php echo esc_html($folder_display); ?></span>
                    <span class="html-folder-pill-caret" aria-hidden="true">&#9662;</span>
                    <span class="html-folder-pill-spinner" aria-hidden="true"></span>
                </button>
            </td>
            <td><?php echo esc_html($created_display); ?></td>
            <td class="html-actions-cell">
                <button type="button"
                        class="<?php echo esc_attr($pin_class); ?>"
                        data-id="<?php echo intval($page->ID); ?>"
                        data-nonce="<?php echo esc_attr($pin_nonce); ?>"
                        data-tooltip="<?php echo esc_attr($pin_label); ?>"
                        aria-label="<?php echo esc_attr($pin_label); ?>">
                    <span class="html-pin-icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"></line><path d="M5 17h14l-1.5-3V8a2 2 0 0 0-2-2H8.5a2 2 0 0 0-2 2v6L5 17z"></path></svg>
                    </span>
                    <span class="html-pin-spinner" aria-hidden="true"></span>
                </button>
                <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new&id=' . $page->ID); ?>" data-tooltip="Edit in plugin editor">Edit</a> |
                <a href="<?php echo get_edit_post_link($page->ID); ?>" data-tooltip="Open in WordPress editor">WP Edit</a> |
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=download&id=' . $page->ID), 'download_html_page_' . $page->ID); ?>" data-tooltip="Download as .html file">Download</a> |
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=delete&id=' . $page->ID), 'delete_html_page_' . $page->ID); ?>"
                   onclick="return confirm('Are you sure you want to delete this page?');"
                   data-tooltip="Move page to trash"
                   style="color: #a00;">Delete</a>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to toggle pinned state on a page.
     */
    public function ajax_toggle_pin() {
        $post_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$post_id || !wp_verify_nonce($nonce, 'html_page_toggle_pin_' . $post_id)) {
            wp_send_json_error('Invalid request');
        }

        $current = (int) get_post_meta($post_id, self::META_KEY_PINNED, true);
        if ($current > 0) {
            delete_post_meta($post_id, self::META_KEY_PINNED);
            $pinned = false;
        } else {
            update_post_meta($post_id, self::META_KEY_PINNED, time());
            $pinned = true;
        }

        wp_send_json_success(array('pinned' => $pinned));
    }

    /**
     * AJAX handler for instant search on list page
     */
    public function ajax_search_pages() {
        check_ajax_referer('html_page_search_nonce', 'nonce');

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        $folder_key = isset($_POST['folder']) ? sanitize_text_field(wp_unslash($_POST['folder'])) : '__all';

        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to   = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $author_id = isset($_POST['author']) ? intval($_POST['author']) : 0;
        $pinned    = isset($_POST['pinned']) ? sanitize_text_field($_POST['pinned']) : ''; // '', 'pinned', 'unpinned'

        // Validate date strings (YYYY-MM-DD).
        $date_from_ok = ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from));
        $date_to_ok   = ($date_to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to));

        global $wpdb;

        $where_filter = function($where) use ($wpdb, $term, $date_from, $date_from_ok, $date_to, $date_to_ok, $author_id) {
            if ($term !== '') {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $where .= $wpdb->prepare(
                    " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_name LIKE %s)",
                    $like, $like
                );
            }
            if ($date_from_ok) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_date >= %s", $date_from . ' 00:00:00');
            }
            if ($date_to_ok) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_date <= %s", $date_to . ' 23:59:59');
            }
            if ($author_id > 0) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_author = %d", $author_id);
            }
            return $where;
        };

        $pages = $this->get_sorted_pages($where_filter, $folder_key);

        // Post-filter: pinned status (uses meta and would complicate the SQL).
        if ($pinned === 'pinned') {
            $pages = array_values(array_filter($pages, function($p) { return $p->__pin_ts > 0; }));
        } elseif ($pinned === 'unpinned') {
            $pages = array_values(array_filter($pages, function($p) { return $p->__pin_ts == 0; }));
        }

        $rows_html = '';
        foreach ($pages as $page) {
            $rows_html .= $this->render_page_row($page);
        }

        wp_send_json_success(array(
            'rows_html' => $rows_html,
            'count'     => count($pages),
            'stats'     => $this->get_folder_stats(),
            'folders'   => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: set (or clear) the folder for a page.
     */
    public function ajax_set_folder() {
        $post_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        $folder = isset($_POST['folder']) ? $this->sanitize_folder_name(wp_unslash($_POST['folder'])) : '';

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!$post_id || !wp_verify_nonce($nonce, 'html_page_set_folder_' . $post_id)) {
            wp_send_json_error('Invalid request');
        }

        if ($folder === '') {
            delete_post_meta($post_id, self::META_KEY_FOLDER);
        } else {
            update_post_meta($post_id, self::META_KEY_FOLDER, $folder);
        }

        wp_send_json_success(array(
            'folder'  => $folder,
            'stats'   => $this->get_folder_stats(),
            'folders' => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: create an empty folder (a folder only exists once a page is in it,
     * so we return it in the list even if empty for the current session).
     */
    public function ajax_create_folder() {
        check_ajax_referer('html_page_search_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $name = $this->sanitize_folder_name(wp_unslash(isset($_POST['name']) ? $_POST['name'] : ''));
        if ($name === '') {
            wp_send_json_error('Folder name is required');
        }

        // Store as an option so folder order + empty folders persist across requests.
        $registered = get_option('html_to_wp_page_folders', array());
        if (!is_array($registered)) {
            $registered = array();
        }
        $existing_lower = array_map('strtolower', $registered);
        $all_existing_lower = array_map('strtolower', $this->get_all_folders());
        if (in_array(strtolower($name), $existing_lower, true) || in_array(strtolower($name), $all_existing_lower, true)) {
            wp_send_json_error('A folder with this name already exists');
        }
        $registered[] = $name; // append to end — user can drag to reorder
        update_option('html_to_wp_page_folders', $registered);

        wp_send_json_success(array(
            'name'    => $name,
            'stats'   => $this->get_folder_stats(),
            'folders' => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: rename a folder — updates every page in that folder.
     */
    public function ajax_rename_folder() {
        check_ajax_referer('html_page_search_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $old = $this->sanitize_folder_name(wp_unslash(isset($_POST['old']) ? $_POST['old'] : ''));
        $new = $this->sanitize_folder_name(wp_unslash(isset($_POST['new']) ? $_POST['new'] : ''));

        if ($old === '' || $new === '') {
            wp_send_json_error('Both old and new folder names are required');
        }

        if (strtolower($old) === strtolower($new)) {
            wp_send_json_success(array(
                'name'    => $new,
                'stats'   => $this->get_folder_stats(),
                'folders' => $this->get_all_folders(),
            ));
        }

        $existing_lower = array_map('strtolower', $this->get_all_folders());
        if (in_array(strtolower($new), $existing_lower, true)) {
            wp_send_json_error('A folder with this name already exists');
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->postmeta,
            array('meta_value' => $new),
            array('meta_key' => self::META_KEY_FOLDER, 'meta_value' => $old)
        );

        // Update the ordered-folders option: replace old name in-place with new.
        $registered = get_option('html_to_wp_page_folders', array());
        if (is_array($registered)) {
            $found = false;
            foreach ($registered as $i => $n) {
                if (strtolower($n) === strtolower($old)) {
                    $registered[$i] = $new;
                    $found = true;
                    break;
                }
            }
            if (!$found) $registered[] = $new;
            update_option('html_to_wp_page_folders', $registered);
        }

        wp_send_json_success(array(
            'name'    => $new,
            'stats'   => $this->get_folder_stats(),
            'folders' => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: reorder folders. Accepts an ordered array of folder names.
     * Names not present in the current folder set are dropped; missing folders are appended.
     */
    public function ajax_reorder_folders() {
        check_ajax_referer('html_page_search_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $incoming = isset($_POST['order']) ? (array) wp_unslash($_POST['order']) : array();
        $current  = $this->get_all_folders();
        $current_lower = array_map('strtolower', $current);
        $current_by_lower = array();
        foreach ($current as $c) { $current_by_lower[strtolower($c)] = $c; }

        $new_order = array();
        $seen_lower = array();
        foreach ($incoming as $name) {
            $name = trim(sanitize_text_field($name));
            if ($name === '') continue;
            $key = strtolower($name);
            if (!isset($current_by_lower[$key]) || isset($seen_lower[$key])) continue;
            $new_order[] = $current_by_lower[$key];
            $seen_lower[$key] = true;
        }
        // Append any folders not in incoming to preserve them.
        foreach ($current as $name) {
            $key = strtolower($name);
            if (isset($seen_lower[$key])) continue;
            $new_order[] = $name;
            $seen_lower[$key] = true;
        }

        update_option('html_to_wp_page_folders', $new_order);

        wp_send_json_success(array(
            'folders' => $this->get_all_folders(),
            'stats'   => $this->get_folder_stats(),
        ));
    }

    /**
     * AJAX: delete a folder — all pages inside move to Unfiled.
     */
    public function ajax_delete_folder() {
        check_ajax_referer('html_page_search_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $name = $this->sanitize_folder_name(wp_unslash(isset($_POST['name']) ? $_POST['name'] : ''));
        if ($name === '') {
            wp_send_json_error('Folder name is required');
        }

        global $wpdb;
        $wpdb->delete($wpdb->postmeta, array(
            'meta_key'   => self::META_KEY_FOLDER,
            'meta_value' => $name,
        ));

        // Remove from registered-empty-folders option too.
        $registered = get_option('html_to_wp_page_folders', array());
        if (is_array($registered)) {
            $registered = array_values(array_filter($registered, function($n) use ($name) {
                return strtolower($n) !== strtolower($name);
            }));
            update_option('html_to_wp_page_folders', $registered);
        }

        wp_send_json_success(array(
            'stats'   => $this->get_folder_stats(),
            'folders' => $this->get_all_folders(),
        ));
    }

    /**
     * Sanitize a folder name — trim, strip tags, cap length, disallow ~~~ marker.
     */
    private function sanitize_folder_name($raw) {
        $name = trim(sanitize_text_field($raw));
        if ($name === '') return '';
        if (mb_strlen($name) > 50) {
            $name = mb_substr($name, 0, 50);
        }
        return $name;
    }

    /**
     * Sanitize a bulk-action IDs input into a list of valid HTML-page post IDs.
     */
    private function sanitize_bulk_ids($raw) {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        if (!is_array($raw)) return array();
        $ids = array();
        foreach ($raw as $val) {
            $id = intval($val);
            if ($id <= 0) continue;
            $post = get_post($id);
            if (!$post || $post->post_type !== 'page') continue;
            // Only touch pages that were flagged as HTML pages by this plugin.
            if (get_post_meta($id, self::META_KEY_ENABLED, true) !== '1') continue;
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * AJAX: bulk delete (trash) selected HTML pages.
     */
    public function ajax_bulk_delete() {
        check_ajax_referer('html_page_search_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $ids = $this->sanitize_bulk_ids(isset($_POST['ids']) ? $_POST['ids'] : array());
        if (empty($ids)) {
            wp_send_json_error('No valid pages selected');
        }
        $count = 0;
        foreach ($ids as $id) {
            delete_post_meta($id, self::META_KEY_CONTENT);
            delete_post_meta($id, self::META_KEY_ENABLED);
            if (wp_trash_post($id)) $count++;
        }
        wp_send_json_success(array(
            'affected' => $count,
            'ids'      => $ids,
            'stats'    => $this->get_folder_stats(),
            'folders'  => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: bulk pin or unpin selected HTML pages.
     */
    public function ajax_bulk_pin() {
        check_ajax_referer('html_page_search_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $ids = $this->sanitize_bulk_ids(isset($_POST['ids']) ? $_POST['ids'] : array());
        $pin = isset($_POST['pin']) && $_POST['pin'] === '1';
        if (empty($ids)) {
            wp_send_json_error('No valid pages selected');
        }
        $count = 0;
        $base_ts = time();
        foreach ($ids as $i => $id) {
            if ($pin) {
                update_post_meta($id, self::META_KEY_PINNED, $base_ts + $i);
            } else {
                delete_post_meta($id, self::META_KEY_PINNED);
            }
            $count++;
        }
        wp_send_json_success(array(
            'affected' => $count,
            'ids'      => $ids,
            'pinned'   => $pin,
        ));
    }

    /**
     * AJAX: bulk move selected pages to a folder (or clear folder if empty).
     */
    public function ajax_bulk_set_folder() {
        check_ajax_referer('html_page_search_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $ids = $this->sanitize_bulk_ids(isset($_POST['ids']) ? $_POST['ids'] : array());
        $folder = $this->sanitize_folder_name(wp_unslash(isset($_POST['folder']) ? $_POST['folder'] : ''));
        if (empty($ids)) {
            wp_send_json_error('No valid pages selected');
        }
        $count = 0;
        foreach ($ids as $id) {
            if ($folder === '') {
                delete_post_meta($id, self::META_KEY_FOLDER);
            } else {
                update_post_meta($id, self::META_KEY_FOLDER, $folder);
            }
            $count++;
        }
        wp_send_json_success(array(
            'affected' => $count,
            'ids'      => $ids,
            'folder'   => $folder,
            'stats'    => $this->get_folder_stats(),
            'folders'  => $this->get_all_folders(),
        ));
    }

    /**
     * AJAX: bulk download selected pages as a zip. Streams the zip in the response.
     */
    public function ajax_bulk_download() {
        check_ajax_referer('html_page_search_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $ids = $this->sanitize_bulk_ids(isset($_POST['ids']) ? $_POST['ids'] : array());
        if (empty($ids)) {
            wp_send_json_error('No valid pages selected');
        }

        if (!class_exists('ZipArchive')) {
            wp_send_json_error('Server is missing the ZipArchive PHP extension');
        }

        $zip = new ZipArchive();
        $zip_filename = sys_get_temp_dir() . '/html-pages-bulk-' . time() . '-' . wp_generate_password(6, false) . '.zip';
        if ($zip->open($zip_filename, ZipArchive::CREATE) !== true) {
            wp_send_json_error('Could not create archive');
        }

        $used = array();
        foreach ($ids as $id) {
            $page = get_post($id);
            if (!$page) continue;
            $html = get_post_meta($id, self::META_KEY_CONTENT, true);
            $base = sanitize_file_name($page->post_name);
            if ($base === '') $base = 'page-' . $id;
            $filename = $base . '.html';
            $suffix = 1;
            while (isset($used[$filename])) {
                $filename = $base . '-' . $suffix . '.html';
                $suffix++;
            }
            $used[$filename] = true;
            $zip->addFromString($filename, $html);
        }
        $zip->close();

        // Clean any output buffers before streaming binary.
        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="html-pages-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($zip_filename));
        header('Cache-Control: no-store');
        readfile($zip_filename);
        @unlink($zip_filename);
        exit;
    }

    /**
     * AJAX handler for quick import
     */
    public function ajax_import_page() {
        check_ajax_referer('html_page_search_nonce', 'nonce');

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $html  = isset($_POST['html']) ? $_POST['html'] : '';
        $slug_input = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';

        if (empty($title) || empty($html)) {
            wp_send_json_error('Title and HTML content are required.');
        }

        $slug = !empty($slug_input) ? $slug_input : sanitize_title($title);

        // Ensure unique slug
        $unique_slug = wp_unique_post_slug($slug, 0, 'publish', 'page', 0);

        $page_id = wp_insert_post(array(
            'post_title'  => $title,
            'post_name'   => $unique_slug,
            'post_status' => 'publish',
            'post_type'   => 'page',
            'post_content'=> '',
        ));

        if (!$page_id || is_wp_error($page_id)) {
            wp_send_json_error('Failed to create page.');
        }

        update_post_meta($page_id, self::META_KEY_CONTENT, $html);
        update_post_meta($page_id, self::META_KEY_ENABLED, '1');

        $page = get_post($page_id);
        $url  = $this->get_page_url($page);

        wp_send_json_success(array(
            'title' => esc_html($title),
            'slug'  => esc_html($page->post_name),
            'url'   => esc_url($url),
            'edit_url' => admin_url('admin.php?page=html-to-wp-page-new&id=' . $page_id),
        ));
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
                        update_post_meta($page_id, self::META_KEY_WP_HEAD, isset($_POST['wp_head_enabled']) ? '1' : '0');
                        update_post_meta($page_id, self::META_KEY_WP_FOOTER, isset($_POST['wp_footer_enabled']) ? '1' : '0');

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
                            update_post_meta($new_page_id, self::META_KEY_WP_HEAD, isset($_POST['wp_head_enabled']) ? '1' : '0');
                            update_post_meta($new_page_id, self::META_KEY_WP_FOOTER, isset($_POST['wp_footer_enabled']) ? '1' : '0');

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
        $wp_head_enabled = $page ? get_post_meta($page->ID, self::META_KEY_WP_HEAD, true) : '';
        $wp_footer_enabled = $page ? get_post_meta($page->ID, self::META_KEY_WP_FOOTER, true) : '';

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
                    <tr>
                        <th>SEO / Plugin Hooks</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wp_head_enabled" value="1" <?php checked($wp_head_enabled, '1'); ?>>
                                    Load <code>wp_head()</code> &mdash; injects before <code>&lt;/head&gt;</code>
                                </label>
                                <p class="description">Allows SEO plugins (Yoast, Rank Math, etc.) to inject meta tags, Open Graph data, and schema markup into this page.</p>
                                <br>
                                <label>
                                    <input type="checkbox" name="wp_footer_enabled" value="1" <?php checked($wp_footer_enabled, '1'); ?>>
                                    Load <code>wp_footer()</code> &mdash; injects before <code>&lt;/body&gt;</code>
                                </label>
                                <p class="description">Allows plugins to inject tracking scripts (analytics, pixels) and other footer code into this page.</p>
                            </fieldset>
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
                    $html_content = $this->maybe_inject_wp_hooks($html_content, $page->ID);
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
            $html_content = $this->maybe_inject_wp_hooks($html_content, $post_id);
            echo $html_content;
            exit;
        }
    }

    /**
     * Inject wp_head() and wp_footer() output into HTML content if enabled
     */
    private function maybe_inject_wp_hooks($html_content, $post_id) {
        $inject_head = get_post_meta($post_id, self::META_KEY_WP_HEAD, true) === '1';
        $inject_footer = get_post_meta($post_id, self::META_KEY_WP_FOOTER, true) === '1';

        if (!$inject_head && !$inject_footer) {
            return $html_content;
        }

        if ($inject_head) {
            ob_start();
            wp_head();
            $head_output = ob_get_clean();

            // Insert before </head>
            $pos = stripos($html_content, '</head>');
            if ($pos !== false) {
                $html_content = substr_replace($html_content, $head_output . "\n", $pos, 0);
            }
        }

        if ($inject_footer) {
            ob_start();
            wp_footer();
            $footer_output = ob_get_clean();

            // Insert before </body>
            $pos = stripos($html_content, '</body>');
            if ($pos !== false) {
                $html_content = substr_replace($html_content, $footer_output . "\n", $pos, 0);
            }
        }

        return $html_content;
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
                    '2.11.0'
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
            '2.11.0'
        );

        wp_enqueue_script(
            'html-to-wp-page-admin',
            plugin_dir_url(__FILE__) . 'admin-script.js',
            array('jquery'),
            '2.11.0',
            true
        );

        wp_localize_script('html-to-wp-page-admin', 'htmlPageAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('html_page_search_nonce'),
        ));
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

        // Enable auto-update by default for this plugin
        add_filter('auto_update_plugin', array($this, 'enable_auto_update'), 10, 2);
    }

    public function enable_auto_update($update, $item) {
        if (isset($item->plugin) && $item->plugin === $this->slug) {
            return true;
        }
        return $update;
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

            $icon_url = "https://raw.githubusercontent.com/{$this->github_repo}/main/assets/icon.svg";

            $transient->response[$this->slug] = (object) array(
                'slug'         => dirname($this->slug),
                'plugin'       => $this->slug,
                'new_version'  => $remote_version,
                'url'          => "https://github.com/{$this->github_repo}",
                'package'      => $download_url,
                'tested'       => '6.9.1',
                'requires'     => '5.0',
                'requires_php' => '7.4',
                'icons'        => array(
                    'svg'     => $icon_url,
                    'default' => $icon_url,
                ),
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

        $icon_url = "https://raw.githubusercontent.com/{$this->github_repo}/main/assets/icon.svg";

        $result = (object) array(
            'name'              => $plugin_data['Name'],
            'slug'              => dirname($this->slug),
            'version'           => ltrim($release->tag_name, 'v'),
            'author'            => $plugin_data['AuthorName'],
            'homepage'          => $plugin_data['PluginURI'] ?: "https://github.com/{$this->github_repo}",
            'requires'          => '5.0',
            'requires_php'      => '7.4',
            'tested'            => '6.9.1',
            'downloaded'        => 0,
            'last_updated'      => $release->published_at,
            'sections'          => array(
                'description'   => $plugin_data['Description'],
                'changelog'     => nl2br(esc_html($release->body)),
            ),
            'download_link'     => $release->zipball_url,
            'icons'             => array(
                'svg'           => $icon_url,
                'default'       => $icon_url,
            ),
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
