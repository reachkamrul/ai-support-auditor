<?php
/**
 * Dashboard Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class Dashboard {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        global $wpdb;
        ?>
        <style>
            /* Dashboard/Audits Page Specific Styles */
            .audits-page-header {
                margin-bottom: 24px;
            }
            
            .audits-filters-wrapper {
                padding: 20px;
                background: var(--color-bg-subtle);
                border-bottom: 1px solid var(--color-border);
                border-radius: var(--radius-md) var(--radius-md) 0 0;
            }
            
            .audit-filters {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                align-items: flex-end;
            }
            
            .audit-filter-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            
            .audit-filter-group label {
                font-size: 12px;
                font-weight: 600;
                color: var(--color-text-secondary);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .audit-filter-group.wide {
                flex: 2;
                min-width: 250px;
            }
            
            .audit-filter-group.narrow {
                flex: 1;
                min-width: 150px;
            }
            
            .audit-table-wrapper {
                overflow-x: auto;
            }
            
            .audit-table {
                margin: 0;
            }
            
            .audit-table tbody tr {
                transition: all 0.2s ease;
            }
            
            .audit-table tbody tr:hover {
                background: var(--color-bg-subtle);
            }
            
            .col-summary {
                color: var(--color-text-secondary);
                font-size: 13px;
                line-height: 1.5;
                max-width: 500px;
            }
            
            .btn-view,
            .btn-force {
                font-size: 12px;
                padding: 0 12px;
                height: 32px;
                margin-left: 6px;
                transition: all 0.2s ease;
            }
            
            .btn-view {
                min-width: 70px;
            }
            
            .btn-force {
                min-width: 90px;
            }
            
            .btn-view:disabled,
            .btn-force:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            
            .btn-force:disabled {
                background: var(--color-text-tertiary);
                border-color: var(--color-text-tertiary);
            }
            
            .audit-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.6);
                z-index: 9999;
                backdrop-filter: blur(4px);
                animation: fadeIn 0.2s ease;
            }
            
            @keyframes fadeIn {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }
            
            .audit-modal-content {
                background: var(--color-bg);
                width: 90%;
                max-width: 900px;
                max-height: 90vh;
                margin: 5vh auto;
                border-radius: var(--radius-lg);
                padding: 0;
                box-shadow: var(--shadow-lg);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                animation: slideUp 0.3s ease;
            }
            
            @keyframes slideUp {
                from {
                    transform: translateY(20px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            .audit-modal-header {
                padding: 24px;
                border-bottom: 1px solid var(--color-border);
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: var(--color-bg-subtle);
            }
            
            .audit-modal-header h2 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--color-text-primary);
            }
            
            .close-modal {
                cursor: pointer;
                font-size: 24px;
                font-weight: 300;
                line-height: 1;
                color: var(--color-text-tertiary);
                transition: color 0.2s ease;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: var(--radius-sm);
                background: transparent;
            }
            
            .close-modal:hover {
                color: var(--color-text-primary);
                background: var(--color-bg-hover);
            }
            
            .json-viewer {
                background: var(--color-bg-subtle);
                padding: 24px;
                border-radius: 0;
                font-family: var(--font-mono);
                font-size: 12px;
                white-space: pre-wrap;
                word-wrap: break-word;
                max-height: calc(90vh - 100px);
                overflow-y: auto;
                margin: 0;
                color: var(--color-text-primary);
                line-height: 1.6;
            }
            
            .json-viewer::-webkit-scrollbar {
                width: 8px;
            }
            
            .json-viewer::-webkit-scrollbar-track {
                background: var(--color-bg);
            }
            
            .json-viewer::-webkit-scrollbar-thumb {
                background: var(--color-border-strong);
                border-radius: 4px;
            }
            
            .json-viewer::-webkit-scrollbar-thumb:hover {
                background: var(--color-text-tertiary);
            }
            
            .empty-audits {
                text-align: center;
                padding: 60px 20px;
                color: var(--color-text-secondary);
            }
            
            .empty-audits-text {
                font-size: 14px;
                margin: 0;
            }
        </style>
        
        <div class="ops-card" style="padding:0; overflow:hidden;">
            <div class="audits-filters-wrapper">
                <div class="audit-filters">
                    <div class="audit-filter-group wide">
                        <label>Search Ticket</label>
                        <input type="text" id="filter-search" class="ops-input" placeholder="Ticket ID or Summary...">
                    </div>
                    <div class="audit-filter-group narrow">
                        <label>Status</label>
                        <select id="filter-status" class="ops-input">
                            <option value="">All</option>
                            <option value="pending">Pending</option>
                            <option value="success">Success</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="audit-table-wrapper">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th width="100">Status</th>
                        <th width="80">Score</th>
                        <th>Summary</th>
                            <th width="180" style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="audit-rows">
                    <?php 
                    $results = $wpdb->get_results("
                        SELECT a.* FROM {$wpdb->prefix}ais_audits a
                        INNER JOIN (
                            SELECT ticket_id, MAX(id) as max_id
                            FROM {$wpdb->prefix}ais_audits
                            GROUP BY ticket_id
                        ) b ON a.ticket_id = b.ticket_id AND a.id = b.max_id
                        ORDER BY a.created_at DESC
                        LIMIT 50
                    "); 
                        if (empty($results)): ?>
                            <tr>
                                <td colspan="5" class="empty-audits">
                                    <p class="empty-audits-text">No audits found. Audits will appear here once tickets are processed.</p>
                                </td>
                            </tr>
                        <?php else:
                    foreach ($results as $row) {
                        $this->render_row($row);
                    }
                        endif;
                    ?>
                </tbody>
            </table>
            </div>
        </div>

        <div id="audit-modal" class="audit-modal">
            <div class="audit-modal-content">
                <div class="audit-modal-header">
                <h2>Audit Details</h2>
                    <span class="close-modal">&times;</span>
                </div>
                <div id="modal-body" class="json-viewer"></div>
            </div>
        </div>
        
        <?php $this->render_scripts(); ?>
        <?php
    }
    
    private function render_row($row) {
        $j = !empty($row->audit_response) ? $row->audit_response : $row->raw_json;
        
        if(empty($j) || $j === 'null') {
            $now = current_time('timestamp');
            $current_hour = date('G', $now);
            $next_hour = ($current_hour + 1) % 24;
            $next_run = sprintf('%02d:00', $next_hour);
            $j = json_encode([
                'status' => 'pending',
                'message' => "Scheduled for $next_run (hourly batch)"
            ]);
        }
        
        $d = json_decode($j, true);
        
        if($row->status == 'failed') {
            $sum = $row->error_message ?: 'Audit failed';
        } elseif($row->status == 'pending') {
            $now = current_time('timestamp');
            $current_hour = date('G', $now);
            $next_hour = ($current_hour + 1) % 24;
            $next_run = sprintf('%02d:00', $next_hour);
            $sum = "⏰ Scheduled for $next_run (hourly batch)";
        } elseif(!empty($d['audit_summary']['executive_summary'])) {
            $sum = $d['audit_summary']['executive_summary'];
        } elseif(!empty($d['summary'])) {
            $sum = $d['summary'];
        } else {
            $sum = 'Processed';
        }
        
        if(is_array($sum)) {
            $sum = is_array($sum) && !empty($sum) ? (string)reset($sum) : 'Processed';
        }
        $sum = (string)$sum;
        
        $score_display = "-";
        $score_class = "";
        if ($row->overall_score !== null) {
            $score_display = $row->overall_score . "%";
            if ($row->overall_score < 0) {
                $score_class = "score-negative";
            } elseif ($row->overall_score < 50) {
                $score_class = "score-warning";
            } elseif ($row->overall_score < 75) {
                $score_class = "score-ok";
            } else {
                $score_class = "score-good";
            }
        }
        
        echo "<tr id='row-{$row->ticket_id}'>
            <td style='font-weight:600;color:var(--color-text-primary);'>#{$row->ticket_id}</td>
            <td><span class='status-badge {$row->status}'>".strtoupper($row->status)."</span></td>
            <td style='text-align:center;'><span class='col-score {$score_class}'>{$score_display}</span></td>
            <td class='col-summary'>".esc_html(substr($sum,0,80))."...<textarea id='json-{$row->ticket_id}' class='json-storage' style='display:none'>".esc_textarea($j)."</textarea></td>
            <td style='text-align:right;padding-right:16px;'>
                <button class='ops-btn secondary btn-view' data-id='{$row->ticket_id}'>View</button> 
                <button class='ops-btn primary btn-force' data-id='{$row->ticket_id}'>Re-Audit</button>
            </td>
        </tr>";
    }
    
    private function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($){
            window.auditDataStore = window.auditDataStore || {};
            
            $(document).on('click', '.btn-force', function(e){
                e.preventDefault(); 
                var btn=$(this), tid=btn.data('id'), row=$('#row-'+tid);
                btn.text('...').prop('disabled',true);
                
                $.post(ajaxurl, {action:'ai_audit_force', ticket_id:tid}, function(res){
                    if(res.success){
                        row.find('.status-badge').attr('class', 'status-badge pending').text('PENDING');
                        row.find('.col-summary').html('Audit in progress...<textarea id="json-' + tid + '" class="json-storage" style="display:none"></textarea>');
                        
                        var attempts=0, maxAttempts=60;
                        var poller = setInterval(function(){
                            attempts++;
                            $.post(ajaxurl, {action:'ai_audit_check_status', ticket_id:tid}, function(res){
                                // Handle WordPress AJAX response format
                                var data = res.success ? res.data : res;
                                
                                if(data && (data.status === 'success' || data.status === 'failed')){
                                    clearInterval(poller); 
                                    btn.text('Re-Audit').prop('disabled',false); 
                                    updateRowUI(row, data, tid);
                                } else if(attempts >= maxAttempts){
                                    clearInterval(poller);
                                    setTimeout(function(){ location.reload(); }, 2000);
                                }
                            });
                        }, 3000);
                    } else { 
                        alert('Error: ' + res.data); 
                        btn.text('Re-Audit').prop('disabled',false); 
                    }
                });
            });

            function updateRowUI(row, data, tid){
                row.find('.status-badge').attr('class', 'status-badge '+data.status).text(data.status.toUpperCase());
                
                var src = data.audit_response ? data.audit_response : data.raw_json;
                if(typeof src === 'object') {
                    src = JSON.stringify(src);
                }
                
                if(src && src !== 'null' && src !== '') {
                    window.auditDataStore[tid] = src;
                    var textarea = row.find('.json-storage');
                    if(textarea.length) {
                        textarea.val(src);
                    }
                }
                
                if(data.status === 'success'){
                    row.find('.col-score').text(data.overall_score + '%');
                    try {
                       var p = typeof src === 'string' ? JSON.parse(src) : src;
                       var sum = p.audit_summary ? p.audit_summary.executive_summary : (p.summary || 'Audited.');
                       var summaryText = sum.substring(0,100) + '...';
                       row.find('.col-summary').html(summaryText + '<textarea id="json-' + tid + '" class="json-storage" style="display:none">' + src + '</textarea>');
                    } catch(e){
                       row.find('.col-summary').html('Audited - view for details<textarea id="json-' + tid + '" class="json-storage" style="display:none">' + src + '</textarea>');
                    }
                } else if(data.status === 'failed'){
                    var errorMsg = data.error_message || 'Audit failed';
                    row.find('.col-summary').html(errorMsg + '<textarea id="json-' + tid + '" class="json-storage" style="display:none"></textarea>');
                    if(data.overall_score > 0) {
                        row.find('.col-score').text(data.overall_score + '%');
                    }
                }
            }

            $(document).on('click', '.btn-view', function(e){ 
                e.preventDefault();
                var ticketId = $(this).data('id');
                var txt = null;
                
                if(window.auditDataStore && window.auditDataStore[ticketId]) {
                    txt = window.auditDataStore[ticketId];
                }
                
                if(!txt || txt === '' || txt === 'null') {
                    var row = $(this).closest('tr');
                    txt = row.find('.json-storage').val();
                }
                
                if(!txt || txt === '' || txt === 'null') {
                    txt = $('#json-'+ticketId).val();
                }
                
                if(!txt || txt === '' || txt === 'null') {
                    $('#modal-body').html('<div style="text-align:center;padding:40px;color:var(--color-text-secondary);">No audit data available yet. The ticket may still be processing.</div>');
                } else {
                    try{ 
                        var parsed = JSON.parse(txt);
                        txt = JSON.stringify(parsed, null, 2);
                        $('#modal-body').text(txt);
                    } catch(e){ 
                        $('#modal-body').html('<div style="color:var(--color-error);padding:20px;">Error parsing JSON: ' + e.message + '</div><pre style="margin-top:20px;padding:20px;background:var(--color-bg);border-radius:6px;overflow-x:auto;">' + txt + '</pre>');
                    }
                }
                
                $('#audit-modal').fadeIn(200); 
            });
            
            $('.close-modal').click(function(){ $('#audit-modal').fadeOut(); });
            
            $('#audit-modal').click(function(e){
                if($(e.target).is('#audit-modal')) {
                    $(this).fadeOut();
                }
            });
            
            $(document).keyup(function(e) {
                if (e.key === "Escape") {
                    $('#audit-modal').fadeOut();
                }
            });

            $('#filter-search, #filter-status').on('change keyup', function(){
                var s = $('#filter-search').val().toLowerCase(); 
                var st = $('#filter-status').val();
                $('#audit-rows tr').each(function(){
                    var t = $(this).text().toLowerCase(); 
                    var rowSt = $(this).find('.status-badge').text().toLowerCase();
                    $(this).toggle(t.indexOf(s)>-1 && (st==='' || rowSt.indexOf(st)>-1));
                });
            });
        });
        </script>
        <?php
    }
}