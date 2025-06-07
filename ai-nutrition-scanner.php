<?php
/**
 * Plugin Name: AI Nutrition Scanner
 * Description: Mobile-first nutrition label scanner using Replicate OCR and OpenAI GPT-4 for WordPress.
 * Version: 1.1.5
 * Author: Your Name
 */

/**
 * Version 1.1.5 highlights:
 * - Added section annotations for maintainability
 * - Bumped asset versions
 */

if (!defined('ABSPATH')) exit;

define('ANP_DEBUG', true);
define('ANP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANP_PLUGIN_URL', plugin_dir_url(__FILE__));

// ----- Section: Activation -----
/**
 * Create scan history table on plugin activation
 */
function anp_activate_plugin() {
    global $wpdb;
    $table_name   = $wpdb->prefix . 'nutrition_scans';
    $charset_coll = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table_name} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        image_url text NOT NULL,
        summary_json longtext NOT NULL,
        tile_flags text NOT NULL,
        scan_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset_coll};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = dbDelta($sql);
    if (ANP_DEBUG) {
        error_log('[ANP] Activation: dbDelta result: ' . var_export($result, true));
    }
}
register_activation_hook(__FILE__, 'anp_activate_plugin');

// ----- Section: Assets -----
/**
 * Enqueue front-end scripts and styles
 */
