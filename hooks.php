<?php
/**
 * AI KB Generator - Addon Hooks
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Hook: AdminAreaHeadOutput
 * Inject KB Generator button into ticket view
 */
add_hook('AdminAreaHeadOutput', 1, function($vars) {
    // Only run on ticket view pages
    if (basename($_SERVER['PHP_SELF']) !== 'supporttickets.php') {
        return '';
    }
    
    if (!isset($_GET['action']) || $_GET['action'] !== 'view') {
        return '';
    }
    
    if (!isset($_GET['id'])) {
        return '';
    }

    $ticketId = (int) $_GET['id'];
    
    // Get addon configuration
    $addonSettings = Capsule::table('tbladdonmodules')
        ->where('module', 'ai_kb_generator')
        ->pluck('value', 'setting')
        ->toArray();
    
    // Check if module has settings and license key
    if (empty($addonSettings) || empty($addonSettings['license_key'] ?? '') || empty($addonSettings['gemini_api_key'] ?? '')) {
        return '';
    }
    
    $moduleLink = 'addonmodules.php?module=ai_kb_generator';
    
    // Get KB categories for the dropdown
    $kbCategories = Capsule::table('tblknowledgebasecats')
        ->select('id', 'name', 'parentid')
        ->orderBy('name')
        ->get()
        ->toArray();
    
    $categoriesJson = json_encode($kbCategories);
    
    // Get existing KB articles for "replace" feature (with category from links table)
    $kbArticles = Capsule::table('tblknowledgebase as kb')
        ->leftJoin('tblknowledgebaselinks as kbl', 'kb.id', '=', 'kbl.articleid')
        ->select('kb.id', 'kb.title', 'kbl.categoryid')
        ->orderBy('kb.title')
        ->get()
        ->toArray();
    
    $articlesJson = json_encode($kbArticles);
    
    return <<<HTML
<script type="text/javascript">
jQuery(document).ready(function(\$) {
    console.log('[AI KB Generator] Initializing on ticket #{$ticketId}');
    
    var kbCategories = {$categoriesJson};
    var kbArticles = {$articlesJson};
    
    // Add KB Generator button to ticket actions area
    setTimeout(function() {
        var actionsArea = \$('.ticket-reply-actions, .btn-group').first();
        
        // Create KB button
        var kbButton = \$('<button>', {
            type: 'button',
            id: 'aiKbGeneratorBtn',
            class: 'btn btn-info btn-sm',
            title: 'Generate KB Article from Ticket',
            'data-toggle': 'tooltip',
            html: '<i class="fa fa-book"></i> Create KB Article'
        });
        
        // Insert button
        if (actionsArea.length) {
            actionsArea.append(' ');
            actionsArea.append(kbButton);
        } else {
            // Alternative placement
            var replyBtn = \$('button:contains("Post Reply"), a:contains("Post Reply")').first();
            if (replyBtn.length) {
                replyBtn.after(' ').after(kbButton);
            }
        }
        
        kbButton.tooltip();
        console.log('[AI KB Generator] Button added');
    }, 500);
    
    // Build category options HTML
    function buildCategoryOptions(selectedId) {
        var html = '<option value="">-- Select Category --</option>';
        kbCategories.forEach(function(cat) {
            var prefix = cat.parentid > 0 ? '-- ' : '';
            var selected = (selectedId && cat.id == selectedId) ? ' selected' : '';
            html += '<option value="' + cat.id + '"' + selected + '>' + prefix + cat.name + '</option>';
        });
        return html;
    }
    
    // Build article options HTML for replacement
    function buildArticleOptions(categoryId) {
        var html = '<option value="">-- Create New Article --</option>';
        kbArticles.forEach(function(art) {
            if (!categoryId || art.categoryid == categoryId) {
                html += '<option value="' + art.id + '">' + art.title + '</option>';
            }
        });
        return html;
    }
    
    // Add modal HTML
    if (!\$('#aiKbModal').length) {
        var modalHtml = '<div class="modal fade" id="aiKbModal" tabindex="-1" role="dialog">' +
            '<div class="modal-dialog modal-lg" role="document">' +
            '<div class="modal-content">' +
            '<div class="modal-header">' +
            '<button type="button" class="close" data-dismiss="modal">&times;</button>' +
            '<h4 class="modal-title"><i class="fa fa-book"></i> Generate KB Article from Ticket</h4>' +
            '</div>' +
            '<div class="modal-body">' +
            '<div id="kbGeneratorLoading" style="text-align:center;padding:40px;display:none;">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i><br><br>' +
            '<span id="kbLoadingText">Analyzing ticket and generating KB article...</span>' +
            '</div>' +
            '<div id="kbGeneratorForm">' +
            '<div class="alert alert-info">' +
            '<i class="fa fa-info-circle"></i> AI will analyze this ticket and generate a knowledge base article. You can edit all fields before saving.' +
            '</div>' +
            '<div class="form-group">' +
            '<label><strong>Article Title:</strong></label>' +
            '<input type="text" class="form-control" id="kbTitle" placeholder="AI will suggest a title...">' +
            '</div>' +
            '<div class="row">' +
            '<div class="col-md-6">' +
            '<div class="form-group">' +
            '<label><strong>Category:</strong> <span id="kbCategorySuggestion" class="text-muted small"></span> ' +
            '<a href="#" id="kbNewCategoryToggle" class="small"><i class="fa fa-plus"></i> New</a></label>' +
            '<select class="form-control" id="kbCategory">' + buildCategoryOptions() + '</select>' +
            '<div id="kbNewCategoryForm" style="display:none;margin-top:10px;padding:10px;background:#f9f9f9;border-radius:4px;">' +
            '<input type="text" class="form-control input-sm" id="kbNewCategoryName" placeholder="New category name...">' +
            '<select class="form-control input-sm" id="kbNewCategoryParent" style="margin-top:5px;">' +
            '<option value="0">-- No Parent (Top Level) --</option>' + buildCategoryOptions() + '</select>' +
            '<button type="button" class="btn btn-sm btn-success" id="kbCreateCategoryBtn" style="margin-top:5px;">' +
            '<i class="fa fa-check"></i> Create</button> ' +
            '<button type="button" class="btn btn-sm btn-default" id="kbCancelCategoryBtn" style="margin-top:5px;">' +
            'Cancel</button>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="col-md-6">' +
            '<div class="form-group">' +
            '<label><strong>Replace Existing Article:</strong></label>' +
            '<select class="form-control" id="kbReplaceArticle">' + buildArticleOptions() + '</select>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="form-group">' +
            '<label><strong>Tags:</strong> <small class="text-muted">(comma separated)</small></label>' +
            '<input type="text" class="form-control" id="kbTags" placeholder="AI will suggest tags...">' +
            '</div>' +
            '<div class="form-group">' +
            '<label><strong>Article Content:</strong></label>' +
            '<textarea class="form-control" id="kbContent" rows="12" placeholder="AI will generate content..."></textarea>' +
            '</div>' +
            '<div class="checkbox">' +
            '<label><input type="checkbox" id="kbPublished" checked> Publish immediately</label>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>' +
            '<button type="button" class="btn btn-primary" id="kbGenerateBtn">' +
            '<i class="fa fa-magic"></i> Generate with AI</button>' +
            '<button type="button" class="btn btn-success" id="kbSaveBtn" style="display:none;">' +
            '<i class="fa fa-save"></i> Save KB Article</button>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>';
        \$('body').append(modalHtml);
        
        // Initialize Select2 on modal show
        \$('#aiKbModal').on('shown.bs.modal', function() {
            \$('#kbCategory').select2({
                dropdownParent: \$('#aiKbModal'),
                placeholder: '-- Select Category --',
                allowClear: true,
                width: '100%'
            });
            \$('#kbReplaceArticle').select2({
                dropdownParent: \$('#aiKbModal'),
                placeholder: '-- Create New Article --',
                allowClear: true,
                width: '100%'
            });
        });
        
        // Update article dropdown when category changes
        \$(document).on('change', '#kbCategory', function() {
            \$('#kbReplaceArticle').html(buildArticleOptions(\$(this).val()));
            // Refresh Select2
            \$('#kbReplaceArticle').trigger('change.select2');
        });
        
        // Toggle new category form
        \$(document).on('click', '#kbNewCategoryToggle', function(e) {
            e.preventDefault();
            \$('#kbNewCategoryForm').slideToggle();
            \$('#kbNewCategoryName').val('').focus();
        });
        
        \$(document).on('click', '#kbCancelCategoryBtn', function() {
            \$('#kbNewCategoryForm').slideUp();
            \$('#kbNewCategoryName').val('');
        });
        
        // Create new category
        \$(document).on('click', '#kbCreateCategoryBtn', function() {
            var btn = \$(this);
            var name = \$('#kbNewCategoryName').val().trim();
            var parentId = \$('#kbNewCategoryParent').val() || 0;
            
            if (!name) {
                alert('Please enter a category name.');
                \$('#kbNewCategoryName').focus();
                return;
            }
            
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            
            \$.ajax({
                url: '{$moduleLink}&action=create_category',
                method: 'POST',
                data: { name: name, parent_id: parentId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Add new category to arrays and dropdowns
                        var newCat = { id: response.category_id, name: name, parentid: parseInt(parentId) };
                        kbCategories.push(newCat);
                        
                        // Rebuild and refresh dropdowns
                        \$('#kbCategory').html(buildCategoryOptions(response.category_id));
                        \$('#kbCategory').val(response.category_id).trigger('change.select2');
                        \$('#kbNewCategoryParent').html('<option value="0">-- No Parent (Top Level) --</option>' + buildCategoryOptions());
                        
                        \$('#kbNewCategoryForm').slideUp();
                        \$('#kbNewCategoryName').val('');
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
    }
    
    // Show modal when button clicked
    \$(document).on('click', '#aiKbGeneratorBtn', function() {
        // Reset form
        \$('#kbTitle').val('');
        \$('#kbContent').val('');
        \$('#kbTags').val('');
        \$('#kbCategory').val('');
        \$('#kbReplaceArticle').val('');
        \$('#kbCategorySuggestion').text('');
        \$('#kbPublished').prop('checked', true);
        \$('#kbGeneratorForm').show();
        \$('#kbGeneratorLoading').hide();
        \$('#kbGenerateBtn').show();
        \$('#kbSaveBtn').hide();
        
        \$('#aiKbModal').modal('show');
    });
    
    // Generate KB article with AI
    \$(document).on('click', '#kbGenerateBtn', function() {
        var btn = \$(this);
        
        \$('#kbGeneratorForm').hide();
        \$('#kbGeneratorLoading').show();
        \$('#kbLoadingText').text('Analyzing ticket and generating KB article...');
        btn.prop('disabled', true);
        
        \$.ajax({
            url: '{$moduleLink}&action=generate_kb',
            method: 'POST',
            data: { ticket_id: {$ticketId} },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    \$('#kbTitle').val(response.title);
                    \$('#kbContent').val(response.content);
                    \$('#kbTags').val(response.tags);
                    
                    if (response.suggested_category_id) {
                        \$('#kbCategory').val(response.suggested_category_id).trigger('change.select2');
                        \$('#kbCategorySuggestion').text('(AI suggested)');
                        \$('#kbReplaceArticle').html(buildArticleOptions(response.suggested_category_id));
                        \$('#kbReplaceArticle').trigger('change.select2');
                    }
                    
                    \$('#kbGeneratorLoading').hide();
                    \$('#kbGeneratorForm').show();
                    \$('#kbGenerateBtn').hide();
                    \$('#kbSaveBtn').show();
                    
                    console.log('[AI KB Generator] Article generated successfully');
                } else {
                    alert('Error: ' + (response.message || 'Failed to generate KB article'));
                    \$('#kbGeneratorLoading').hide();
                    \$('#kbGeneratorForm').show();
                }
                btn.prop('disabled', false);
            },
            error: function(xhr, status, error) {
                console.error('[AI KB Generator] AJAX Error:', status, error);
                alert('Failed to generate KB article. Please try again.');
                \$('#kbGeneratorLoading').hide();
                \$('#kbGeneratorForm').show();
                btn.prop('disabled', false);
            }
        });
    });
    
    // Save KB article
    \$(document).on('click', '#kbSaveBtn', function() {
        var btn = \$(this);
        var title = \$('#kbTitle').val().trim();
        var content = \$('#kbContent').val().trim();
        var category = \$('#kbCategory').val();
        var tags = \$('#kbTags').val().trim();
        var replaceId = \$('#kbReplaceArticle').val();
        var published = \$('#kbPublished').is(':checked') ? 1 : 0;
        
        if (!title) {
            alert('Please enter an article title.');
            \$('#kbTitle').focus();
            return;
        }
        
        if (!category) {
            alert('Please select a category.');
            \$('#kbCategory').focus();
            return;
        }
        
        if (!content) {
            alert('Please enter article content.');
            \$('#kbContent').focus();
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        
        \$.ajax({
            url: '{$moduleLink}&action=save_kb',
            method: 'POST',
            data: {
                ticket_id: {$ticketId},
                title: title,
                content: content,
                category_id: category,
                tags: tags,
                replace_id: replaceId,
                published: published
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    \$('#aiKbModal').modal('hide');
                    alert('KB Article saved successfully!' + (response.article_id ? ' Article ID: ' + response.article_id : ''));
                    
                    // Optionally open the KB article
                    if (response.article_id && confirm('Would you like to view the KB article?')) {
                        window.open('supportkb.php?action=edit&id=' + response.article_id, '_blank');
                    }
                } else {
                    alert('Error: ' + (response.message || 'Failed to save KB article'));
                }
                btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save KB Article');
            },
            error: function(xhr, status, error) {
                console.error('[AI KB Generator] Save Error:', status, error);
                alert('Failed to save KB article. Please try again.');
                btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save KB Article');
            }
        });
    });
});
</script>
HTML;
});

/**
 * Hook: TicketClose
 * Auto-queue closed tickets for KB review
 */
add_hook('TicketClose', 1, function($vars) {
    $ticketId = $vars['ticketid'];
    
    // Get addon settings
    $addonSettings = Capsule::table('tbladdonmodules')
        ->where('module', 'ai_kb_generator')
        ->pluck('value', 'setting')
        ->toArray();
    
    // Check if auto-queue is enabled
    if (($addonSettings['auto_queue_closed'] ?? 'yes') !== 'on') {
        return;
    }
    
    $minReplies = (int) ($addonSettings['min_replies'] ?? 2);
    
    try {
        // Get ticket details
        $ticket = Capsule::table('tbltickets')
            ->where('id', $ticketId)
            ->first();
        
        if (!$ticket) {
            return;
        }
        
        // Count replies
        $replyCount = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->count();
        
        if ($replyCount < $minReplies) {
            logActivity('[AI KB Generator] Ticket #' . $ticketId . ' skipped - only ' . $replyCount . ' replies (min: ' . $minReplies . ')');
            return;
        }
        
        // Check if already in queue
        $exists = Capsule::table('mod_ai_kb_queue')
            ->where('ticket_id', $ticketId)
            ->exists();
        
        if ($exists) {
            return;
        }
        
        // Get department name
        $department = Capsule::table('tblticketdepartments')
            ->where('id', $ticket->did)
            ->value('name');
        
        // Add to queue
        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_ai_kb_queue')->insert([
            'ticket_id' => $ticketId,
            'ticket_subject' => $ticket->title,
            'ticket_department' => $department,
            'status' => 'pending',
            'ticket_closed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        
        logActivity('[AI KB Generator] Ticket #' . $ticketId . ' added to KB queue');
        
    } catch (Exception $e) {
        logActivity('[AI KB Generator] Error queuing ticket #' . $ticketId . ': ' . $e->getMessage());
    }
});

/**
 * Hook: AdminAreaPage
 * Handle AJAX requests
 */
add_hook('AdminAreaPage', 1, function($vars) {
    if (!isset($_GET['module']) || $_GET['module'] !== 'ai_kb_generator') {
        return;
    }
    
    $action = $_GET['action'] ?? '';
    
    if ($action === 'generate_kb' || $action === 'save_kb') {
        require_once __DIR__ . '/lib/Services/AIService.php';
        require_once __DIR__ . '/lib/Admin/Controllers/AdminController.php';
        
        $controller = new \WHMCS\Module\Addon\AIKBGenerator\Admin\Controllers\AdminController();
        
        if ($action === 'generate_kb') {
            $controller->generateKB();
        } elseif ($action === 'save_kb') {
            $controller->saveKB();
        }
    }
    
    // Handle create category AJAX request
    if ($action === 'create_category') {
        ob_clean();
        header('Content-Type: application/json');
        
        try {
            $name = trim($_POST['name'] ?? '');
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            
            if (empty($name)) {
                throw new Exception('Category name is required');
            }
            
            // Create the category
            $categoryId = Capsule::table('tblknowledgebasecats')->insertGetId([
                'name' => $name,
                'description' => '',
                'parentid' => $parentId,
                'hidden' => 0,
            ]);
            
            logActivity('[AI KB Generator] Created KB category: ' . $name . ' (ID: ' . $categoryId . ')');
            
            echo json_encode([
                'success' => true,
                'category_id' => $categoryId,
                'message' => 'Category created successfully',
            ]);
        } catch (Exception $e) {
            logActivity('[AI KB Generator] Error creating category: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
        
        exit;
    }
});
