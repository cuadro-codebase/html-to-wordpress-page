<?php
/**
 * Plugin Name: HTML to WordPress Page
 * Description: Create standalone HTML pages without WordPress theme header/footer. Perfect for uploading AI-generated HTML.
 * Version: 1.0.0
 * Author: Cuadro Srl
 * Author URI: https://cuadrostudio.com
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class HTML_To_WordPress_Page {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'html_pages';

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('init', array($this, 'register_rewrite_rules'));
        add_action('template_redirect', array($this, 'render_html_page'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }

    public function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            slug varchar(255) NOT NULL UNIQUE,
            html_content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^html/([^/]+)/?$',
            'index.php?html_wp_page=$matches[1]',
            'top'
        );
    }

    public function add_query_vars($vars) {
        $vars[] = 'html_wp_page';
        return $vars;
    }

    public function render_html_page() {
        $slug = get_query_var('html_wp_page');

        if (empty($slug)) {
            return;
        }

        global $wpdb;
        $page = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE slug = %s",
            $slug
        ));

        if (!$page) {
            status_header(404);
            echo '<!DOCTYPE html><html><head><title>Page Not Found</title></head><body><h1>404 - Page Not Found</h1></body></html>';
            exit;
        }

        // Output raw HTML without any WordPress elements
        echo $page->html_content;
        exit;
    }

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
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'html-to-wp-page') === false) {
            return;
        }

        wp_enqueue_style('html-to-wp-page-admin', plugin_dir_url(__FILE__) . 'admin-style.css', array(), '1.0.0');
        wp_enqueue_script('html-to-wp-page-admin', plugin_dir_url(__FILE__) . 'admin-script.js', array('jquery'), '1.0.0', true);
    }

    public function admin_page_list() {
        global $wpdb;

        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_html_page_' . $_GET['id'])) {
                $wpdb->delete($this->table_name, array('id' => intval($_GET['id'])));
                echo '<div class="notice notice-success"><p>Page deleted successfully.</p></div>';
            }
        }

        $pages = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">HTML Pages</h1>
            <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new'); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 30%;">Title</th>
                        <th style="width: 25%;">Slug</th>
                        <th style="width: 25%;">URL</th>
                        <th style="width: 10%;">Created</th>
                        <th style="width: 10%;">Actions</th>
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
                                <td><strong><?php echo esc_html($page->title); ?></strong></td>
                                <td><code><?php echo esc_html($page->slug); ?></code></td>
                                <td>
                                    <a href="<?php echo home_url('/html/' . $page->slug); ?>" target="_blank">
                                        <?php echo home_url('/html/' . $page->slug); ?>
                                    </a>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($page->created_at)); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=html-to-wp-page-new&id=' . $page->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=html-to-wp-page&action=delete&id=' . $page->id), 'delete_html_page_' . $page->id); ?>"
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

    public function admin_page_edit() {
        global $wpdb;

        $page_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $page = null;
        $message = '';
        $error = '';

        // Load existing page for editing
        if ($page_id) {
            $page = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $page_id
            ));
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['html_wp_nonce'])) {
            if (wp_verify_nonce($_POST['html_wp_nonce'], 'html_wp_save')) {
                $title = sanitize_text_field($_POST['title']);
                $slug = sanitize_title($_POST['slug']);
                $html_content = $_POST['html_content']; // Don't sanitize HTML content

                if (empty($title) || empty($slug) || empty($html_content)) {
                    $error = 'Please fill in all fields.';
                } else {
                    // Check for duplicate slug
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$this->table_name} WHERE slug = %s AND id != %d",
                        $slug,
                        $page_id
                    ));

                    if ($existing) {
                        $error = 'This slug is already in use. Please choose a different one.';
                    } else {
                        if ($page_id) {
                            // Update existing
                            $wpdb->update(
                                $this->table_name,
                                array(
                                    'title' => $title,
                                    'slug' => $slug,
                                    'html_content' => $html_content
                                ),
                                array('id' => $page_id)
                            );
                            $message = 'Page updated successfully.';
                        } else {
                            // Insert new
                            $wpdb->insert(
                                $this->table_name,
                                array(
                                    'title' => $title,
                                    'slug' => $slug,
                                    'html_content' => $html_content
                                )
                            );
                            $page_id = $wpdb->insert_id;
                            $message = 'Page created successfully.';

                            // Redirect to edit page
                            wp_redirect(admin_url('admin.php?page=html-to-wp-page-new&id=' . $page_id . '&created=1'));
                            exit;
                        }

                        // Reload page data
                        $page = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$this->table_name} WHERE id = %d",
                            $page_id
                        ));
                    }
                }
            }
        }

        if (isset($_GET['created'])) {
            $message = 'Page created successfully.';
        }

        ?>
        <div class="wrap">
            <h1><?php echo $page_id ? 'Edit HTML Page' : 'Add New HTML Page'; ?></h1>

            <?php if ($message): ?>
                <div class="notice notice-success">
                    <p>
                        <?php if (isset($_GET['created']) && $page): ?>
                            Page created successfully. URL: <a href="<?php echo esc_url(home_url('/html/' . $page->slug)); ?>" target="_blank"><?php echo esc_html(home_url('/html/' . $page->slug)); ?></a>
                        <?php else: ?>
                            <?php echo esc_html($message); ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if ($page): ?>
                <div class="html-wp-page-url">
                    <strong>Page URL:</strong>
                    <a href="<?php echo home_url('/html/' . $page->slug); ?>" target="_blank">
                        <?php echo home_url('/html/' . $page->slug); ?>
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
                                   value="<?php echo $page ? esc_attr($page->title) : ''; ?>" required>
                            <p class="description">Internal name for this page</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="slug">Slug</label></th>
                        <td>
                            <input type="text" id="slug" name="slug" class="regular-text"
                                   value="<?php echo $page ? esc_attr($page->slug) : ''; ?>" required>
                            <p class="description">URL will be: <?php echo home_url('/html/'); ?><span id="slug-preview"><?php echo $page ? esc_html($page->slug) : 'your-slug'; ?></span></p>
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
                            <textarea id="html_content" name="html_content" rows="25" class="large-text code" required><?php echo $page ? esc_textarea($page->html_content) : ''; ?></textarea>
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
}

// Initialize the plugin
new HTML_To_WordPress_Page();

// Flush rewrite rules on plugin deactivation
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
