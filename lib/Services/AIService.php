<?php

namespace WHMCS\Module\Addon\AIKBGenerator\Services;

use Exception;
use WHMCS\Database\Capsule;
use WHMCS\Support\Ticket;

/**
 * AI Service for KB Article Generation
 * 
 * All requests are routed through the Deploymance API for license validation.
 */
class AIService
{
    private string $licenseKey;
    private string $geminiApiKey;
    private string $apiEndpoint;
    private array $settings;
    private string $domain;

    public function __construct(array $addonConfig = [])
    {
        $this->licenseKey = $addonConfig['license_key'] ?? '';
        
        if (empty($this->licenseKey)) {
            throw new Exception('Deploymance License Key is not configured.');
        }

        $this->geminiApiKey = $addonConfig['gemini_api_key'] ?? '';
        
        if (empty($this->geminiApiKey)) {
            throw new Exception('Gemini API Key is not configured.');
        }

        // Set API endpoint
        $apiUrlOverride = trim($addonConfig['api_url_override'] ?? '');
        if (!empty($apiUrlOverride)) {
            $this->apiEndpoint = rtrim($apiUrlOverride, '/') . '/api/addon/kb-generator';
            logActivity('[AI KB Generator] Using custom API URL: ' . $this->apiEndpoint);
        } else {
            $this->apiEndpoint = 'https://deploymance.com/api/addon/kb-generator';
        }

        $this->domain = $this->getCurrentDomain();
        
        $this->settings = [
            'model' => $addonConfig['gemini_model'] ?? 'gemini-2.5-flash',
        ];
    }

    /**
     * Get the current WHMCS domain
     */
    private function getCurrentDomain(): string
    {
        if (function_exists('WHMCS\Config\Setting::getValue')) {
            try {
                $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
                if ($systemUrl) {
                    $parsed = parse_url($systemUrl);
                    if (isset($parsed['host'])) {
                        return $parsed['host'];
                    }
                }
            } catch (Exception $e) {
                // Fall through
            }
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }

        if (!empty($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }

        throw new Exception('Could not determine WHMCS domain.');
    }

    /**
     * Generate KB article from ticket
     */
    public function generateKBArticle(int $ticketId): array
    {
        logActivity('[AI KB Generator] Generating KB article for ticket #' . $ticketId);

        // Get ticket with replies
        $ticket = Capsule::table('tbltickets')
            ->where('id', $ticketId)
            ->first();

        if (!$ticket) {
            throw new Exception('Ticket not found');
        }

        // Get ticket replies
        $replies = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->orderBy('date', 'asc')
            ->get();

        // Get KB categories for suggestion
        $kbCategories = Capsule::table('tblknowledgebasecats')
            ->select('id', 'name', 'description')
            ->get()
            ->toArray();

        // Build context
        $context = $this->buildContext($ticket, $replies, $kbCategories);

        // Call API
        $response = $this->callDeploymanceApi($context);

        return [
            'title' => $response['title'] ?? 'Untitled Article',
            'content' => $response['content'] ?? '',
            'tags' => $response['tags'] ?? '',
            'suggested_category_id' => $response['suggested_category_id'] ?? null,
            'suggested_category_name' => $response['suggested_category_name'] ?? null,
        ];
    }

    /**
     * Build context for AI
     */
    private function buildContext($ticket, $replies, array $kbCategories): array
    {
        // Build conversation
        $conversation = "TICKET SUBJECT: " . $ticket->title . "\n\n";
        $conversation .= "ORIGINAL MESSAGE:\n" . strip_tags($ticket->message) . "\n\n";

        foreach ($replies as $reply) {
            $from = $reply->admin ? 'STAFF' : 'CUSTOMER';
            $conversation .= "[$from]:\n" . strip_tags($reply->message) . "\n\n";
        }

        // Build category list
        $categoryList = [];
        foreach ($kbCategories as $cat) {
            $categoryList[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'description' => $cat->description ?? '',
            ];
        }

        return [
            'ticket_id' => $ticket->id,
            'ticket_subject' => $ticket->title,
            'conversation' => $conversation,
            'kb_categories' => $categoryList,
        ];
    }

    /**
     * Call Deploymance API
     */
    private function callDeploymanceApi(array $context): array
    {
        $requestData = [
            'licenseKey' => $this->licenseKey,
            'domain' => $this->domain,
            'geminiApiKey' => $this->geminiApiKey,
            'geminiModel' => $this->settings['model'],
            'action' => 'generate_kb_article',
            'context' => $context,
        ];

        logActivity('[AI KB Generator] Calling Deploymance API');

        $ch = curl_init($this->apiEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        logActivity('[AI KB Generator] Response received. HTTP Code: ' . $httpCode);

        if ($error) {
            logActivity('[AI KB Generator] Connection error: ' . $error);
            throw new Exception('Could not connect to Deploymance server. Please check your internet connection.');
        }

        if (empty($response) || $httpCode === 0) {
            throw new Exception('Could not reach Deploymance server. Please try again later.');
        }

        if (str_starts_with(trim($response), '<') || str_starts_with(trim($response), '<!')) {
            throw new Exception('Could not connect to Deploymance server. Please try again later.');
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logActivity('[AI KB Generator] JSON error: ' . json_last_error_msg());
            throw new Exception('Unexpected response from Deploymance server.');
        }

        if ($httpCode === 401) {
            $errorMsg = $decoded['error'] ?? 'License validation failed';
            throw new Exception('License Error: ' . $errorMsg);
        }

        if ($httpCode !== 200) {
            $errorMsg = $decoded['error'] ?? 'Unknown error';
            throw new Exception('API Error: ' . $errorMsg);
        }

        if (!isset($decoded['success']) || !$decoded['success']) {
            throw new Exception($decoded['error'] ?? 'API returned unsuccessful response');
        }

        return $decoded['data'] ?? $decoded;
    }
}