function anp_enqueue_assets() {
    if (ANP_DEBUG) error_log('[ANP] Enqueue assets');
    wp_enqueue_style('anp-styles', ANP_PLUGIN_URL . 'assets/css/anp-styles.css', [], '1.1.5');
    wp_enqueue_script('anp-scripts', ANP_PLUGIN_URL . 'assets/js/anp-scripts.js', ['jquery'], '1.1.5', true);

    wp_localize_script('anp-scripts', 'anp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('anp_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'anp_enqueue_assets');

// ----- Section: Shortcode -----
/**
 * Display scanner UI via shortcode
 */
function anp_scanner_shortcode() {
    if (ANP_DEBUG) error_log('[ANP] Render scanner shortcode');
    ob_start();
    ?>
    <div id="anp-app">
        <button id="anp-scan-btn" class="anp-btn">📸 Scan Label</button>
        <div id="anp-loading" class="anp-loading" style="display:none;">Analyzing...</div>
        <div id="anp-tiles" class="anp-tiles"></div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('anp_scanner', 'anp_scanner_shortcode');

// ----- Section: AJAX Handler -----
/**
 * AJAX handler: Process scan
 */
function anp_handle_scan() {
    check_ajax_referer('anp_nonce', 'nonce');
    if (ANP_DEBUG) error_log('[ANP] AJAX scan request');

    $image_data = isset($_POST['image']) ? $_POST['image'] : '';
    if (empty($image_data)) {
        wp_send_json_error('No image data');
    }

    // Save image
    $upload = anp_save_image($image_data);
    if (is_wp_error($upload)) {
        wp_send_json_error($upload->get_error_message());
    }
    $image_url = $upload['url'];
    if (ANP_DEBUG) error_log('[ANP] Image saved: ' . $image_url);

    // OCR via Replicate
    $ocr_text = anp_replicate_ocr($image_url);
    if ($ocr_text === false) {
        wp_send_json_error('OCR failed');
    }
    if (ANP_DEBUG) error_log('[ANP] OCR text: ' . $ocr_text);

    // 3) Clean only numeric formatting (generic)
    $cleaned = anp_gpt_clean_only($ocr_text);
    if (ANP_DEBUG) error_log('[ANP] After OCR-clean: ' . $cleaned);

    // 4) Final extraction of last numbers
    $analysis = anp_openai_extract_after_clean($cleaned);
    if (ANP_DEBUG) error_log('[ANP] Full analysis: ' . print_r($analysis, true));

    /*
    // Parse nutrition fields
    $fields = anp_parse_nutrition($ocr_text);
    if (ANP_DEBUG) error_log('[ANP] Parsed fields: ' . print_r($fields, true));


    // Clean OCR via OpenAI
    $clean_text = anp_clean_ocr_text($ocr_text);
    if (ANP_DEBUG) error_log('[ANP] OCR cleaned text: '.print_r($clean_text,true));

    // Get expiry Date
    //$expiry = anp_extract_expiry_date($clean_text);
    //$analysis['expiry_date'] = $expiry;

    // GPT analysis (pass only raw text; GPT will extract nutrition facts)
    $analysis = anp_openai_analyze($fields, $clean_text);

    if (ANP_DEBUG) error_log('[ANP] Analysis: '.print_r($analysis,true));
    */

    // 3) Single GPT call: clean + parse + summarize
   // $analysis = anp_openai_full_analysis($ocr_text);
   // if (ANP_DEBUG) error_log('[ANP] Full analysis: ' . print_r($analysis, true));


    // Save to DB
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'nutrition_scans',
        [
            'user_id'      => get_current_user_id(),
            'image_url'    => esc_url_raw($image_url),
            'summary_json' => wp_json_encode($analysis),
            'tile_flags'   => implode(',', $analysis['flags']),
        ]
    );

    wp_send_json_success($analysis);
}
add_action('wp_ajax_anp_scan', 'anp_handle_scan');
add_action('wp_ajax_nopriv_anp_scan', 'anp_handle_scan');

// ----- Section: Image Saving -----
/**
 * Save base64 image
 */
function anp_save_image($base64) {
    $prefix = substr($base64, 0, 30);
    if (ANP_DEBUG) error_log("[ANP] save_image prefix: {$prefix}...");
    $pattern = '/^data:image\/(png|jpe?g|gif);base64,(.+)$/i';
    if (!preg_match($pattern, $base64, $m)) {
        return new WP_Error('invalid_image', 'Invalid image format');
    }
    $ext = strtolower($m[1]);

    // Calculate expected byte size without decoding the entire string
    $b64        = $m[2];
    $data_len   = strlen($b64);
    $padding    = substr_count(substr($b64, -2), '=');
    $byte_size  = ($data_len * 3 / 4) - $padding;
    $max_bytes  = 5 * 1024 * 1024; // 5 MB
    if ($byte_size > $max_bytes) {
        return new WP_Error('file_too_large', 'Image exceeds 5 MB limit');
    }

    $data = base64_decode($b64);
    if ($data === false) {
        return new WP_Error('decode_error', 'Base64 decode failed');
    }
    $filename = 'scan_' . time() . ".{$ext}";
    $upload   = wp_upload_bits($filename, null, $data);
    if (!empty($upload['error'])) {
        return new WP_Error('upload_error', $upload['error']);
    }
    return $upload;
}

// ----- Section: OCR via Replicate -----
/**
 * Perform OCR via Replicate with polling
 */
function anp_replicate_ocr($url) {
    $api_key = get_option('anp_replicate_api_key');
    $model   = 'abiruyt/text-extract-ocr:a524caeaa23495bc9edc805ab08ab5fe943afd3febed884a4f3747aa32e9cd61';
    $payload = ['version' => $model, 'input' => ['image' => $url]];
    if (ANP_DEBUG) error_log('[ANP] OCR payload: ' . json_encode($payload));

    // Start prediction
    $response = wp_remote_post('https://api.replicate.com/v1/predictions', [
        'headers' => [
            'Authorization' => 'Token ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) {
        if (ANP_DEBUG) error_log('[ANP] OCR init error: ' . $response->get_error_message());
        return false;
    }
    $pred = json_decode(wp_remote_retrieve_body($response), true);
    if (ANP_DEBUG) error_log('[ANP] OCR init response: ' . print_r($pred, true));
    $poll_url = $pred['urls']['get'] ?? '';
    if (empty($poll_url)) {
        if (ANP_DEBUG) error_log('[ANP] Missing poll URL');
        return false;
    }

    // Poll for output
    for ($i = 0; $i < 10; $i++) {
        sleep(2);
        $status = wp_remote_get($poll_url, [
            'headers' => ['Authorization' => 'Token ' . $api_key],
            'timeout' => 60,
        ]);
        if (is_wp_error($status)) {
            if (ANP_DEBUG) error_log('[ANP] OCR poll error: ' . $status->get_error_message());
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($status), true);
        if (ANP_DEBUG) error_log('[ANP] OCR poll response: ' . print_r($data, true));
        if (!empty($data['error'])) {
            if (ANP_DEBUG) error_log('[ANP] OCR processing error: ' . $data['error']);
            return false;
        }
        if (!empty($data['output'])) {
            return $data['output'];
        }
    }
    if (ANP_DEBUG) error_log('[ANP] OCR polling timed out');
    return false;
}


// ----- Section: OpenAI Helpers -----
/**
 * Parse nutrition fields from OCR text
 */


 function anp_parse_nutrition($text) {
    $prompt = "
    Extract the third column (Prepared) from this nutrition table. 
    Return JSON { energy_kcal:…, fat_g:…, saturates_g:…, carbohydrate_g:…, sugars_g:…, fiber_g:…, protein_g:…, salt_g:… }.

    Nutrition Table:
    {$text}
    ";

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . get_option('anp_openai_api_key'),
          'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
          'model' => 'gpt-4o-mini',
          'messages' => [
            ['role'=>'system','content'=>'You are a nutrition parser.'],
            ['role'=>'user','content'=>trim($prompt)],
          ],
          'max_tokens'=>200
        ]),
    ]);

    if (is_wp_error($res)) return [];

    $body = json_decode(wp_remote_retrieve_body($res), true);
    $content = $body['choices'][0]['message']['content'] ?? '';
    $json = json_decode($content, true);
    return is_array($json) ? $json : [];
}


