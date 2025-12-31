<?php
/**
 * System Message Page
 * 
 * @package SupportOps\Admin\Pages
 */

namespace SupportOps\Admin\Pages;

use SupportOps\Database\Manager as DatabaseManager;

class SystemMessage {
    
    private $database;
    
    public function __construct(DatabaseManager $database) {
        $this->database = $database;
    }
    
    public function render() {
        if (isset($_POST['save_system_message'])) {
            $message = wp_unslash($_POST['system_message']);
            update_option('ai_audit_system_message', $message);
            update_option('ai_audit_system_message_updated', current_time('mysql'));
            echo '<div class="notice notice-success is-dismissible"><p><strong>✅ System message saved successfully!</strong></p></div>';
        }
        
        $current_message = get_option('ai_audit_system_message', '');
        
        if (empty($current_message)) {
            $current_message = $this->get_default_message();
            update_option('ai_audit_system_message', $current_message);
        }
        
        ?>
        <style>
            /* System Message Page Specific Styles */
            #system-message-form {
                margin-top: 24px;
            }
            
            #system_message_editor {
                width: 100%;
                font-family: var(--font-mono);
                font-size: 13px;
                padding: 16px;
                border: 1px solid var(--color-border);
                border-radius: var(--radius-md);
                line-height: 1.7;
                background: var(--color-bg);
                color: var(--color-text-primary);
                transition: all 0.2s ease;
                resize: vertical;
                min-height: 400px;
            }
            
            #system_message_editor:focus {
                border-color: var(--color-primary);
                box-shadow: 0 0 0 3px var(--color-primary-light);
                outline: none;
            }
            
            #system_message_editor::placeholder {
                color: var(--color-text-tertiary);
            }
            
            .system-message-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 8px;
                flex-wrap: wrap;
                gap: 12px;
            }
            
            .system-message-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid var(--color-border);
            }
            
            .last-saved {
                display: flex;
                align-items: center;
                gap: 6px;
                color: var(--color-text-secondary);
                font-size: 13px;
                margin-left: auto;
            }
            
            .last-saved::before {
                content: "⏱️";
                font-size: 14px;
            }
            
            .tip-box {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                margin-top: 12px;
                padding: 12px 16px;
                background: var(--color-info-bg);
                border: 1px solid var(--color-primary-light);
                border-radius: var(--radius-sm);
                border-left: 3px solid var(--color-primary);
            }
            
            .tip-box code {
                background: rgba(255, 255, 255, 0.8);
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 11px;
                font-family: var(--font-mono);
                color: var(--color-primary);
                font-weight: 600;
            }
            
            .tip-text {
                color: var(--color-text-secondary);
                font-size: 12px;
                line-height: 1.5;
                flex: 1;
            }
            
            #test-form {
                display: flex;
                gap: 12px;
                align-items: flex-end;
                margin-top: 20px;
                padding: 20px;
                background: var(--color-bg-subtle);
                border-radius: var(--radius-md);
                border: 1px solid var(--color-border);
            }
            
            #test-form > div:first-child {
                flex: 1;
                min-width: 200px;
            }
            
            #test-result {
                margin-top: 24px;
                animation: fadeIn 0.3s ease;
            }
            
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            #test-result h4 {
                margin: 0 0 12px 0;
                font-size: 15px;
                font-weight: 600;
                color: var(--color-text-primary);
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            #test-result h4::before {
                content: "📊";
                font-size: 18px;
            }
            
            #test-result-content {
                background: var(--color-bg-subtle);
                padding: 20px;
                border-radius: var(--radius-md);
                border: 1px solid var(--color-border);
                max-height: 600px;
                overflow-y: auto;
                font-size: 13px;
                line-height: 1.6;
            }
            
            #test-result-content p {
                margin: 0;
                color: var(--color-text-secondary);
            }
            
            .test-status {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                border-radius: var(--radius-sm);
                font-size: 12px;
                font-weight: 600;
                margin-bottom: 16px;
            }
            
            .test-status.success {
                background: var(--color-success-bg);
                color: #065f46;
            }
            
            .test-status.error {
                background: var(--color-error-bg);
                color: #991b1b;
            }
            
            .test-status.loading {
                background: var(--color-info-bg);
                color: #1e40af;
            }
            
            .test-detail {
                display: grid;
                grid-template-columns: auto 1fr;
                gap: 12px 20px;
                margin-bottom: 16px;
                padding: 12px;
                background: var(--color-bg);
                border-radius: var(--radius-sm);
                border: 1px solid var(--color-border);
            }
            
            .test-detail-label {
                font-weight: 600;
                color: var(--color-text-secondary);
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .test-detail-value {
                color: var(--color-text-primary);
                font-weight: 500;
            }
            
            .test-detail-value.score {
                font-size: 18px;
                font-weight: 700;
                color: var(--color-primary);
            }
            
            details {
                margin-top: 16px;
            }
            
            details summary {
                cursor: pointer;
                color: var(--color-primary);
                font-weight: 600;
                font-size: 13px;
                padding: 8px 12px;
                background: var(--color-bg);
                border: 1px solid var(--color-border);
                border-radius: var(--radius-sm);
                transition: all 0.2s ease;
                user-select: none;
            }
            
            details summary:hover {
                background: var(--color-bg-subtle);
                border-color: var(--color-primary);
            }
            
            details summary::-webkit-details-marker {
                display: none;
            }
            
            details summary::before {
                content: "▶";
                display: inline-block;
                margin-right: 8px;
                transition: transform 0.2s ease;
                font-size: 10px;
            }
            
            details[open] summary::before {
                transform: rotate(90deg);
            }
            
            details pre {
                background: var(--color-bg);
                padding: 16px;
                border-radius: var(--radius-sm);
                margin-top: 12px;
                border: 1px solid var(--color-border);
                overflow-x: auto;
                max-height: 400px;
                font-family: var(--font-mono);
                font-size: 11px;
                line-height: 1.5;
            }
            
            .char-count {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
                font-size: 11px;
                color: var(--color-text-tertiary);
            }
        </style>
        
        <div class="ops-card">
            <div class="system-message-header">
                <div>
                    <h3 style="margin: 0 0 4px 0;">🤖 AI System Message Editor</h3>
                    <p style="margin: 0; color: var(--color-text-secondary); font-size: 13px;">
                        Configure the prompt that guides AI ticket auditing. This message is sent to the AI model for every audit.
                    </p>
                </div>
            </div>
            
            <form method="post" id="system-message-form">
                <div>
                    <label style="font-weight: 600; font-size: 12px; display: block; margin-bottom: 8px; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">
                        System Message
                    </label>
                    <textarea 
                        name="system_message" 
                        id="system_message_editor" 
                        rows="30" 
                        placeholder="Enter the system message for AI auditing..."
                    ><?php echo esc_textarea($current_message); ?></textarea>
                    <div class="char-count">
                        <span id="char-count">0 characters</span>
                        <span id="line-count">0 lines</span>
                    </div>
                    
                    <div class="tip-box">
                        <span style="font-size: 16px;">💡</span>
                        <div class="tip-text">
                            <strong>Dynamic Variables:</strong> Use <code>{{ $json.clean_transcript_json_safe }}</code> to inject the ticket transcript and <code>{{ $json.shift_context }}</code> to include shift information. These will be replaced with actual data when the audit runs.
                        </div>
                    </div>
                </div>
                
                <div class="system-message-actions">
                    <button name="save_system_message" class="ops-btn primary" type="submit">
                        <span>💾</span> Save System Message
                    </button>
                    <button type="button" class="ops-btn secondary" onclick="resetToDefault()">
                        <span>🔄</span> Reset to Default
                    </button>
                    <span class="last-saved">
                        Last saved: <strong><?php echo esc_html(get_option('ai_audit_system_message_updated', 'Never')); ?></strong>
                    </span>
                </div>
            </form>
        </div>
        
        <div class="ops-card">
            <h3 style="margin: 0 0 4px 0;">🧪 Test System Message</h3>
            <p style="margin: 0 0 0 0; color: var(--color-text-secondary); font-size: 13px;">
                Test your system message with a real ticket to preview how the AI will respond before deploying.
            </p>
            
            <form method="post" id="test-form">
                <div>
                    <label style="font-weight: 600; font-size: 12px; display: block; margin-bottom: 8px; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">
                        Test Ticket ID
                    </label>
                    <input type="number" name="test_ticket_id" id="test_ticket_id" class="ops-input" 
                           placeholder="Enter ticket ID (e.g., 110)" required>
                </div>
                <div>
                    <button type="button" class="ops-btn primary" onclick="testSystemMessage()">
                        <span>🚀</span> Test Message
                    </button>
                </div>
            </form>
            
            <div id="test-result" style="display:none;">
                <h4>Test Result</h4>
                <div id="test-result-content">
                    <p>Loading test result...</p>
                </div>
            </div>
        </div>
        
        <script>
        // Character and line counter
        (function() {
            var editor = document.getElementById('system_message_editor');
            var charCount = document.getElementById('char-count');
            var lineCount = document.getElementById('line-count');
            
            function updateCounts() {
                var text = editor.value;
                var chars = text.length;
                var lines = text.split('\n').length;
                
                charCount.textContent = chars.toLocaleString() + ' characters';
                lineCount.textContent = lines + ' lines';
            }
            
            if (editor && charCount && lineCount) {
                editor.addEventListener('input', updateCounts);
                editor.addEventListener('paste', function() {
                    setTimeout(updateCounts, 10);
                });
                updateCounts();
            }
        })();
        
        function resetToDefault() {
            if (confirm('⚠️ Reset to default system message? This will overwrite your current message.')) {
                var editor = document.getElementById('system_message_editor');
                editor.value = <?php echo json_encode($this->get_default_message()); ?>;
                editor.dispatchEvent(new Event('input'));
            }
        }
        
        function testSystemMessage() {
            var ticketId = document.getElementById('test_ticket_id').value;
            if (!ticketId) {
                alert('Please enter a ticket ID');
                return;
            }
            
            var resultDiv = document.getElementById('test-result');
            var contentDiv = document.getElementById('test-result-content');
            resultDiv.style.display = 'block';
            contentDiv.innerHTML = '<div class="test-status loading">⏳ Triggering test for ticket #' + ticketId + '...</div>';
            
            jQuery.post(ajaxurl, {
                action: 'ai_audit_test_system_message',
                ticket_id: ticketId
            }, function(response) {
                if (!response.success) {
                    contentDiv.innerHTML = '<div class="test-status error">❌ Failed to trigger test: ' + (response.data || 'Unknown error') + '</div>';
                    return;
                }
                
                contentDiv.innerHTML = '<div class="test-status loading">⏳ Test triggered. Waiting for results...</div><div id="polling-status" style="margin-top:12px;font-size:12px;color:var(--color-text-secondary);padding:8px 12px;background:var(--color-bg);border-radius:6px;border:1px solid var(--color-border);"></div>';
                
                var attempts = 0;
                var maxAttempts = 40;
                var pollInterval = setInterval(function() {
                    attempts++;
                    var statusText = 'Polling attempt ' + attempts + '/' + maxAttempts + '...';
                    jQuery('#polling-status').html('<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--color-primary);animation:pulse 1.5s infinite;margin-right:8px;"></span>' + statusText);
                    
                    jQuery.post(ajaxurl, {
                        action: 'ai_audit_check_test_status',
                        ticket_id: ticketId
                    }, function(pollResponse) {
                        if (!pollResponse.success) {
                            clearInterval(pollInterval);
                            contentDiv.innerHTML = '<div class="test-status error">❌ Polling error: ' + (pollResponse.data || 'Unknown error') + '</div>';
                            return;
                        }
                        
                        var data = pollResponse.data;
                        
                        if (data.status === 'pending') {
                            if (attempts >= maxAttempts) {
                                clearInterval(pollInterval);
                                contentDiv.innerHTML = '<div class="test-status error">⏱️ Timeout: Test is taking longer than expected. Please try again later.</div>';
                            }
                            return;
                        }
                        
                        clearInterval(pollInterval);
                        
                        if (data.status === 'success' && data.audit_result) {
                            try {
                                var audit = typeof data.audit_result === 'string' ? JSON.parse(data.audit_result) : data.audit_result;
                                var score = audit.audit_summary?.overall_score || 'N/A';
                                var scoreClass = score !== 'N/A' ? (score >= 80 ? 'success' : score >= 60 ? 'loading' : 'error') : '';
                                
                                var html = '<div class="test-status ' + scoreClass + '">✅ Test Completed Successfully</div>';
                                html += '<div class="test-detail">';
                                html += '<div class="test-detail-label">Ticket ID</div>';
                                html += '<div class="test-detail-value">#' + data.ticket_id + '</div>';
                                html += '<div class="test-detail-label">Overall Score</div>';
                                html += '<div class="test-detail-value score">' + score + '</div>';
                                html += '</div>';
                                html += '<details><summary>View Full Response (JSON)</summary><pre>' + JSON.stringify(audit, null, 2) + '</pre></details>';
                                contentDiv.innerHTML = html;
                            } catch(e) {
                                contentDiv.innerHTML = '<div class="test-status error">❌ Error parsing response: ' + e.message + '</div>';
                            }
                        } else if (data.status === 'failed') {
                            contentDiv.innerHTML = '<div class="test-status error">❌ Test failed: ' + (data.error_message || 'Unknown error') + '</div>';
                        }
                    });
                }, 3000);
            });
        }
        
        // Add pulse animation for loading indicator
        if (!document.getElementById('system-message-styles')) {
            var style = document.createElement('style');
            style.id = 'system-message-styles';
            style.textContent = '@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }';
            document.head.appendChild(style);
        }
        </script>
        <?php
    }
    
    private function get_default_message() {
        return "\nYou are the Head of Support Operations & HR Compliance. Your job is to audit the customer support transcript below for quality, accuracy, and adherence to company policy.\n\n### DATA SOURCE\n{{ \$json.clean_transcript_json_safe }}\n\n{{ \$json.shift_context }}\n\n[Rest of default message truncated for brevity - include full default from original code]";
    }
}