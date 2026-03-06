<?php
/**
 * Knowledge Base AJAX Handler — Doc registration CRUD
 *
 * @package SupportOps\AJAX\Handlers
 */

namespace SupportOps\AJAX\Handlers;

use SupportOps\Database\Manager as DatabaseManager;

class KnowledgeBaseHandler {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    /**
     * Save (create or update) a doc entry
     */
    public function save_doc() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id           = intval($_POST['id'] ?? 0);
        $product_name = sanitize_text_field($_POST['product_name'] ?? '');
        $doc_url      = esc_url_raw($_POST['doc_url'] ?? '');
        $doc_title    = sanitize_text_field($_POST['doc_title'] ?? '');
        $category     = sanitize_text_field($_POST['category'] ?? '');
        $tags         = sanitize_text_field($_POST['tags'] ?? '');
        $status       = sanitize_text_field($_POST['status'] ?? 'active');

        if (!$product_name || !$doc_url) {
            wp_send_json_error('Product name and URL are required');
        }

        $table = $this->database->get_table('doc_central_meta');
        $current_user = wp_get_current_user();

        $data = [
            'product_name' => $product_name,
            'doc_url'      => $doc_url,
            'doc_title'    => $doc_title,
            'category'     => $category,
            'tags'         => $tags,
            'status'       => $status,
            'added_by'     => $current_user->user_email,
        ];

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
            wp_send_json_success(['message' => 'Doc updated', 'id' => $id]);
        } else {
            $wpdb->insert($table, $data);
            wp_send_json_success(['message' => 'Doc added', 'id' => $wpdb->insert_id]);
        }
    }

    /**
     * Delete a doc entry
     */
    public function delete_doc() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID required');
        }

        $table = $this->database->get_table('doc_central_meta');
        $wpdb->delete($table, ['id' => $id]);

        wp_send_json_success(['message' => 'Doc deleted']);
    }

    /**
     * Update doc status (active / needs_update / archived)
     */
    public function update_doc_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $id     = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'active');

        if (!$id) {
            wp_send_json_error('ID required');
        }

        $table = $this->database->get_table('doc_central_meta');
        $wpdb->update($table, ['status' => $status], ['id' => $id]);

        wp_send_json_success(['message' => 'Status updated']);
    }

    /**
     * Get all docs (for AJAX reload)
     */
    public function get_docs() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $this->database->get_table('doc_central_meta');

        $docs = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY product_name, doc_title"
        );

        wp_send_json_success(['docs' => $docs]);
    }

    /**
     * Import docs from a sitemap.xml URL
     *
     * Fetches the sitemap, parses all <loc> URLs, and bulk-inserts them.
     * Skips duplicates (same product_name + doc_url).
     * If the URL points to a sitemap index, follows child sitemaps.
     */
    public function import_sitemap() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $sitemap_url  = esc_url_raw($_POST['sitemap_url'] ?? '');
        $product_name = sanitize_text_field($_POST['product_name'] ?? '');

        if (!$sitemap_url || !$product_name) {
            wp_send_json_error('Sitemap URL and product name are required');
        }

        // Fetch and parse sitemap
        $urls = $this->fetch_sitemap_urls($sitemap_url);

        if (is_wp_error($urls)) {
            wp_send_json_error('Failed to fetch sitemap: ' . $urls->get_error_message());
        }

        if (empty($urls)) {
            wp_send_json_error('No URLs found in sitemap');
        }

        global $wpdb;
        $table = $this->database->get_table('doc_central_meta');
        $current_user = wp_get_current_user();

        // Get existing URLs for this product to avoid duplicates
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT doc_url FROM {$table} WHERE product_name = %s",
            $product_name
        ));
        $existing_set = array_flip($existing);

        $added = 0;
        $skipped = 0;

        foreach ($urls as $url) {
            // Normalize URL (strip trailing slash for comparison)
            $normalized = rtrim($url, '/');

            if (isset($existing_set[$url]) || isset($existing_set[$normalized]) || isset($existing_set[$normalized . '/'])) {
                $skipped++;
                continue;
            }

            // Auto-generate title from URL slug
            $title = $this->url_to_title($url);

            // Auto-detect category from URL path segments
            $category = $this->url_to_category($url);

            $wpdb->insert($table, [
                'product_name' => $product_name,
                'doc_url'      => $url,
                'doc_title'    => $title,
                'category'     => $category,
                'tags'         => '',
                'status'       => 'active',
                'added_by'     => $current_user->user_email,
            ]);
            $added++;
        }

        wp_send_json_success([
            'message' => sprintf('%d docs imported, %d duplicates skipped', $added, $skipped),
            'added'   => $added,
            'skipped' => $skipped,
            'total'   => count($urls),
        ]);
    }

    /**
     * Sync sitemap — re-import, archive removed URLs
     */
    public function sync_sitemap() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $sitemap_url  = esc_url_raw($_POST['sitemap_url'] ?? '');
        $product_name = sanitize_text_field($_POST['product_name'] ?? '');

        if (!$sitemap_url || !$product_name) {
            wp_send_json_error('Sitemap URL and product name are required');
        }

        $urls = $this->fetch_sitemap_urls($sitemap_url);

        if (is_wp_error($urls)) {
            wp_send_json_error('Failed to fetch sitemap: ' . $urls->get_error_message());
        }

        global $wpdb;
        $table = $this->database->get_table('doc_central_meta');
        $current_user = wp_get_current_user();

        // Get all existing docs for this product
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, doc_url FROM {$table} WHERE product_name = %s",
            $product_name
        ));

        $existing_map = [];
        foreach ($existing as $e) {
            $existing_map[rtrim($e->doc_url, '/')] = $e->id;
        }

        // Normalize sitemap URLs
        $sitemap_set = [];
        foreach ($urls as $u) {
            $sitemap_set[rtrim($u, '/')] = $u;
        }

        $added = 0;
        $archived = 0;

        // Add new URLs from sitemap
        foreach ($sitemap_set as $norm => $url) {
            if (!isset($existing_map[$norm])) {
                $wpdb->insert($table, [
                    'product_name' => $product_name,
                    'doc_url'      => $url,
                    'doc_title'    => $this->url_to_title($url),
                    'category'     => $this->url_to_category($url),
                    'tags'         => '',
                    'status'       => 'active',
                    'added_by'     => $current_user->user_email,
                ]);
                $added++;
            }
        }

        // Archive docs no longer in sitemap
        foreach ($existing_map as $norm => $id) {
            if (!isset($sitemap_set[$norm])) {
                $wpdb->update($table, ['status' => 'archived'], ['id' => $id]);
                $archived++;
            }
        }

        wp_send_json_success([
            'message'  => sprintf('%d new docs added, %d archived (removed from sitemap)', $added, $archived),
            'added'    => $added,
            'archived' => $archived,
        ]);
    }

    /**
     * Fetch all <loc> URLs from a sitemap (handles sitemap index too)
     */
    private function fetch_sitemap_urls($sitemap_url) {
        $response = wp_remote_get($sitemap_url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new \WP_Error('empty', 'Empty response from sitemap');
        }

        // Suppress XML errors
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        if (!$xml) {
            return new \WP_Error('parse', 'Failed to parse XML');
        }

        $urls = [];

        // Check if this is a sitemap index
        if ($xml->getName() === 'sitemapindex') {
            foreach ($xml->sitemap as $sm) {
                $child_url = (string) $sm->loc;
                // Only follow doc-related sitemaps
                if (stripos($child_url, 'doc') !== false || stripos($child_url, 'page') !== false) {
                    $child_urls = $this->fetch_sitemap_urls($child_url);
                    if (!is_wp_error($child_urls)) {
                        $urls = array_merge($urls, $child_urls);
                    }
                }
            }
        } else {
            // Standard urlset
            $namespaces = $xml->getNamespaces(true);
            $ns = $namespaces[''] ?? null;

            if ($ns) {
                $xml->registerXPathNamespace('sm', $ns);
                $locs = $xml->xpath('//sm:url/sm:loc');
            } else {
                $locs = $xml->xpath('//url/loc');
            }

            if ($locs) {
                foreach ($locs as $loc) {
                    $url = trim((string) $loc);
                    if ($url) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Convert URL slug to human-readable title
     * e.g. https://docs.fluentsupport.com/openai-integration-with-fluent-support
     *   → "Openai Integration With Fluent Support"
     */
    private function url_to_title($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $slug = trim($path, '/');

        // Get the last segment
        $parts = explode('/', $slug);
        $slug = end($parts) ?: $slug;

        // Convert hyphens to spaces, title case
        $title = str_replace(['-', '_'], ' ', $slug);
        $title = ucwords($title);

        return $title;
    }

    /**
     * Auto-detect category from URL path
     * e.g. /docs/integrations/stripe/ → "integrations"
     */
    private function url_to_category($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $segments = array_filter(explode('/', trim($path, '/')));

        // If there are multiple segments, the first non-"docs" one is likely a category
        $skip = ['docs', 'doc', 'documentation', 'guide', 'guides', 'help'];
        foreach ($segments as $seg) {
            if (!in_array(strtolower($seg), $skip) && count($segments) > 1) {
                // Only use as category if it's not the last segment (which is the doc itself)
                $last = end($segments);
                if ($seg !== $last) {
                    return ucwords(str_replace(['-', '_'], ' ', $seg));
                }
            }
        }

        return '';
    }

    /**
     * Save a sitemap URL for a product (stored in WP option)
     */
    public function save_sitemap_url() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $product = sanitize_text_field($_POST['product_name'] ?? '');
        $url     = esc_url_raw($_POST['sitemap_url'] ?? '');

        if (!$product || !$url) {
            wp_send_json_error('Product and URL required');
        }

        $sitemaps = get_option('ais_kb_sitemaps', []);
        $sitemaps[$product] = [
            'url'         => $url,
            'last_import' => current_time('mysql'),
        ];
        update_option('ais_kb_sitemaps', $sitemaps);

        wp_send_json_success(['message' => 'Sitemap URL saved']);
    }

    /**
     * Remove a sitemap URL for a product
     */
    public function remove_sitemap() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $product = sanitize_text_field($_POST['product_name'] ?? '');
        if (!$product) {
            wp_send_json_error('Product required');
        }

        $sitemaps = get_option('ais_kb_sitemaps', []);
        unset($sitemaps[$product]);
        update_option('ais_kb_sitemaps', $sitemaps);

        wp_send_json_success(['message' => 'Sitemap removed']);
    }
}
