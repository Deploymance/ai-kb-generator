<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

// Load addon hooks
require_once __DIR__ . '/hooks.php';

/**
 * AI KB Generator Module Configuration
 */
function ai_kb_generator_config(): array
{
    return [
        'name' => 'AI KB Generator',
        'description' => 'Generate Knowledge Base articles from support tickets using AI. Automatically suggests titles, categories, and tags.',
        'author' => 'Deploymance',
        'language' => 'english',
        'version' => '1.0.0',
        'fields' => [
            'license_key' => [
                'FriendlyName' => 'License Key',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Enter your Deploymance license key. Get one at <a href="https://deploymance.com/addons" target="_blank">deploymance.com</a>',
                'Required' => true,
            ],
            'gemini_api_key' => [
                'FriendlyName' => 'Gemini API Key',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Enter your Google Gemini API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>',
                'Required' => true,
            ],
            'gemini_model' => [
                'FriendlyName' => 'Gemini Model',
                'Type' => 'dropdown',
                'Options' => 'gemini-2.5-flash,gemini-2.5-pro,gemini-2.0-flash,gemini-2.5-flash-lite',
                'Description' => 'Select the Gemini model to use<br>' .
                    '<small><em>Pricing as of January 2026</em><br>' .
                    '<strong>gemini-2.5-flash</strong> (Recommended): $0.30 in / $2.50 out per 1M tokens<br>' .
                    '<strong>gemini-2.5-pro</strong>: $1.25 in / $10.00 out per 1M tokens<br>' .
                    '<strong>gemini-2.0-flash</strong>: $0.10 in / $0.40 out per 1M tokens<br>' .
                    '<strong>gemini-2.5-flash-lite</strong>: $0.10 in / $0.40 out per 1M tokens</small>',
                'Default' => 'gemini-2.5-flash',
            ],
            'retention_days' => [
                'FriendlyName' => 'Ticket Retention Days',
                'Type' => 'text',
                'Size' => '5',
                'Description' => 'Number of days to keep closed tickets in the queue for KB article creation (default: 31)',
                'Default' => '31',
            ],
            'auto_queue_closed' => [
                'FriendlyName' => 'Auto-Queue Closed Tickets',
                'Type' => 'yesno',
                'Description' => 'Automatically add closed tickets to the KB queue for review',
                'Default' => 'yes',
            ],
            'min_replies' => [
                'FriendlyName' => 'Minimum Replies',
                'Type' => 'text',
                'Size' => '5',
                'Description' => 'Minimum number of replies required for a ticket to be queued (default: 2)',
                'Default' => '2',
            ],
            'api_url_override' => [
                'FriendlyName' => 'API URL Override (Dev Only)',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'For development use only. Leave empty for production.',
                'Default' => '',
            ],
        ],
    ];
}

/**
 * Activate Module
 */
function ai_kb_generator_activate(): array
{
    try {
        // Create ticket queue table
        if (!Capsule::schema()->hasTable('mod_ai_kb_queue')) {
            Capsule::schema()->create('mod_ai_kb_queue', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned();
                $table->string('ticket_subject', 255);
                $table->string('ticket_department', 100)->nullable();
                $table->text('ticket_content')->nullable();
                $table->enum('status', ['pending', 'converted', 'dismissed'])->default('pending');
                $table->integer('kb_article_id')->nullable();
                $table->timestamp('ticket_closed_at')->nullable();
                $table->timestamps();
                
                $table->index('ticket_id');
                $table->index('status');
            });
        }

        logActivity('[AI KB Generator] Module activated successfully');

        return [
            'status' => 'success',
            'description' => 'AI KB Generator module activated successfully. Table mod_ai_kb_queue created.',
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Error activating module: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate Module
 */
function ai_kb_generator_deactivate(): array
{
    try {
        logActivity('[AI KB Generator] Module deactivated');

        return [
            'status' => 'success',
            'description' => 'AI KB Generator module deactivated. Database table preserved.',
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Error deactivating module: ' . $e->getMessage(),
        ];
    }
}

/**
 * Upgrade Module
 */
function ai_kb_generator_upgrade($vars): void
{
    $currentVersion = $vars['version'];
    logActivity('[AI KB Generator] Module upgraded to version ' . $currentVersion);
}

/**
 * Admin Area Output
 */
function ai_kb_generator_output($vars): void
{
    require_once __DIR__ . '/lib/Admin/Controllers/AdminController.php';
    
    $controller = new \WHMCS\Module\Addon\AIKBGenerator\Admin\Controllers\AdminController();
    $controller->index($vars);
}

/**
 * Admin Area Sidebar
 */
function ai_kb_generator_sidebar($vars): string
{
    $moduleLink = $vars['modulelink'];
    
    $sidebar = '<div class="panel panel-default">';
    $sidebar .= '<div class="panel-heading"><h3 class="panel-title">AI KB Generator</h3></div>';
    $sidebar .= '<div class="panel-body">';
    $sidebar .= '<p><strong>Version:</strong> ' . $vars['version'] . '</p>';
    
    // Get queue stats
    $pendingCount = Capsule::table('mod_ai_kb_queue')->where('status', 'pending')->count();
    $convertedCount = Capsule::table('mod_ai_kb_queue')->where('status', 'converted')->count();
    
    $sidebar .= '<p><strong>Pending:</strong> <span class="label label-warning">' . $pendingCount . '</span></p>';
    $sidebar .= '<p><strong>Converted:</strong> <span class="label label-success">' . $convertedCount . '</span></p>';
    $sidebar .= '<hr>';
    $sidebar .= '<h4>Quick Links</h4>';
    $sidebar .= '<ul>';
    $sidebar .= '<li><a href="supportkb.php">Manage Knowledge Base</a></li>';
    $sidebar .= '<li><a href="https://ai.google.dev/gemini-api/docs" target="_blank">Gemini API Docs</a></li>';
    $sidebar .= '</ul>';
    $sidebar .= '</div></div>';
    
    return $sidebar;
}
