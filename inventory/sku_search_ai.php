<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

// *****************************************************************
// ***** INCLUDES (Ensure paths are correct) *************************
// *****************************************************************
require_once __DIR__ . '/dbinv.php';       // Provides $conn and global constants (OPENAI_API_KEY)
require_once __DIR__ . '/utils/helpers.php'; // Provides ai_http, json_read, json_write, etc.

// =================================================================
// ===== AI SEARCH CONFIGURATION ===================================
// =================================================================
const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
// Define a reasonable limit for autocomplete suggestions
const MAX_AUTOCOMPLETE_RESULTS = 10;
// =================================================================

header('Content-Type: application/json; charset=utf-8');

// --- 1. Connection/Error Check ---
if (!isset($conn) || !$conn || $conn->connect_error) {
     http_response_code(500);
     echo json_encode(['ok'=>false,'error'=>'Database connection failed.']);
     exit;
}

// Ensure MySQLi throws exceptions for controlled error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // --- 2. Input Retrieval ---
    $raw_query = $_GET['q'] ?? '';
    $query = trim($raw_query);

    if (empty($query)) {
        echo json_encode(['ok'=>true,'results'=>[]]); // Empty query returns empty results
        exit;
    }
    
    // --- 3. AI Translation (Helper Function) ---
    // Note: We define the callOpenAI function here as it's the core logic for this file.
    // If you prefer to move it to helpers.php, you can.
    function callOpenAI(string $userQuery): ?array {
        if (!function_exists('ai_http') || !defined('OPENAI_API_KEY')) {
            error_log("AI Helper: Required function or key not defined.");
            return null;
        }

        // System prompt slightly adjusted to focus purely on SKU extraction for this search file
        $systemPrompt = "You are a precise inventory search assistant. Your task is to extract the main item identifier (SKU number) from the user's query. Ignore any location information. Your output MUST be a valid JSON object with the key 'sku_num' (string, or null if not found).";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userQuery]
        ];

        $payload = [
            'model' => 'gpt-3.5-turbo-1106', 
            'messages' => $messages,
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ];
        
        $headers = ["Authorization: Bearer " . OPENAI_API_KEY];
        
        [$http_code, $raw_response, $curl_error, $request_body, $decoded] = ai_http(OPENAI_API_URL, $headers, $payload);

        if ($http_code !== 200 || $curl_error) {
            error_log("OpenAI API Error. Code: {$http_code}, Response: {$raw_response}");
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        
        if ($content) {
            $ai_data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['sku_num' => trim($ai_data['sku_num'] ?? '') ?: null];
            }
        }
        return null;
    }

    // --- 4. Determine Search Term ---
    $final_search_term = $query;
    
    // Only call AI if the input looks fuzzy (not a standard SKU format)
    if (strlen($query) < 6 || strpos($query, ' ') !== false || !ctype_alnum(str_replace(['-', '_'], '', $query))) {
        
        $ai_result = callOpenAI($query);
        
        if ($ai_result && $ai_result['sku_num']) {
            $final_search_term = $ai_result['sku_num'];
        }
    }

    // --- 5. Database Search (Autocomplete/Fuzzy Match) ---
    // Use the determined search term for fuzzy matching on SKU and Description.
    $like_term = '%' . $final_search_term . '%'; 

    $sql = "
        SELECT 
            s.sku_num, 
            s.`desc`, 
            s.`status`,
            SUM(i.quantity) AS on_hand
        FROM sku s
        LEFT JOIN inventory i ON i.sku_id = s.id
        WHERE s.sku_num LIKE ?
           OR s.`desc` LIKE ?
        GROUP BY s.id 
        ORDER BY s.sku_num 
        LIMIT ?
    ";
    
    $stmt = $conn->prepare($sql);
    $limit = MAX_AUTOCOMPLETE_RESULTS;
    $stmt->bind_param('ssi', $like_term, $like_term, $limit); 
    $stmt->execute();

    // Use the helper for reliable fetching
    $results = stmt_fetch_all_assoc($stmt);
    $stmt->close();
    
    // --- 6. Response ---
    echo json_encode([
        'ok' => true,
        'query' => $query,
        'search_term' => $final_search_term,
        'results' => $results,
    ]);

} catch (\mysqli_sql_exception $e) {
    http_response_code(500);
    error_log("[sku_search_ai.php] SQL Error: " . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Database search failed.']);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("[sku_search_ai.php] Server Error: " . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Internal server error.']);
}