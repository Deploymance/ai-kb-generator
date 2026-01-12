<?php

namespace WHMCS\Module\Addon\AIKBGenerator\Admin\Controllers;

use Exception;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\AIKBGenerator\Services\AIService;

/**
 * Admin Controller for AI KB Generator
 */
class AdminController
{
    /**
     * Display the main admin page with ticket queue
     */
    public function index($vars): void
    {
        $moduleLink = $vars['modulelink'];
        $retentionDays = (int) ($vars['retention_days'] ?? 31);
        
        // Clean up old entries
        $this->cleanupOldEntries($retentionDays);
        
        // Handle dismiss/delete actions
        if (isset($_POST['dismiss_id'])) {
            $this->dismissTicket((int) $_POST['dismiss_id']);
        }
        
        if (isset($_POST['delete_id'])) {
            $this->deleteFromQueue((int) $_POST['delete_id']);
        }
        
        // Get queue entries
        $queueEntries = Capsule::table('mod_ai_kb_queue')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        
        // Get stats
        $stats = [
            'pending' => Capsule::table('mod_ai_kb_queue')->where('status', 'pending')->count(),
            'converted' => Capsule::table('mod_ai_kb_queue')->where('status', 'converted')->count(),
            'dismissed' => Capsule::table('mod_ai_kb_queue')->where('status', 'dismissed')->count(),
        ];
        
        // Get KB categories for modal
        $kbCategories = Capsule::table('tblknowledgebasecats')
            ->select('id', 'name', 'parentid')
            ->orderBy('name')
            ->get();
        
        $categoriesJson = json_encode($kbCategories->toArray());
        
        // Get KB articles for replace feature (with category from links table)
        $kbArticles = Capsule::table('tblknowledgebase as kb')
            ->leftJoin('tblknowledgebaselinks as kbl', 'kb.id', '=', 'kbl.articleid')
            ->select('kb.id', 'kb.title', 'kbl.categoryid')
            ->orderBy('kb.title')
            ->get();
        
        $articlesJson = json_encode($kbArticles->toArray());
        
        echo <<<HTML
<style>
.kb-stats { display: flex; gap: 20px; margin-bottom: 20px; }
.kb-stat { background: #f8f9fa; padding: 15px 25px; border-radius: 8px; text-align: center; }
.kb-stat .number { font-size: 2em; font-weight: bold; }
.kb-stat .label { color: #666; }
.queue-table { margin-top: 20px; }
.status-pending { color: #f0ad4e; }
.status-converted { color: #5cb85c; }
.status-dismissed { color: #999; }
</style>

<h2><i class="fa fa-book"></i> AI KB Generator - Ticket Queue</h2>

<div class="kb-stats">
    <div class="kb-stat">
        <div class="number text-warning">{$stats['pending']}</div>
        <div class="label">Pending Review</div>
    </div>
    <div class="kb-stat">
        <div class="number text-success">{$stats['converted']}</div>
        <div class="label">Converted to KB</div>
    </div>
    <div class="kb-stat">
        <div class="number text-muted">{$stats['dismissed']}</div>
        <div class="label">Dismissed</div>
    </div>
</div>

<div class="alert alert-info">
    <i class="fa fa-info-circle"></i> 
    Closed tickets with at least {$vars['min_replies']} replies are automatically added here. 
    Tickets are retained for {$retentionDays} days.
</div>

<table class="table table-striped queue-table">
    <thead>
        <tr>
            <th>Ticket #</th>
            <th>Subject</th>
            <th>Department</th>
            <th>Status</th>
            <th>Closed</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
HTML;

        foreach ($queueEntries as $entry) {
            $statusClass = 'status-' . $entry->status;
            $statusLabel = ucfirst($entry->status);
            $closedAt = $entry->ticket_closed_at ? date('M j, Y', strtotime($entry->ticket_closed_at)) : '-';
            
            $actionsHtml = '';
            if ($entry->status === 'pending') {
                $actionsHtml = <<<ACTIONS
<button class="btn btn-sm btn-primary kb-generate-btn" data-ticket-id="{$entry->ticket_id}" data-queue-id="{$entry->id}">
    <i class="fa fa-magic"></i> Generate KB
</button>
<form method="post" style="display:inline;">
    <input type="hidden" name="dismiss_id" value="{$entry->id}">
    <button type="submit" class="btn btn-sm btn-default" onclick="return confirm('Dismiss this ticket from the queue?');">
        <i class="fa fa-times"></i>
    </button>
</form>
ACTIONS;
            } elseif ($entry->status === 'converted' && $entry->kb_article_id) {
                $actionsHtml = '<a href="supportkb.php?action=edit&id=' . $entry->kb_article_id . '" class="btn btn-sm btn-success" target="_blank"><i class="fa fa-external-link"></i> View KB</a>';
            }
            
            echo <<<ROW
        <tr>
            <td><a href="supporttickets.php?action=view&id={$entry->ticket_id}" target="_blank">#{$entry->ticket_id}</a></td>
            <td>{$entry->ticket_subject}</td>
            <td>{$entry->ticket_department}</td>
            <td><span class="{$statusClass}">{$statusLabel}</span></td>
            <td>{$closedAt}</td>
            <td>{$actionsHtml}</td>
        </tr>
ROW;
        }

        if ($queueEntries->isEmpty()) {
            echo '<tr><td colspan="6" class="text-center text-muted">No tickets in queue</td></tr>';
        }

        echo <<<HTML
    </tbody>
</table>

<!-- KB Generator Modal -->
<div class="modal fade" id="kbQueueModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-book"></i> Generate KB Article</h4>
            </div>
            <div class="modal-body">
                <div id="queueKbLoading" style="text-align:center;padding:40px;">
                    <i class="fa fa-spinner fa-spin fa-3x"></i><br><br>
                    <span>Analyzing ticket and generating KB article...</span>
                </div>
                <div id="queueKbForm" style="display:none;">
                    <div class="form-group">
                        <label><strong>Article Title:</strong></label>
                        <input type="text" class="form-control" id="queueKbTitle">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><strong>Category:</strong> <span id="queueKbCategorySuggestion" class="text-muted small"></span>
                                <a href="#" id="queueNewCategoryToggle" class="small"><i class="fa fa-plus"></i> New</a></label>
                                <select class="form-control" id="queueKbCategory"></select>
                                <div id="queueNewCategoryForm" style="display:none;margin-top:10px;padding:10px;background:#f9f9f9;border-radius:4px;">
                                    <input type="text" class="form-control input-sm" id="queueNewCategoryName" placeholder="New category name...">
                                    <select class="form-control input-sm" id="queueNewCategoryParent" style="margin-top:5px;"></select>
                                    <button type="button" class="btn btn-sm btn-success" id="queueCreateCategoryBtn" style="margin-top:5px;">
                                    <i class="fa fa-check"></i> Create</button>
                                    <button type="button" class="btn btn-sm btn-default" id="queueCancelCategoryBtn" style="margin-top:5px;">Cancel</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><strong>Replace Existing:</strong></label>
                                <select class="form-control" id="queueKbReplace"></select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><strong>Tags:</strong></label>
                        <input type="text" class="form-control" id="queueKbTags">
                    </div>
                    <div class="form-group">
                        <label><strong>Content:</strong></label>
                        <textarea class="form-control" id="queueKbContent" rows="12"></textarea>
                    </div>
                    <div class="checkbox">
                        <label><input type="checkbox" id="queueKbPublished" checked> Publish immediately</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <input type="hidden" id="queueTicketId">
                <input type="hidden" id="queueEntryId">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="queueKbSaveBtn" style="display:none;">
                    <i class="fa fa-save"></i> Save KB Article
                </button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function(\$) {
    var kbCategories = {$categoriesJson};
    var kbArticles = {$articlesJson};
    var moduleLink = '{$moduleLink}';
    
    function buildCategoryOptions(selectedId) {
        var html = '<option value="">-- Select Category --</option>';
        kbCategories.forEach(function(cat) {
            var prefix = cat.parentid > 0 ? '-- ' : '';
            var selected = (selectedId && cat.id == selectedId) ? ' selected' : '';
            html += '<option value="' + cat.id + '"' + selected + '>' + prefix + cat.name + '</option>';
        });
        return html;
    }
    
    function buildArticleOptions(categoryId) {
        var html = '<option value="">-- Create New Article --</option>';
        kbArticles.forEach(function(art) {
            if (!categoryId || art.categoryid == categoryId) {
                html += '<option value="' + art.id + '">' + art.title + '</option>';
            }
        });
        return html;
    }
    
    \$('#queueKbCategory').html(buildCategoryOptions());
    \$('#queueKbReplace').html(buildArticleOptions());
    \$('#queueNewCategoryParent').html('<option value="0">-- No Parent (Top Level) --</option>' + buildCategoryOptions());
    
    // Initialize Select2 on modal show
    \$('#kbQueueModal').on('shown.bs.modal', function() {
        \$('#queueKbCategory').select2({
            dropdownParent: \$('#kbQueueModal'),
            placeholder: '-- Select Category --',
            allowClear: true,
            width: '100%'
        });
        \$('#queueKbReplace').select2({
            dropdownParent: \$('#kbQueueModal'),
            placeholder: '-- Create New Article --',
            allowClear: true,
            width: '100%'
        });
    });
    
    \$('#queueKbCategory').on('change', function() {
        \$('#queueKbReplace').html(buildArticleOptions(\$(this).val()));
        \$('#queueKbReplace').trigger('change.select2');
    });
    
    // Toggle new category form
    \$('#queueNewCategoryToggle').on('click', function(e) {
        e.preventDefault();
        \$('#queueNewCategoryForm').slideToggle();
        \$('#queueNewCategoryName').val('').focus();
    });
    
    \$('#queueCancelCategoryBtn').on('click', function() {
        \$('#queueNewCategoryForm').slideUp();
        \$('#queueNewCategoryName').val('');
    });
    
    // Create new category from queue modal
    \$('#queueCreateCategoryBtn').on('click', function() {
        var btn = \$(this);
        var name = \$('#queueNewCategoryName').val().trim();
        var parentId = \$('#queueNewCategoryParent').val() || 0;
        
        if (!name) {
            alert('Please enter a category name.');
            \$('#queueNewCategoryName').focus();
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
        
        \$.ajax({
            url: moduleLink + '&action=create_category',
            method: 'POST',
            data: { name: name, parent_id: parentId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var newCat = { id: response.category_id, name: name, parentid: parseInt(parentId) };
                    kbCategories.push(newCat);
                    
                    \$('#queueKbCategory').html(buildCategoryOptions(response.category_id));
                    \$('#queueKbCategory').val(response.category_id).trigger('change.select2');
                    \$('#queueNewCategoryParent').html('<option value="0">-- No Parent (Top Level) --</option>' + buildCategoryOptions());
                    
                    \$('#queueNewCategoryForm').slideUp();
                    \$('#queueNewCategoryName').val('');
                } else {
                    alert('Error: ' + (response.message || 'Failed to create category'));
                }
                btn.prop('disabled', false).html('<i class="fa fa-check"></i> Create');
            },
            error: function() {
                alert('Failed to create category.');
                btn.prop('disabled', false).html('<i class="fa fa-check"></i> Create');
            }
        });
    });
    
    // Generate KB from queue
    \$(document).on('click', '.kb-generate-btn', function() {
        var ticketId = \$(this).data('ticket-id');
        var queueId = \$(this).data('queue-id');
        
        \$('#queueTicketId').val(ticketId);
        \$('#queueEntryId').val(queueId);
        \$('#queueKbLoading').show();
        \$('#queueKbForm').hide();
        \$('#queueKbSaveBtn').hide();
        
        \$('#kbQueueModal').modal('show');
        
        \$.ajax({
            url: moduleLink + '&action=generate_kb',
            method: 'POST',
            data: { ticket_id: ticketId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    \$('#queueKbTitle').val(response.title);
                    \$('#queueKbContent').val(response.content);
                    \$('#queueKbTags').val(response.tags);
                    
                    if (response.suggested_category_id) {
                        \$('#queueKbCategory').val(response.suggested_category_id).trigger('change.select2');
                        \$('#queueKbCategorySuggestion').text('(AI suggested)');
                        \$('#queueKbReplace').html(buildArticleOptions(response.suggested_category_id));
                        \$('#queueKbReplace').trigger('change.select2');
                    }
                    
                    \$('#queueKbLoading').hide();
                    \$('#queueKbForm').show();
                    \$('#queueKbSaveBtn').show();
                } else {
                    alert('Error: ' + (response.message || 'Failed to generate'));
                    \$('#kbQueueModal').modal('hide');
                }
            },
            error: function() {
                alert('Failed to generate KB article.');
                \$('#kbQueueModal').modal('hide');
            }
        });
    });
    
    // Save KB from queue
    \$('#queueKbSaveBtn').on('click', function() {
        var btn = \$(this);
        var ticketId = \$('#queueTicketId').val();
        var queueId = \$('#queueEntryId').val();
        
        if (!\$('#queueKbTitle').val().trim()) {
            alert('Please enter a title.');
            return;
        }
        if (!\$('#queueKbCategory').val()) {
            alert('Please select a category.');
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        
        \$.ajax({
            url: moduleLink + '&action=save_kb',
            method: 'POST',
            data: {
                ticket_id: ticketId,
                queue_id: queueId,
                title: \$('#queueKbTitle').val(),
                content: \$('#queueKbContent').val(),
                category_id: \$('#queueKbCategory').val(),
                tags: \$('#queueKbTags').val(),
                replace_id: \$('#queueKbReplace').val(),
                published: \$('#queueKbPublished').is(':checked') ? 1 : 0
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    \$('#kbQueueModal').modal('hide');
                    location.reload();
                } else {
                    alert('Error: ' + (response.message || 'Failed to save'));
                    btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save KB Article');
                }
            },
            error: function() {
                alert('Failed to save KB article.');
                btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save KB Article');
            }
        });
    });
});
</script>
HTML;
    }

    /**
     * Generate KB article via AJAX
     */
    public function generateKB(): void
    {
        ob_clean();
        header('Content-Type: application/json');

        try {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);

            if ($ticketId <= 0) {
                throw new Exception('Invalid ticket ID');
            }

            logActivity('[AI KB Generator] Generating KB for ticket #' . $ticketId);

            $addonConfig = Capsule::table('tbladdonmodules')
                ->where('module', 'ai_kb_generator')
                ->pluck('value', 'setting')
                ->toArray();

            $aiService = new AIService($addonConfig);
            $result = $aiService->generateKBArticle($ticketId);

            echo json_encode([
                'success' => true,
                'title' => $result['title'],
                'content' => $result['content'],
                'tags' => $result['tags'],
                'suggested_category_id' => $result['suggested_category_id'],
                'suggested_category_name' => $result['suggested_category_name'],
            ]);

        } catch (Exception $e) {
            logActivity('[AI KB Generator] Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        exit;
    }

    /**
     * Save KB article via AJAX
     */
    public function saveKB(): void
    {
        ob_clean();
        header('Content-Type: application/json');

        try {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $tags = trim($_POST['tags'] ?? '');
            $replaceId = (int) ($_POST['replace_id'] ?? 0);
            $published = (int) ($_POST['published'] ?? 1);

            if (empty($title)) {
                throw new Exception('Title is required');
            }

            if ($categoryId <= 0) {
                throw new Exception('Category is required');
            }

            $articleId = null;
            
            // Ensure content is not double-escaped
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($replaceId > 0) {
                // Update existing article
                Capsule::table('tblknowledgebase')
                    ->where('id', $replaceId)
                    ->update([
                        'title' => $title,
                        'article' => $content,
                        'private' => $published ? 0 : 1,
                    ]);
                $articleId = $replaceId;
                
                // Update category link
                Capsule::table('tblknowledgebaselinks')
                    ->where('articleid', $articleId)
                    ->delete();
                Capsule::table('tblknowledgebaselinks')->insert([
                    'categoryid' => $categoryId,
                    'articleid' => $articleId,
                ]);
                
                logActivity('[AI KB Generator] Updated KB article #' . $articleId);
            } else {
                // Create new article
                $articleId = Capsule::table('tblknowledgebase')->insertGetId([
                    'title' => $title,
                    'article' => $content,
                    'views' => 0,
                    'useful' => 0,
                    'votes' => 0,
                    'private' => $published ? 0 : 1,
                    'order' => 0,
                    'parentid' => 0,
                    'language' => '',
                ]);
                
                // Create category link
                Capsule::table('tblknowledgebaselinks')->insert([
                    'categoryid' => $categoryId,
                    'articleid' => $articleId,
                ]);
                
                logActivity('[AI KB Generator] Created KB article #' . $articleId);
            }

            // Save tags
            if (!empty($tags) && $articleId) {
                // Delete existing tags
                Capsule::table('tblknowledgebasetags')
                    ->where('articleid', $articleId)
                    ->delete();

                // Insert new tags
                $tagArray = array_map('trim', explode(',', $tags));
                foreach ($tagArray as $tag) {
                    if (!empty($tag)) {
                        Capsule::table('tblknowledgebasetags')->insert([
                            'articleid' => $articleId,
                            'tag' => $tag,
                        ]);
                    }
                }
            }

            // Update queue entry - either by queue ID or by ticket ID
            if ($queueId > 0) {
                Capsule::table('mod_ai_kb_queue')
                    ->where('id', $queueId)
                    ->update([
                        'status' => 'converted',
                        'kb_article_id' => $articleId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } elseif ($ticketId > 0) {
                // Also update if ticket exists in queue (created from in-ticket button)
                Capsule::table('mod_ai_kb_queue')
                    ->where('ticket_id', $ticketId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'converted',
                        'kb_article_id' => $articleId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            echo json_encode([
                'success' => true,
                'article_id' => $articleId,
                'message' => 'KB article saved successfully',
            ]);

        } catch (Exception $e) {
            logActivity('[AI KB Generator] Save error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        exit;
    }

    /**
     * Dismiss ticket from queue
     */
    private function dismissTicket(int $queueId): void
    {
        Capsule::table('mod_ai_kb_queue')
            ->where('id', $queueId)
            ->update([
                'status' => 'dismissed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        logActivity('[AI KB Generator] Dismissed queue entry #' . $queueId);
    }

    /**
     * Delete entry from queue
     */
    private function deleteFromQueue(int $queueId): void
    {
        Capsule::table('mod_ai_kb_queue')
            ->where('id', $queueId)
            ->delete();
        logActivity('[AI KB Generator] Deleted queue entry #' . $queueId);
    }

    /**
     * Clean up old entries
     */
    private function cleanupOldEntries(int $retentionDays): void
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        $deleted = Capsule::table('mod_ai_kb_queue')
            ->where('status', '!=', 'converted')
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        if ($deleted > 0) {
            logActivity('[AI KB Generator] Cleaned up ' . $deleted . ' old queue entries');
        }
    }
}