/**
 * Call OpenAI for analysis
 */
function anp_openai_analyze($fields, $raw) {
    $api_key = get_option('anp_openai_api_key');

    // ➕ Build a prompt that relies only on the raw OCR text,
    //    instructing GPT to extract nutrition details itself.
    $prompt  = "You are a nutrition expert. Below is text from a product label (OCR output):\n\n";
    $prompt .= trim($raw) . "\n\n";
    $prompt .= "Please do the following:\n";
    $prompt .= "1) Find any expiry/best‐before date. Return null if none.\n";
    $prompt .= "2) Extract all numeric nutrition facts (energy in kcal, fat in g, saturates in g, carbohydrates in g, sugars in g, fiber in g, protein in g, salt in g, etc.).\n";
    $prompt .= "3) Identify any red‐flag nutrients (e.g., \"High Sugar\" if sugar per 100g > threshold). You decide reasonable cutoffs.\n";
    $prompt .= "4) Return exactly a JSON object with these keys:\n";
    $prompt .= "   {\n";
    $prompt .= "     \"expiry_date\": \"YYYY-MM-DD\" or null,\n";
    $prompt .= "     \"flags\": [ /* array of strings, e.g. [\"High Sugar\"] */ ],\n";
    $prompt .= "     \"nutrition\": { /* e.g. \"energy_kcal\": 365, \"fat_g\": 5.19, … */ },\n";
    $prompt .= "     \"summary\": \"A brief summary of this product's nutrition profile.\",\n";
    $prompt .= "     \"alternative\": \"A concise alternative name or tagline.\"\n";
    $prompt .= "   }\n";
    $prompt .= "Do not include any additional keys or explanation—just the JSON.\n";

    if (ANP_DEBUG) {
        error_log('[ANP] GPT prompt: ' . $prompt);
    }

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'model'    => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a nutrition assistant.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'max_tokens' => 500,
        ]),
        'timeout' => 60,
    ]);

    if (is_wp_error($res)) {
        return [
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => new stdClass(),
            'summary'     => 'Error calling GPT',
            'alternative' => '',
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (ANP_DEBUG) {
        error_log('[ANP] GPT resp: ' . print_r($body, true));
    }

    $content = $body['choices'][0]['message']['content'] ?? '';
    $j       = json_decode($content, true);

    if (is_array($j)) {
        // GPT returned valid JSON with our expected keys
        return $j;
    }

    // Fallback if GPT did not return valid JSON
    return [
        'expiry_date' => null,
        'flags'       => [],
        'nutrition'   => new stdClass(),
        'summary'     => $content,
        'alternative' => '',
    ];
}


/**
 * Clean up raw OCR text using OpenAI GPT-4.
 *
 * @param string $ocr_text The raw text output from the OCR model.
 * @return string The cleaned, human-readable text. If the OpenAI call fails, returns the original $ocr_text.
 */
function anp_clean_ocr_text($ocr_text) {
    // Get the OpenAI API key from WP options
    $api_key = get_option('anp_openai_api_key');
    if (empty($api_key)) {
        // No API key set; skip cleaning and return raw text
        if (defined('ANP_DEBUG') && ANP_DEBUG) {
            error_log('[ANP] No OpenAI API key found; skipping OCR-clean.');
        }
        return $ocr_text;
    }

    // Build a prompt instructing GPT to correct common OCR mistakes
   // $prompt  = "You are a helpful assistant that corrects OCR errors and restores readability.\n\n";
   // $prompt .= "Below is text extracted from a product label by an OCR engine. It may contain typos, stray characters, missing line breaks, or misrecognized units.\n\n";
   /// $prompt .= "OCR Output:\n";
   // $prompt .= trim($ocr_text) . "\n\n";
   // $prompt .= "Please return a cleaned, properly formatted version of that text, fixing any obvious OCR errors (e.g., 'k)/' → 'kcal', 'O' vs '0'), restoring line breaks, and ensuring units and numbers are correct. Only return the corrected text—do not add any commentary.\n";

  //  $prompt = "You are a helpful assistant that corrects OCR errors and restores readability.\n\n";
  //  $prompt = "Below is text extracted from a product label by an OCR engine. It contains numeric nutrition values (even if they look garbled). Please do NOT remove or discard any numeric characters. Instead, fix obvious OCR artifacts such as "k)/" → "kcal", "kaal" → "kcal", mis‐recognized digits ("O" vs "0"), and restore proper line breaks around numbers and units.\n\n";
  //  $prompt = "OCR Output:\n" . trim($ocr_text) . "\n\n";
  //  $prompt = "Return the corrected, human‐readable label text, preserving all numeric values (even if they appear with stray slashes or parentheses). Only output the corrected text, no commentary.\n";

    // Use a heredoc so we can include quotes freely without escaping
    $prompt = <<<EOD
            You are a helpful assistant that corrects OCR errors and restores readability.
            Below is text extracted from a product label by an OCR engine. It contains numeric nutrition values (even if they look garbled). Do NOT remove or discard any numeric characters. Instead, fix obvious OCR artifacts around them—e.g., 'k)/' → 'kcal', 'kaal' → 'kcal', 'O' → '0'—and restore proper line breaks around numbers and units.
            OCR Output:{$ocr_text}
            Return the corrected, human-readable version of that text exactly as it should appear on a label, preserving all numeric values. Do not add any commentary or extra keys.
            EOD;


    if (defined('ANP_DEBUG') && ANP_DEBUG) {
        error_log('[ANP] OCR-clean prompt: ' . $prompt);
    }

    // Prepare the API request to OpenAI
    $request_body = [
        'model'    => 'gpt-3.5-turbo', // 'gpt-4',  // You can also use 'gpt-3.5-turbo' if cost/time is a concern
        'messages' => [
            [
                'role'    => 'system',
                'content' => 'You are a helpful assistant that corrects OCR errors.'
            ],
            [
                'role'    => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 500,    // Adjust as needed to cover long OCR passages
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($request_body),
        'timeout' => 60,
    ]);

    // If the HTTP request fails, bail out and return raw OCR text
    if (is_wp_error($response)) {
        if (defined('ANP_DEBUG') && ANP_DEBUG) {
            error_log('[ANP] OCR-clean HTTP error: ' . $response->get_error_message());
        }
        return $ocr_text;
    }

    // Decode the JSON response
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (defined('ANP_DEBUG') && ANP_DEBUG) {
        error_log('[ANP] OCR-clean response: ' . print_r($body, true));
    }

    // Extract the assistant's content
    $assistant = $body['choices'][0]['message']['content'] ?? '';

    // If GPT returned something, trim and return it; otherwise, return the original
    $cleaned = trim($assistant);
    if ($cleaned === '') {
        if (defined('ANP_DEBUG') && ANP_DEBUG) {
            error_log('[ANP] OCR-clean returned empty; using raw text.');
        }
        return $ocr_text;
    }

    return $cleaned;
}


// OCR-Clean Step in PHP

/**
 * Cheap, generic “clean only the nutrition numbers” step.
 *
 * @param string $ocr_text
 * @return string  Cleaned OCR text (only the nutrition portion).
 */
function anp_gpt_clean_only($ocr_text) {
    $api_key = get_option('anp_openai_api_key');
    if (empty($api_key)) {
        return $ocr_text;
    }

    // Instruct GPT to fix *any* numeric formatting without hard-coding patterns.
    $prompt  = "You are an OCR text cleaner. Only fix numeric artifacts and insert missing decimals/slashes in this nutrition block. ";
    $prompt .= "Whenever you see a group of digits longer than two digits attached to a unit (like '644g', 'O365kcal', '1039'), assume it should be split so that the last digit is a decimal fraction. ";
    $prompt .= "For example:\n";
    $prompt .= "  • '644g' → '64.4 g'\n";
    $prompt .= "  • '314'  → '31.4'\n";
    $prompt .= "  • '1039' → '10.3'  (while preserving unit if present e.g. '10.3 g').\n";
    $prompt .= "Also, separate digits from units with a space (e.g. '33.7 g', not '33.7g').\n";
    $prompt .= "Return only the cleaned nutrition lines, starting from the word 'NUTRITION'. Do not add commentary.\n\n";
    $prompt .= trim($ocr_text);

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json'
        ],
        'body' => wp_json_encode([
            'model'    => 'gpt-3.5-turbo',
            'messages' => [
                ['role'=>'system','content'=>'You are an OCR text cleaner.'],
                ['role'=>'user','content'=> $prompt],
            ],
            'max_tokens' => 250
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($res)) {
        return $ocr_text;
    }

    $body    = json_decode(wp_remote_retrieve_body($res), true);
    $cleaned = $body['choices'][0]['message']['content'] ?? $ocr_text;
    return trim($cleaned);
}


/**
 * Now that numeric lines are clean, extract the last value on each row.
 *
 * @param string $cleaned_text  OCR output after generic cleaning.
 * @return array{expiry_date: string|null, flags: string[], nutrition: array, summary: string, alternative: string}
 */

 /**
 * After generic numeric cleanup, extract only the “last” number on each row,
 * and return JSON with precisely the keys your code expects.
 */
function anp_openai_extract_after_clean($cleaned_text) {
    $api_key = get_option('anp_openai_api_key');
    if (empty($api_key)) {
        return [
            'product_name'=> null,
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => '',
            'alternative' => '',
        ];
    }

    // VERY IMPORTANT: we now spell out *exactly* which keys we need, in snake_case.
    $prompt  = "You are a nutrition‐label parser. The following text has been cleaned so that each nutrient row shows a single 'prepared' value, for example:\n";
    $prompt .= "  Energy 232 kcal\n";
    $prompt .= "  Fat 5.5 g\n";
    $prompt .= "  Saturates 2.4 g\n";
    $prompt .= "  Carbohydrate 33.7 g\n";
    $prompt .= "  Sugars 15.3 g\n";
    $prompt .= "  Fiber 3.5 g\n";
    $prompt .= "  Protein 10.3 g\n";
    $prompt .= "  Salt 0.30 g\n\n";

    $prompt .= "Your job:\n";
    $prompt .= "  1) If you see any expiry/best‐before date (e.g. 'BEST BEFORE: 2025-08-01'), return it as “YYYY-MM-DD”. If there is no explicit date, return null.\n";
    $prompt .= "  2) Identify any red‐alert flags (e.g. \"High Sugar\" if sugars per prepared serving > 15 g). You decide reasonable cutoffs.\n";
    $prompt .= "  3) If you can identify the product name, include it.\n";
    $prompt .= "  4) Return exactly one JSON object with these keys (no others):\n";
    $prompt .= "      {\n";
    $prompt .= "        \"product_name\": <string|null>,\n";
    $prompt .= "        \"expiry_date\": <string|null>,\n";
    $prompt .= "        \"flags\": [ /* array of strings */ ],\n";
    $prompt .= "        \"nutrition\": {\n";
    $prompt .= "          \"energy_kcal\": <number>,\n";
    $prompt .= "          \"fat_g\": <number>,\n";
    $prompt .= "          \"saturates_g\": <number>,\n";
    $prompt .= "          \"carbohydrate_g\": <number>,\n";
    $prompt .= "          \"sugars_g\": <number>,\n";
    $prompt .= "          \"fiber_g\": <number>,\n";
    $prompt .= "          \"protein_g\": <number>,\n";
    $prompt .= "          \"salt_g\": <number>\n";
    $prompt .= "        },\n";
    $prompt .= "        \"summary\": \"Brief summary of this product’s nutrition profile.\",\n";
    $prompt .= "        \"alternative\": \"Short alternative product name or tagline.\"\n";
    $prompt .= "      }\n\n";
    $prompt .= "Here is the cleaned nutrition text:\n";
    $prompt .= trim($cleaned_text) . "\n";

    if (defined('ANP_DEBUG') && ANP_DEBUG) {
        error_log('[ANP] Extraction prompt:' . $prompt);
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers'=> [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'    => 'gpt-3.5-turbo',
            'messages' => [
                ['role'=>'system','content'=>'You are a nutrition‐label parser.'],
                ['role'=>'user','content'=> $prompt],
            ],
            'max_tokens' => 200,
        ]),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        return [
            'product_name'=> null,
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => '',
            'alternative' => '',
        ];
    }

    $body    = json_decode(wp_remote_retrieve_body($response), true);
    if (defined('ANP_DEBUG') && ANP_DEBUG) {
        error_log('[ANP] Extraction response:' . print_r($body, true));
    }

    $content = $body['choices'][0]['message']['content'] ?? '';
    $data    = json_decode($content, true);

    if (!is_array($data)) {
        // GPT didn’t return valid JSON → fallback
        return [
            'product_name'=> null,
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => $content,
            'alternative' => '',
        ];
    }

    // Normalize numeric fields (all keys are exactly as requested, so no more "Energy"/"Fat")
    $nut = $data['nutrition'] ?? [];
    $normalized_nutrition = [
        'energy_kcal'    => isset($nut['energy_kcal'])    ? floatval($nut['energy_kcal'])    : null,
        'fat_g'          => isset($nut['fat_g'])          ? floatval($nut['fat_g'])          : null,
        'saturates_g'    => isset($nut['saturates_g'])    ? floatval($nut['saturates_g'])    : null,
        'carbohydrate_g' => isset($nut['carbohydrate_g']) ? floatval($nut['carbohydrate_g']) : null,
        'sugars_g'       => isset($nut['sugars_g'])       ? floatval($nut['sugars_g'])       : null,
        'fiber_g'        => isset($nut['fiber_g'])        ? floatval($nut['fiber_g'])        : null,
        'protein_g'      => isset($nut['protein_g'])      ? floatval($nut['protein_g'])      : null,
        'salt_g'         => isset($nut['salt_g'])         ? floatval($nut['salt_g'])         : null,
    ];

    return [
        'product_name'=> $data['product_name']  ?? null,
        'expiry_date' => $data['expiry_date']   ?? null,
        'flags'       => $data['flags']         ?? [],
        'nutrition'   => $normalized_nutrition,
        'summary'     => $data['summary']       ?? '',
        'alternative' => $data['alternative']   ?? '',
    ];
}


