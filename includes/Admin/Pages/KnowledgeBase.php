<?php
/**
 * Knowledge Base Page — Sitemap import, gap analysis, product coverage
 *
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class KnowledgeBase {

    private $database;

    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }

    public function render() {
        global $wpdb;

        $doc_table   = $this->database->get_table('doc_central_meta');
        $topic_table = $this->database->get_table('topic_stats');

        // Fetch all docs
        $docs = $wpdb->get_results("SELECT * FROM {$doc_table} ORDER BY product_name, doc_title");

        // Group docs by product
        $docs_by_product = [];
        foreach ($docs as $doc) {
            $docs_by_product[$doc->product_name][] = $doc;
        }

        // Get saved sitemap URLs from option
        $sitemaps = get_option('ais_kb_sitemaps', []);

        // Fetch flagged topics (FAQ candidates or doc update needed)
        $flagged_topics = $wpdb->get_results(
            "SELECT * FROM {$topic_table}
             WHERE is_faq_candidate = 1 OR is_doc_update_needed = 1
             ORDER BY ticket_count DESC"
        );

        // Build gap analysis
        $gap_analysis = $this->analyze_gaps($flagged_topics, $docs);

        // Product coverage stats
        $product_coverage = $this->get_product_coverage($docs_by_product, $flagged_topics);

        $this->render_styles();
        ?>

        <!-- Section 1: Sitemap Import -->
        <div class="ops-card">
            <div class="ops-card-header">
                <h3 style="margin:0;">Documentation Sources</h3>
                <button type="button" class="ops-btn primary" onclick="openAddSitemap()">Add Product Sitemap</button>
            </div>

            <!-- Add Sitemap Form (hidden by default) -->
            <div id="sitemap-form-panel" style="display:none;padding:16px 20px;background:var(--color-bg-subtle);border-bottom:1px solid var(--color-border);">
                <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;align-items:end;">
                    <div>
                        <label class="kb-label">Product Name *</label>
                        <input type="text" id="sm-product" class="ops-input" placeholder="e.g., FluentSupport">
                    </div>
                    <div>
                        <label class="kb-label">Sitemap URL *</label>
                        <div style="display:flex;gap:8px;">
                            <input type="url" id="sm-url" class="ops-input" placeholder="https://docs.fluentsupport.com/sitemap.xml" style="flex:1;">
                            <button type="button" class="ops-btn primary" onclick="importSitemap()">Import</button>
                            <button type="button" class="ops-btn secondary" onclick="closeSitemapForm()">Cancel</button>
                        </div>
                    </div>
                </div>
                <div style="margin-top:8px;font-size: var(--font-size-xs);color:var(--color-text-tertiary);">
                    URL pattern: <code>https://docs.{productname}.com/sitemap.xml</code> — Supports both flat sitemaps and sitemap indexes.
                </div>
            </div>

            <!-- Import progress -->
            <div id="import-progress" style="display:none;padding:16px 20px;background:var(--color-bg-subtle);border-bottom:1px solid var(--color-border);">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="spinner is-active" style="float:none;margin:0;"></span>
                    <span id="import-status-text">Fetching sitemap...</span>
                </div>
            </div>

            <?php if (empty($sitemaps) && empty($docs_by_product)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No documentation sources yet</div>
                    <div class="ops-empty-state-description">Click "Add Product Sitemap" to import docs from a sitemap.xml URL.</div>
                </div>
            <?php else: ?>
                <!-- Registered sitemaps -->
                <?php if (!empty($sitemaps)): ?>
                <div style="padding:16px 20px;">
                    <table class="audit-table" style="font-size: var(--font-size-sm);">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Sitemap URL</th>
                                <th width="80">Docs</th>
                                <th width="120">Last Import</th>
                                <th width="160" style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sitemaps as $product => $info): ?>
                            <tr id="sm-row-<?php echo esc_attr(sanitize_title($product)); ?>">
                                <td style="font-weight:600;"><?php echo esc_html($product); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($info['url']); ?>" target="_blank" style="font-size: var(--font-size-xs);color:var(--color-primary);word-break:break-all;">
                                        <?php echo esc_html($info['url']); ?>
                                    </a>
                                </td>
                                <td style="text-align:center;font-weight:600;">
                                    <?php echo count($docs_by_product[$product] ?? []); ?>
                                </td>
                                <td style="font-size: var(--font-size-xs);color:var(--color-text-tertiary);">
                                    <?php echo !empty($info['last_import']) ? wp_date('M j, g:ia', strtotime($info['last_import'])) : '—'; ?>
                                </td>
                                <td style="text-align:right;">
                                    <button class="ops-btn secondary" style="font-size: var(--font-size-xs);height:26px;padding:0 10px;" onclick="syncSitemap('<?php echo esc_attr($product); ?>', '<?php echo esc_url($info['url']); ?>')">
                                        Sync
                                    </button>
                                    <button class="ops-btn secondary" style="font-size: var(--font-size-xs);height:26px;padding:0 10px;" onclick="toggleProductDocs('<?php echo esc_attr(sanitize_title($product)); ?>')">
                                        View Docs
                                    </button>
                                    <button class="ops-btn secondary" style="font-size: var(--font-size-xs);height:26px;padding:0 8px;color:var(--color-error);" onclick="removeSitemap('<?php echo esc_attr($product); ?>')">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <!-- Expandable doc list -->
                            <tr id="docs-<?php echo esc_attr(sanitize_title($product)); ?>" style="display:none;">
                                <td colspan="5" style="padding:0;">
                                    <?php if (!empty($docs_by_product[$product])): ?>
                                    <div style="max-height:400px;overflow-y:auto;background:var(--color-bg-subtle);border-top:1px solid var(--color-border);">
                                        <table class="audit-table" style="font-size: var(--font-size-xs);margin:0;">
                                            <thead>
                                                <tr>
                                                    <th>Title</th>
                                                    <th>URL</th>
                                                    <th width="100">Category</th>
                                                    <th width="80">Status</th>
                                                    <th width="80" style="text-align:right;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($docs_by_product[$product] as $doc): ?>
                                                <tr id="doc-row-<?php echo $doc->id; ?>">
                                                    <td style="font-weight:500;"><?php echo esc_html($doc->doc_title ?: '(untitled)'); ?></td>
                                                    <td>
                                                        <a href="<?php echo esc_url($doc->doc_url); ?>" target="_blank" style="color:var(--color-primary);word-break:break-all;">
                                                            <?php echo esc_html(strlen($doc->doc_url) > 70 ? substr($doc->doc_url, 0, 70) . '...' : $doc->doc_url); ?>
                                                        </a>
                                                    </td>
                                                    <td><span class="kb-cat-badge"><?php echo esc_html($doc->category ?: '—'); ?></span></td>
                                                    <td><?php echo $this->status_badge($doc->status); ?></td>
                                                    <td style="text-align:right;">
                                                        <button class="ops-btn secondary" style="font-size: var(--font-size-xs);height:22px;padding:0 6px;color:var(--color-error);" onclick="deleteDoc(<?php echo $doc->id; ?>)">Del</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                        <div style="padding:12px 20px;font-size: var(--font-size-sm);color:var(--color-text-tertiary);background:var(--color-bg-subtle);">No docs imported yet. Click "Sync" to import from sitemap.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Products with docs but no sitemap registered -->
                <?php
                $orphan_products = array_diff_key($docs_by_product, $sitemaps);
                if (!empty($orphan_products)):
                ?>
                <div style="padding:0 20px 16px;">
                    <div style="font-size: var(--font-size-xs);color:var(--color-text-tertiary);margin-bottom:8px;font-weight:500;">Manually Added (no sitemap)</div>
                    <?php foreach ($orphan_products as $product => $product_docs): ?>
                    <div class="kb-product-group">
                        <div class="kb-product-header">
                            <strong><?php echo esc_html($product); ?></strong>
                            <span class="kb-doc-count"><?php echo count($product_docs); ?> docs</span>
                        </div>
                        <table class="audit-table" style="font-size: var(--font-size-xs);">
                            <tbody>
                            <?php foreach ($product_docs as $doc): ?>
                                <tr id="doc-row-<?php echo $doc->id; ?>">
                                    <td style="font-weight:500;"><?php echo esc_html($doc->doc_title ?: '(untitled)'); ?></td>
                                    <td><a href="<?php echo esc_url($doc->doc_url); ?>" target="_blank" style="color:var(--color-primary);font-size: var(--font-size-xs);"><?php echo esc_html(strlen($doc->doc_url) > 60 ? substr($doc->doc_url, 0, 60) . '...' : $doc->doc_url); ?></a></td>
                                    <td width="80"><?php echo $this->status_badge($doc->status); ?></td>
                                    <td width="60" style="text-align:right;">
                                        <button class="ops-btn secondary" style="font-size: var(--font-size-xs);height:22px;padding:0 6px;color:var(--color-error);" onclick="deleteDoc(<?php echo $doc->id; ?>)">Del</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Section 2: Gap Analysis -->
        <div class="ops-card">
            <div class="ops-card-header">
                <h3 style="margin:0;">Gap Analysis</h3>
                <span style="font-size: var(--font-size-xs);color:var(--color-text-tertiary);">Cross-references flagged topics against imported docs</span>
            </div>

            <?php if (empty($flagged_topics)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No flagged topics yet</div>
                    <div class="ops-empty-state-description">Topics flagged as FAQ candidates or needing doc updates will appear here once audits detect them.</div>
                </div>
            <?php else: ?>
                <table class="audit-table" style="font-size: var(--font-size-sm);">
                    <thead>
                        <tr>
                            <th>Topic</th>
                            <th width="80">Category</th>
                            <th width="70">Tickets</th>
                            <th width="80">Type</th>
                            <th width="90">Last Seen</th>
                            <th width="100">Doc Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($gap_analysis as $gap): ?>
                        <tr>
                            <td style="font-weight:500;"><?php echo esc_html($gap['topic_label']); ?></td>
                            <td><span class="kb-cat-badge"><?php echo esc_html($gap['category'] ?: '—'); ?></span></td>
                            <td style="text-align:center;font-weight:600;"><?php echo intval($gap['ticket_count']); ?></td>
                            <td>
                                <?php if ($gap['is_faq_candidate']): ?>
                                    <span class="status-badge warning" style="font-size: var(--font-size-xs);">FAQ</span>
                                <?php endif; ?>
                                <?php if ($gap['is_doc_update_needed']): ?>
                                    <span class="status-badge failed" style="font-size: var(--font-size-xs);">Doc Update</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: var(--font-size-xs);color:var(--color-text-tertiary);"><?php echo $gap['last_seen'] ? wp_date('M j', strtotime($gap['last_seen'])) : '—'; ?></td>
                            <td>
                                <?php if ($gap['doc_match']): ?>
                                    <span class="kb-gap-covered" title="<?php echo esc_attr($gap['doc_match']); ?>">Covered</span>
                                <?php elseif ($gap['doc_outdated']): ?>
                                    <span class="kb-gap-outdated" title="<?php echo esc_attr($gap['doc_outdated']); ?>">Outdated</span>
                                <?php else: ?>
                                    <span class="kb-gap-missing">Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Section 3: Product Coverage -->
        <div class="ops-card">
            <div class="ops-card-header">
                <h3 style="margin:0;">Product Coverage</h3>
            </div>

            <?php if (empty($product_coverage)): ?>
                <div class="ops-empty-state">
                    <div class="ops-empty-state-title">No data yet</div>
                    <div class="ops-empty-state-description">Import docs via sitemap and run audits to see product coverage metrics.</div>
                </div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:20px;">
                <?php foreach ($product_coverage as $product => $stats): ?>
                    <div class="kb-coverage-card">
                        <div class="kb-coverage-header">
                            <strong><?php echo esc_html($product); ?></strong>
                        </div>
                        <div class="kb-coverage-stats">
                            <div class="kb-stat">
                                <span class="kb-stat-value"><?php echo $stats['docs']; ?></span>
                                <span class="kb-stat-label">Docs</span>
                            </div>
                            <div class="kb-stat">
                                <span class="kb-stat-value"><?php echo $stats['topics']; ?></span>
                                <span class="kb-stat-label">Topics</span>
                            </div>
                            <div class="kb-stat">
                                <span class="kb-stat-value" style="<?php echo $stats['gaps'] > 0 ? 'color:var(--color-error);' : 'color:var(--color-success);'; ?>"><?php echo $stats['gaps']; ?></span>
                                <span class="kb-stat-label">Gaps</span>
                            </div>
                        </div>
                        <?php if ($stats['topics'] > 0):
                            $covered_pct = round((($stats['topics'] - $stats['gaps']) / $stats['topics']) * 100);
                        ?>
                        <div class="kb-coverage-bar-wrapper">
                            <div class="kb-coverage-bar">
                                <div class="kb-coverage-fill" style="width:<?php echo $covered_pct; ?>%;background:<?php echo $covered_pct >= 80 ? 'var(--color-success)' : ($covered_pct >= 50 ? 'var(--color-warning)' : 'var(--color-error)'); ?>;"></div>
                            </div>
                            <span class="kb-coverage-pct"><?php echo $covered_pct; ?>% covered</span>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->render_scripts($sitemaps); ?>
        <?php
    }

    /**
     * Analyze gaps: match flagged topics against registered docs
     */
    private function analyze_gaps($topics, $docs) {
        $doc_index = [];
        foreach ($docs as $doc) {
            $searchable = strtolower(
                ($doc->doc_title ?: '') . ' ' .
                ($doc->category ?: '') . ' ' .
                ($doc->tags ?: '') . ' ' .
                ($doc->product_name ?: '') . ' ' .
                ($doc->doc_url ?: '')
            );
            $doc_index[] = [
                'searchable' => $searchable,
                'title' => $doc->doc_title ?: $doc->doc_url,
                'status' => $doc->status,
            ];
        }

        $results = [];
        foreach ($topics as $topic) {
            $search_terms = strtolower(
                ($topic->topic_label ?: '') . ' ' .
                ($topic->topic_slug ?: '') . ' ' .
                ($topic->category ?: '')
            );

            $tokens = array_filter(preg_split('/[\s_\-\/]+/', $search_terms));

            $doc_match = null;
            $doc_outdated = null;

            foreach ($doc_index as $di) {
                $match_count = 0;
                foreach ($tokens as $token) {
                    if (strlen($token) >= 3 && strpos($di['searchable'], $token) !== false) {
                        $match_count++;
                    }
                }
                $threshold = count($tokens) <= 2 ? 1 : 2;
                if ($match_count >= $threshold) {
                    if ($di['status'] === 'needs_update') {
                        $doc_outdated = $di['title'];
                    } else {
                        $doc_match = $di['title'];
                    }
                    break;
                }
            }

            $results[] = [
                'topic_label' => $topic->topic_label ?: $topic->topic_slug,
                'category' => $topic->category,
                'ticket_count' => $topic->ticket_count,
                'is_faq_candidate' => $topic->is_faq_candidate,
                'is_doc_update_needed' => $topic->is_doc_update_needed,
                'last_seen' => $topic->last_seen,
                'doc_match' => $doc_match,
                'doc_outdated' => $doc_outdated,
            ];
        }

        return $results;
    }

    /**
     * Get product coverage stats
     */
    private function get_product_coverage($docs_by_product, $flagged_topics) {
        if (empty($docs_by_product) && empty($flagged_topics)) {
            return [];
        }

        $all_products = array_keys($docs_by_product);

        $coverage = [];
        foreach ($all_products as $product) {
            $product_docs = $docs_by_product[$product] ?? [];
            $doc_count = count($product_docs);

            $doc_search = '';
            foreach ($product_docs as $d) {
                $doc_search .= strtolower(($d->doc_title ?: '') . ' ' . ($d->category ?: '') . ' ' . ($d->tags ?: '') . ' ' . ($d->doc_url ?: '') . ' ');
            }

            $topic_count = 0;
            $gap_count = 0;
            foreach ($flagged_topics as $topic) {
                $topic_search = strtolower(($topic->topic_label ?: '') . ' ' . ($topic->category ?: ''));
                $product_lower = strtolower($product);
                $product_tokens = preg_split('/[\s_\-]+/', $product_lower);

                $related = false;
                foreach ($product_tokens as $pt) {
                    if (strlen($pt) >= 3 && strpos($topic_search, $pt) !== false) {
                        $related = true;
                        break;
                    }
                }

                if (!$related) continue;

                $topic_count++;

                $tokens = array_filter(preg_split('/[\s_\-\/]+/', $topic_search));
                $covered = false;
                foreach ($tokens as $token) {
                    if (strlen($token) >= 3 && strpos($doc_search, $token) !== false) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $gap_count++;
                }
            }

            $coverage[$product] = [
                'docs' => $doc_count,
                'topics' => $topic_count,
                'gaps' => $gap_count,
            ];
        }

        return $coverage;
    }

    private function status_badge($status) {
        $map = [
            'active'       => ['Active', 'success'],
            'needs_update' => ['Needs Update', 'warning'],
            'archived'     => ['Archived', 'pending'],
        ];
        $info = $map[$status] ?? ['Unknown', 'pending'];
        return '<span class="status-badge ' . $info[1] . '" style="font-size: var(--font-size-xs);">' . $info[0] . '</span>';
    }

    private function render_styles() {
        ?>
        <style>
            .kb-label {
                display: block; font-size: var(--font-size-xs); font-weight: 500;
                color: var(--color-text-tertiary); margin-bottom: 4px;
            }
            .kb-product-group {
                border: 1px solid var(--color-border); border-radius: var(--radius-md);
                margin-bottom: 8px; overflow: hidden;
            }
            .kb-product-header {
                padding: 10px 16px; background: var(--color-bg-subtle);
                display: flex; align-items: center; justify-content: space-between;
                border-bottom: 1px solid var(--color-border);
            }
            .kb-product-header strong { font-size: var(--font-size-sm); }
            .kb-doc-count {
                font-size: var(--font-size-xs); color: var(--color-text-tertiary);
                background: var(--color-bg); padding: 2px 8px;
                border-radius: var(--radius-pill); border: 1px solid var(--color-border);
            }
            .kb-cat-badge {
                font-size: var(--font-size-xs); padding: 2px 8px; border-radius: var(--radius-pill);
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                color: var(--color-text-secondary);
            }

            /* Gap analysis badges */
            .kb-gap-covered {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: var(--font-size-xs); font-weight: 600;
                background: var(--color-success-bg); color: #065f46;
            }
            .kb-gap-outdated {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: var(--font-size-xs); font-weight: 600;
                background: #fef3c7; color: #92400e;
            }
            .kb-gap-missing {
                display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);
                font-size: var(--font-size-xs); font-weight: 600;
                background: var(--color-error-bg); color: #991b1b;
            }

            /* Coverage cards */
            .kb-coverage-card {
                background: var(--color-bg-subtle); border: 1px solid var(--color-border);
                border-radius: var(--radius-md); padding: 16px;
            }
            .kb-coverage-header { margin-bottom: 12px; }
            .kb-coverage-header strong { font-size: var(--font-size-md); }
            .kb-coverage-stats {
                display: flex; gap: 16px; margin-bottom: 12px;
            }
            .kb-stat { display: flex; flex-direction: column; align-items: center; }
            .kb-stat-value { font-size: var(--font-size-xl); font-weight: 700; line-height: 1.2; }
            .kb-stat-label { font-size: var(--font-size-xs); color: var(--color-text-tertiary); }
            .kb-coverage-bar-wrapper {
                display: flex; align-items: center; gap: 8px;
            }
            .kb-coverage-bar {
                flex: 1; height: 6px; background: var(--color-border);
                border-radius: 3px; overflow: hidden;
            }
            .kb-coverage-fill { height: 100%; border-radius: 3px; }
            .kb-coverage-pct { font-size: var(--font-size-xs); color: var(--color-text-tertiary); white-space: nowrap; }

            /* Import result toast */
            .kb-toast {
                position: fixed; top: 40px; right: 20px; z-index: 99999;
                padding: 12px 20px; border-radius: var(--radius-md);
                font-size: var(--font-size-sm); font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,.15);
                animation: kbSlideIn .3s ease;
            }
            .kb-toast.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
            .kb-toast.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
            @keyframes kbSlideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        </style>
        <?php
    }

    private function render_scripts($sitemaps) {
        ?>
        <script>
        (function($){
            var sitemaps = <?php echo wp_json_encode($sitemaps ?: new \stdClass()); ?>;

            window.openAddSitemap = function() {
                $('#sm-product, #sm-url').val('');
                $('#sitemap-form-panel').slideDown(200);
            };

            window.closeSitemapForm = function() {
                $('#sitemap-form-panel').slideUp(200);
            };

            window.importSitemap = function() {
                var product = $('#sm-product').val().trim();
                var url = $('#sm-url').val().trim();
                if (!product || !url) {
                    showToast('Product name and sitemap URL are required', 'error');
                    return;
                }

                closeSitemapForm();
                $('#import-progress').show();
                $('#import-status-text').text('Importing docs from ' + url + '...');

                $.post(ajaxurl, {
                    action: 'ai_kb_import_sitemap',
                    product_name: product,
                    sitemap_url: url
                }, function(res) {
                    $('#import-progress').hide();
                    if (res.success) {
                        // Save sitemap URL to sitemaps option
                        saveSitemapUrl(product, url, function() {
                            showToast(res.data.message, 'success');
                            setTimeout(function(){ location.reload(); }, 1200);
                        });
                    } else {
                        showToast('Import failed: ' + (res.data || 'Unknown error'), 'error');
                    }
                }).fail(function() {
                    $('#import-progress').hide();
                    showToast('Network error during import', 'error');
                });
            };

            window.syncSitemap = function(product, url) {
                $('#import-progress').show();
                $('#import-status-text').text('Syncing ' + product + '...');

                $.post(ajaxurl, {
                    action: 'ai_kb_sync_sitemap',
                    product_name: product,
                    sitemap_url: url
                }, function(res) {
                    $('#import-progress').hide();
                    if (res.success) {
                        // Update last import timestamp
                        saveSitemapUrl(product, url, function() {
                            showToast(res.data.message, 'success');
                            setTimeout(function(){ location.reload(); }, 1200);
                        });
                    } else {
                        showToast('Sync failed: ' + (res.data || 'Unknown error'), 'error');
                    }
                }).fail(function() {
                    $('#import-progress').hide();
                    showToast('Network error during sync', 'error');
                });
            };

            window.toggleProductDocs = function(slug) {
                var $row = $('#docs-' + slug);
                if ($row.is(':visible')) {
                    $row.hide();
                } else {
                    $row.show();
                }
            };

            window.removeSitemap = function(product) {
                if (!confirm('Remove sitemap for ' + product + '? This won\'t delete the imported docs.')) return;

                // Remove from saved sitemaps
                $.post(ajaxurl, {
                    action: 'ai_kb_remove_sitemap',
                    product_name: product
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    }
                });
            };

            window.deleteDoc = function(id) {
                if (!confirm('Delete this doc entry?')) return;
                $.post(ajaxurl, {action: 'ai_kb_delete_doc', id: id}, function(res) {
                    if (res.success) {
                        $('#doc-row-' + id).fadeOut(300, function(){ $(this).remove(); });
                    } else {
                        showToast('Error: ' + (res.data || 'Unknown error'), 'error');
                    }
                });
            };

            function saveSitemapUrl(product, url, callback) {
                $.post(ajaxurl, {
                    action: 'ai_kb_save_sitemap_url',
                    product_name: product,
                    sitemap_url: url
                }, function() {
                    if (callback) callback();
                });
            }

            function showToast(msg, type) {
                var $toast = $('<div class="kb-toast ' + type + '">' + msg + '</div>');
                $('body').append($toast);
                setTimeout(function(){ $toast.fadeOut(300, function(){ $(this).remove(); }); }, 4000);
            }
        })(jQuery);
        </script>
        <?php
    }
}