/**
 * Do a single GPT call that:
 *  1) Cleans OCR artifacts
 *  2) Extracts exactly the third‐column (prepared) nutrition facts
 *  3) Finds any expiry date
 *  4) Flags high‐risk nutrients
 *  5) Writes a summary and alternative tagline
 *
 * param string $ocr_text
 * return array {
 *   @type string       $expiry_date
 *   @type string[]     $flags
 *   @type array        $nutrition     Associative: energy_kcal, fat_g, saturates_g, carbohydrate_g, sugars_g, fiber_g, protein_g, salt_g
 *   @type string       $summary
 *   @type string       $alternative
 * }
 */

 /**
 * One‐call GPT that:
 *  • Cleans OCR artifacts
 *  • Parses only the “last” (prepared) number on each row
 *  • Finds expiry, flags, summary, alternative
 */
function anp_openai_full_analysis($ocr_text) {
    $api_key = get_option('anp_openai_api_key');
    if (empty($api_key)) {
        return [
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => '',
            'alternative' => '',
        ];
    }

    // Build a very explicit prompt
    $prompt  = "You are a nutrition‐label expert and copy editor.\n\n";
    $prompt .= "Below is the raw OCR text from a product’s nutrition panel. ";
    $prompt .= "Each row may list up to three values separated by slashes or spaces—for example:\n";
    $prompt .= "  \"Carbohydrate 64.4 g / 25.1 g / 33.7 g\"\n";
    $prompt .= "These columns correspond to Per 100 g, Per serving (dry), and Per prepared (as served) respectively.\n\n";
    $prompt .= "Your job:\n";
    $prompt .= "  1) Fix any OCR artifacts (e.g., 'k)/' → 'kcal', 'kaal' → 'kcal', '03O' → '0.30', '1039' → '10.3', etc.)\n";
    $prompt .= "  2) For each nutrient row, **always take the last numeric value** (i.e. the prepared column). ";
    $prompt .= "So in \"64.4 g / 25.1 g / 33.7 g\", pick **33.7**.\n";
    $prompt .= "  3) Identify any expiry/best‐before date (if present).\n";
    $prompt .= "  4) Flag any red‐alert nutrients (e.g., \"High Sugar\" if sugars > 15 g per prepared serving).\n";
    $prompt .= "  5) Return exactly one JSON object with these keys (no extra fields):\n";
    $prompt .= "      {\n";
    $prompt .= "        \"expiry_date\": \"YYYY-MM-DD\" or null,\n";
    $prompt .= "        \"flags\": [ /* e.g. [\"High Sugar\"] */ ],\n";
    $prompt .= "        \"nutrition\": {\n";
    $prompt .= "          \"energy_kcal\": <number>,\n";
    $prompt .= "          \"fat_g\": <number>,\n";
    $prompt .= "          \"saturates_g\": <number>,\n";
    $prompt .= "          \"carbohydrate_g\": <number>,\n";
    $prompt .= "          \"sugars_g\": <number>,\n";
    $prompt .= "          \"fiber_g\": <number>,\n";
    $prompt .= "          \"protein_g\": <number>,\n";
    $prompt .= "          \"salt_g\": <number>\n";
    $prompt .= "        },\n";
    $prompt .= "        \"summary\": \"Brief summary of this product’s nutrition profile.\",\n";
    $prompt .= "        \"alternative\": \"Short alternative product name or tagline.\"\n";
    $prompt .= "      }\n\n";
    $prompt .= "Raw OCR Output:\n";
    $prompt .= trim($ocr_text) . "\n";

    if (ANP_DEBUG) {
        error_log("[ANP] Full‐analysis prompt:\n{$prompt}");
    }

    // Call GPT‐3.5‐Turbo (or gpt-4o-mini if you have access)
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'    => 'gpt-3.5-turbo',
            'messages' => [
                ['role'=>'system','content'=>'You are a nutrition‐label expert and text cleaner.'],
                ['role'=>'user','content'=> $prompt],
            ],
            'max_tokens' => 400,
        ]),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        return [
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => '',
            'alternative' => '',
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (ANP_DEBUG) {
        error_log('[ANP] GPT full‐analysis response: ' . print_r($body, true));
    }
    $content = $body['choices'][0]['message']['content'] ?? '';

    $data = json_decode($content, true);
    if (!is_array($data)) {
        // GPT failed to return valid JSON → fallback to empty
        return [
            'expiry_date' => null,
            'flags'       => [],
            'nutrition'   => [],
            'summary'     => $content,
            'alternative' => '',
        ];
    }

    // Normalize “fiber/fibre” key if needed
    $nutrition = [
        'energy_kcal'    => isset($data['nutrition']['energy_kcal'])    ? floatval($data['nutrition']['energy_kcal'])    : null,
        'fat_g'          => isset($data['nutrition']['fat_g'])          ? floatval($data['nutrition']['fat_g'])          : null,
        'saturates_g'    => isset($data['nutrition']['saturates_g'])    ? floatval($data['nutrition']['saturates_g'])    : null,
        'carbohydrate_g' => isset($data['nutrition']['carbohydrate_g']) ? floatval($data['nutrition']['carbohydrate_g']) : null,
        'sugars_g'       => isset($data['nutrition']['sugars_g'])       ? floatval($data['nutrition']['sugars_g'])       : null,
        'fiber_g'        =>
            isset($data['nutrition']['fiber_g'])  ? floatval($data['nutrition']['fiber_g'])
          : (isset($data['nutrition']['fibre_g'])  ? floatval($data['nutrition']['fibre_g']) : null),
        'protein_g'      => isset($data['nutrition']['protein_g'])      ? floatval($data['nutrition']['protein_g'])      : null,
        'salt_g'         => isset($data['nutrition']['salt_g'])         ? floatval($data['nutrition']['salt_g'])         : null,
    ];

    return [
        'expiry_date' => $data['expiry_date']   ?? null,
        'flags'       => $data['flags']         ?? [],
        'nutrition'   => $nutrition,
        'summary'     => $data['summary']       ?? '',
        'alternative' => $data['alternative']   ?? '',
    ];
}


// ----- Section: Admin Settings -----
/**
 * Admin menu and settings page
 */
function anp_admin_menu() {
    add_options_page('AI Nutrition Scanner', 'Nutrition Scanner', 'manage_options', 'anp-settings', 'anp_settings_page');
}
add_action('admin_menu', 'anp_admin_menu');

function anp_settings_page() {
    if (!empty($_POST['anp_save'])) {
        update_option('anp_replicate_api_key', sanitize_text_field($_POST['replicate_key']));
        update_option('anp_openai_api_key', sanitize_text_field($_POST['openai_key']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>AI Nutrition Scanner Settings</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="replicate_key">Replicate API Key</label></th>
                    <td><input id="replicate_key" name="replicate_key" type="text" value="<?php echo esc_attr(get_option('anp_replicate_api_key')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="openai_key">OpenAI API Key</label></th>
                    <td><input id="openai_key" name="openai_key" type="text" value="<?php echo esc_attr(get_option('anp_openai_api_key')); ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'anp_save'); ?>
        </form>
    </div>
    <?php
}

?>
