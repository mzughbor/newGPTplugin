<?php

/*
Plugin Name: ChatGPT with Ava - Private Rewrites
Description: Rewrite private post content using ChatGPT API as a cron job.
Version: 1.90
Author: mZughbor
*/

define('CUSTOM_LOG_PATH', plugin_dir_path(__FILE__) . 'log.txt');
//define('CUSTOM_LOG_PATH', WP_CONTENT_DIR . 'plugins/chatgpt_ava/log.txt');

$log_dir = dirname(CUSTOM_LOG_PATH);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Add the plugin menu
add_action('admin_menu', 'chatgpt_ava_plugin_menu');
function chatgpt_ava_plugin_menu()
{
    add_options_page('ChatGPT with Ava Settings', 'ChatGPT with Ava', 'manage_options', 'chatgpt_ava_settings', 'chatgpt_ava_settings_page');
}


// Plugin settings page
function chatgpt_ava_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    ?>
    <div class="wrap">
        <h1>ChatGPT with Ava Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('chatgpt_ava_options');
            do_settings_sections('chatgpt_ava_settings');
            submit_button();
            $min_word_count = 115;
            $max_char_count = 8100; // without_space
            ?>
        </form>        
        <!-- Existing form for API key setting -->        
        <h2>Draft Posts List will auto delete at the end of the day</h2>
        <p>Below is the list of draft posts with less than <?php echo $min_word_count; ?> words or long text more that <?php echo $max_char_count; ?> charachters without spaces.</p>
        <?php display_draft_posts(); ?>        
        <!-- Rest of the form and submit button -->
    </div>
    <?php
}


// Plugin settings
add_action('admin_init', 'chatgpt_ava_settings_init');
function chatgpt_ava_settings_init()
{
    register_setting('chatgpt_ava_options', 'chatgpt_ava_api_key');
    add_settings_section('chatgpt_ava_settings', 'ChatGPT API Settings', 'chatgpt_ava_settings_section_callback', 'chatgpt_ava_settings');
    add_settings_field('chatgpt_ava_api_key', 'ChatGPT API Key', 'chatgpt_ava_api_key_render', 'chatgpt_ava_settings', 'chatgpt_ava_settings');
}

function chatgpt_ava_settings_section_callback()
{
    echo '<p>Enter your ChatGPT API key below:</p>';
}

function chatgpt_ava_api_key_render()
{
    $api_key = get_option('chatgpt_ava_api_key');
    echo "<input type='text' name='chatgpt_ava_api_key' value='" . esc_attr($api_key) . "' />";
}

// Enqueue necessary scripts and styles for the plugin
add_action('wp_enqueue_scripts', 'chatgpt_ava_enqueue_scripts');
function chatgpt_ava_enqueue_scripts()
{
    wp_enqueue_script('chatgpt-ava-script', plugin_dir_url(__FILE__) . 'js/chatgpt_ava_script.js', array('jquery'), '1.0', true);
}

function get_draft_posts_array()
{
    return get_option('draft_posts_array', array());
}

function display_draft_posts()
{
    $draft_posts = get_draft_posts_array();
    if (!empty($draft_posts)) {
        echo '<ul>';
        $num_lo = 1;
        foreach ($draft_posts as $post_id) {
            echo '<li> num ' . $num_lo . ' >> '; 
            echo get_the_title($post_id) . '</li>';
            $num_lo +=1;
        }
        echo '</ul>';
    } else {
        echo 'No draft posts found.';
    }
}

// Shortcode to display the ChatGPT form
add_shortcode('chatgpt_ava_form', 'chatgpt_ava_form_shortcode');
function chatgpt_ava_form_shortcode()
{
    ob_start();
    ?>
    <form id="chatgpt-ava-form">
        <textarea id="chatgpt-ava-input" rows="5" cols="30" placeholder="Enter your message..."></textarea>
        <button type="button" id="chatgpt-ava-submit">Send</button>
        <div id="chatgpt-ava-output"></div>
    </form>
    <?php
    return ob_get_clean();
}

// AJAX handler to interact with the ChatGPT API
add_action('wp_ajax_chatgpt_ava_send_message', 'chatgpt_ava_send_message');
add_action('wp_ajax_nopriv_chatgpt_ava_send_message', 'chatgpt_ava_send_message');

function chatgpt_ava_send_message()
{
    $api_key = get_option('chatgpt_ava_api_key');
    $message = sanitize_text_field($_POST['message']);

    // Helper function to truncate the content to fit within the token limit
    function chatgpt_ava_truncate_content($content, $max_tokens)
    {
        // Truncate the content to fit within the token limit
        $tokens = str_word_count(preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($content)) , 1); // for arabic
        $total_tokens = count($tokens);
        if ($total_tokens > $max_tokens) {
            $content = implode(' ', array_slice($tokens, 0, $max_tokens));
        }
        return $content;
    }

    // Limit the content length if needed
    $max_tokens = 3770; // Model's maximum context length 40
    $filtered_content = chatgpt_ava_truncate_content($message, $max_tokens);

    // Insert your ChatGPT API call here using the chat completions endpoint
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 60, // Increase the timeout value
        'body' => json_encode(array(
            'prompt' => $filtered_content, // Use 'prompt' instead of 'messages'
            'max_tokens' => 3000, // Adjust as needed
            'model' => 'gpt-3.5-turbo', // Use the Ada model name here // gpt-3.5-turbo // text-davinci-003
            'temperature' => 0.5, // Control randomness (optional, adjust as needed) // old 0.8
            
        )),
    ));

    if (is_wp_error($response)) {
        $output = $response->get_error_message();
    } else {
        $response_body = json_decode($response['body'], true);
        /*
        if (isset($response_body['choices']) && is_array($response_body['choices']) && !empty($response_body['choices'])) {
            $output = $response_body['choices'][0]['message']['content'];
        } else {
            $output = 'Generated content from ChatGPT';
        }
        */
        if (is_array($response_body) && isset($response_body['choices'][0]['message']['content'])) {
            $output = $response_body['choices'][0]['message']['content'];
        } else {
            // Log the error for debugging
            error_log('chatgpt_ava_send_message function >> ChatGPT API Response Error: ' . print_r($response_body, true)."\n", 3, CUSTOM_LOG_PATH);
            $output = 'Generated content from ChatGPT';
        }

    }

    echo $output;
    wp_die();
}

// Function for deleting draft post after failed ...
function delete_draft_posts_daily()
{
    $draft_posts = get_option('draft_posts_array', array());
    if (!empty($draft_posts)) {
        foreach ($draft_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        // Clear the array after deleting the posts
        update_option('draft_posts_array', array());
    }
}
add_action('init', function () {
    if (!wp_next_scheduled('delete_draft_posts_cron')) {
        wp_schedule_event(time(), 'daily', 'delete_draft_posts_cron');
    }
});
add_action('delete_draft_posts_cron', 'delete_draft_posts_daily');

// Function to handle private rewrites and schedule it as a cron job
function chatgpt_ava_schedule_private_rewrites()
{
    if (!wp_next_scheduled('chatgpt_ava_private_rewrite_cron')) {
        wp_schedule_event(time(), 'every_fifteen_minutes', 'chatgpt_ava_private_rewrite_cron');
    }
}
add_action('wp', 'chatgpt_ava_schedule_private_rewrites');

// 26 December 2023 latest edit...
// Filteration function for all inclusions and exclusions like more news and so on...
// this function was to, idea of if this site do .. it's not don't do.. filtering stuff...
function test_ava_remove_custom_paragraphs($content) {
    // Pattern to match the unwanted paragraph with a strong tag
    // Array of unwanted patterns
    // replacment idea of text we don't need like المراجع dind't work in fucter update we'll fix it
    $unwanted_patterns = array(
        '/أقرأ ايضًا:/u', //kora+
        '/أخبار متعلقة/u',
        '/طالع أيضًا:/u',
        '/طالع أيضًا/u',
        '/WRONGERR/u',
        '/الأخبار الرئيسية/u', // bbc !! not working so we'll remove the inter div...
        '/قصص مقترحة/u', // bbc
        '/المزيد حول هذه القصة/u', // bbc
        '/مواضيع ذات صلة/u', // bbc
        '/اخترنا لكم/u', // bbc
        '/اقرأ أيضا:/u', //yalla_kora
        '/اقرأ أيضا:/u', //2
        '/طالع أيضا/u',        
        '/المراجع/u', //mawdoo3.com // didn't worked ++ elakademiapost.com ? did work?
        '/محتويات/u', // we'll delete inter div
    );
    //  no-sometimes there is two ones in articles '/أخبار متعلقة/u',

    // Loop through patterns and remove them from content
    foreach ($unwanted_patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            // Remove the unwanted pattern
            $content = preg_replace($pattern, '', $content);
        }
    }

    // Define the class names of the divs you want to remove
    $ids_and_classes_to_remove  = array(
        'ez-toc-container', // id for elakademiapost.com
        'h-المراجع', // id for ...
        'd-sharing-enabled', // class for ...
        'copy-link-tooltip', // id for ...
        'copyLinkInput', // ...
        'note', // ...
        'post-tags', // ...
        'post-categories', // ...
        'After_F_Paragraph', // Kora+ <id>
        'related-articles-list1', // mawdoo3 <class>
        'toc',// mawdoo3
        'toctitle', // mawdoo3
        'references', // mawdoo3
        'printfooter', // mawdoo3
        'feedback-feature', // mawdoo3
        'feedback-no-option', // mawdoo3
        'feedback-thanks-msg', // mawdoo3
        'feedback-yes-option', // mawdoo3
    );

    // Create a DOMDocument object to parse the post content
    $dom = new DOMDocument();
    $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');
    @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Check if the DOMDocument was created successfully
    if (!$dom) {
        // we'll edit return lateer to be at the bottom
        error_log('Failed .. !dom :: So return content ...\n' , 3, CUSTOM_LOG_PATH);        
        return $content;
    }

    // Create a DOMXPath object to query the DOMDocument
    $xpath = new DOMXPath($dom);
    
    // Loop through each class to remove
    foreach ($ids_and_classes_to_remove as $id_or_class) {
        // Find the divs with the specified class name using XPath
        //$divsToRemove = $xpath->query("//*[@class='$class_name']");
        $divsToRemove = $xpath->query("//*[@id='$id_or_class' or contains(@class, '$id_or_class')]");
        if ($divsToRemove) {
            // Remove divs by class name
            foreach ($divsToRemove as $div) {
                $divParent = $div->parentNode;
                $divParent->removeChild($div);
            }
        }
    }
    // Save the modified HTML back to the post content
    $content = $dom->saveHTML();
    $content = html_entity_decode($content);
    return $content;
}

// this function not in use for now!
function triger_seo_analysis($post_id)
{
    // 24-09
    // Get the Yoast SEO analysis class loader.
    require_once ABSPATH . 'wp-content/plugins/wordpress-seo/vendor/autoload.php';
    // Create a new Yoast SEO analysis object.
    $wpseo_analysis = new Yoast\WP\SEO\Analysis\Analysis();
    // Trigger the Yoast SEO analysis for the post.
    $wpseo_analysis->trigger_analysis($post_id);

        // latest problem where we trying to solve... b 7 Oct...
        // Check the results of the analysis.
        $analysis_results = $wpseo_analysis->get_results();

    sleep(5);
    //$update_data = array('ID'    => $post_id);
    //$update_result = wp_update_post($update_data);
    error_log('wp_update_post() ::' . print_r($analysis_results, true)."\n" , 3, CUSTOM_LOG_PATH);
}

// this function used for filtering the keyphrase response
function extract_arabic_text($text)
{

    // If the text is more than 191 characters long, then only extract the text between "".
    if (strlen($text) > 191) {

        // Find the position of the first occurrence of the left double quote.
        $start_position = strpos($text, '"');

        // Find the position of the next occurrence of the right double quote after the first occurrence.
        $end_position = strpos($text, '"', $start_position + 1);

        // If the second occurrence of the quote is not found, then the text between the two occurrences is the entire rest of the string.
        if ($end_position === false) {
          $end_position = strlen($text);
        }

        // Extract the text between the two occurrences.
        $text_between_quotes = substr($text, $start_position + 1, $end_position - $start_position - 1);

        // Return the extracted text.
        return $text_between_quotes;
        
    } else {
        // Check if the text starts with any of the supported keywords.
        $keywords = array('Focus Keyphrase:',
            'The focus keyphrase for the given sentence is', 
            'The focus keyphrase for the given sentence is:',
            'The keyphrase for the given sentence is',
            'The keyphrase for the given sentence is:',
            'The keyphrase for the given text is',
            'The keyphrase for the given text is:',
            'Possible focus keyphrase',
            'Possible focus keyphrase:',
            'The Focus Keyphrase for this sentence could be',
            'The Focus Keyphrase for this sentence could be:',
            'The focus keyphrase for your input is',
            'The focus keyphrase for your input is:',
            'The suggested Focus Keyphrase for the given text is',
            'The suggested Focus Keyphrase for the given text is:',
            'The Focus Keyphrase for the given text is',
            'The Focus Keyphrase for the given text is:',
            'The possible focus keyphrase for the given text is',
            'The possible focus keyphrase for the given text is:',
        );
        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                // Extract the Arabic text after the keyword and remove the double quotes.
                $arabic_text = substr($text, strpos($text, $keyword) + strlen($keyword));
                $arabic_text = preg_replace('/"+/', '', $arabic_text);
                break;
            } else {
                $arabic_text = substr($text, strpos($text, '"'), strpos($text, '"', strpos($text, '"') ) - strpos($text, '"'));
            }
        }

        // If the text does not start with any of the supported keywords, then the text is not in a supported format.
        if ($arabic_text === '') {
          // Extract the Arabic text between the double quotes.
          $arabic_text = preg_replace('/"+/', '', $text);
        }

        // 11/09 11:57p.m
        // Check if the string contains a comma
        if (strpos($arabic_text, ',') !== false) {
            // Replace commas with Arabic commas
            $arabic_text = preg_replace('/,/', '،', $arabic_text);
        }

        // Remove the single quotes.
        $arabic_text = preg_replace("/'/", "", $arabic_text);

        // Return the extracted Arabic text.
        return $arabic_text;
    }
}

// Function to find private posts and rewrite their content
function chatgpt_ava_private_rewrite()
{
    $api_key = get_option('chatgpt_ava_api_key');

    // Helper function to check if the post has a featured image
    function has_featured_image($post_ID)
    {
        $thumbnail_id = get_post_thumbnail_id($post_ID);
        return !empty($thumbnail_id);
    }

    /*
    // Helper function to truncate the content to fit within the token limit
    function chatgpt_ava_truncate_content($content, $max_tokens)
    {
        // Truncate the content to fit within the token limit
        $tokens = str_word_count($content, 1); // for ar preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($text_to_count)) 
        $total_tokens = count($tokens);
        if ($total_tokens > $max_tokens) {
            $content = implode(' ', array_slice($tokens, 0, $max_tokens));
        }
        return $content;
    }
    */
   
    function chatgpt_ava_truncate_content($content, $max_characters)
    {
        // Check if the content length exceeds the maximum characters
        //if (mb_strlen($content) > $max_characters) {
        if (strlen(preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($content))) > $max_characters) {
            
            //to delete
            error_log('**inside chatgpt_ava_truncate_content > mb_strlen(): ' . print_r(strlen($content), true)."\n", 3, CUSTOM_LOG_PATH);
            //error_log('**inside chatgpt_ava_truncate_content > mb_strlen(preg_replace(...)): ' . print_r(strlen(preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($content)), true))."\n", 3, CUSTOM_LOG_PATH);
            
            // Truncate the content to fit within the character limit
            $content = mb_substr(strip_tags($content), 0, $max_characters);
            
            //error_log('full content: ' . print_r($content, true)."\n", 3, CUSTOM_LOG_PATH);

        }
        return $content;
    }

    //07/01/2024
    // this function for filtering content before sending it to chatGPT, and fixing extra array of blocks issues. 
    function filter_allowed_tags($content) {
        // Define the allowed HTML tags
        $allowed_tags = '<h1><h2><h3><h4><h5><h6><p><img><picture><source><b><i><em><strong><a><ul><ol><li><table><tr><th><td>';
    
        // Use strip_tags to allow only the specified tags
        $contentFiltered = strip_tags($content, $allowed_tags);
    
        return $contentFiltered;
    }

    // new + img stuff
    function filter_post_content($content) {
        // Convert content to UTF-8 if needed
        $contentUTF8 = mb_convert_encoding($content, 'UTF-8', 'auto');
    
        // Open log file for writing with UTF-8 encoding
        $context = stream_context_create(['encoding' => 'UTF-8']);
        $file = fopen(CUSTOM_LOG_PATH, 'a', false, $context);
    
        // Write the content to the log file
        fwrite($file, $contentUTF8);
    
        // Split the content into blocks based on headings        
        $blocks = preg_split('/<h[1-6].*?>/', $contentUTF8);
    
        // Remove empty blocks
        $blocks = array_filter($blocks);
    
        $filtered_content = [];
    
        foreach ($blocks as $index => $block) {
            // Get the heading and its paragraph
            preg_match('/<h[1-6].*?>(.*?)<\/h[1-6]>/s', $block, $matches);
    
            $blockData = [];
    
            if (!empty($matches[1])) {
                // If there is a heading, include it and the paragraph
                $heading = $matches[0];
                $paragraph = substr($block, strlen($matches[0]));
    
                // Check if the total length is less than or equal to 2550 characters
                if (strlen($heading . $paragraph) <= 2550) {
                    $blockData = [
                        'type' => 'heading_with_paragraph',
                        'content' => $heading . $paragraph,
                    ];
                } else {
                    // If the total length exceeds 2550 characters, add the paragraph as a separate block
                    $blockData[] = [
                        'type' => 'heading',
                        'content' => $heading,
                    ];
    
                    // Split the paragraph into chunks of maximum 2550 characters
                    $paragraphChunks = str_split($paragraph, 2550);
                    foreach ($paragraphChunks as $chunk) {
                        $blockData[] = [
                            'type' => 'paragraph',
                            'content' => $chunk,
                        ];
                    }
                }
            } else {
                // If there is no heading, include the entire paragraph as a separate block
                $blockData = [
                    'type' => 'paragraph',
                    'content' => $block,
                ];
            }
    
            // Check if the block contains an image
            $containsImage = preg_match('/<img|<picture/', $block) === 1;
            //$blockData['containsImage'] = $containsImage;
            //$filtered_content[] = $blockData;
            
            if ($containsImage) {
                // Handle the block with an image (e.g., store it for later use)
                // You can add your logic here to handle images separately
                $imageBlock = [
                    'type' => 'image',
                    'content' => $block,
                ];
    
                // Add the image block to the filtered content separately
                $filtered_content[] = $imageBlock;
            } else {
                // Add the regular block to the filtered content
                $filtered_content[] = $blockData;
            }
    
        }
    
        // Close the file
        fclose($file);
    
        return $filtered_content;
    }

    //06/01/2024
    // this function responsible for cutting articles into chanks or blocks based on thier headding and paragraphs
    function filter_post_content_paragraphs($content) {
        
        // Convert content to UTF-8 if needed
        $contentUTF8 = mb_convert_encoding($content, 'UTF-8', 'auto');

        // Filter allowed HTML tags
        $contentFiltered = filter_allowed_tags($contentUTF8);

        // Open log file for writing with UTF-8 encoding
        $context = stream_context_create(['encoding' => 'UTF-8']);
        $file = fopen(CUSTOM_LOG_PATH, 'a', false, $context);

        // Write the content to the log file
        fwrite($file, $contentUTF8); // need to be commented...

        // Split the content into blocks based on headings        
        $blocks = preg_split('/<h[1-6].*?>/', $contentFiltered);

        // Remove empty blocks
        $blocks = array_filter($blocks);

        $filtered_content = [];

        foreach ($blocks as $index => $block) {
            // Get the heading and its paragraph
            preg_match('/<h[1-6].*?>(.*?)<\/h[1-6]>/s', $block, $matches);

            if (!empty($matches[1])) {
                // If there is a heading, include it and the paragraph
                $heading = $matches[0];
                $paragraph = substr($block, strlen($matches[0]));

                // Check if the total length is less than or equal to 2550 characters
                if (strlen($heading . $paragraph) <= 2550) {
                    $filtered_content[] = [
                        'type' => 'heading_with_paragraph',
                        'content' => $heading . $paragraph,
                    ];
                } else {
                    // If the total length exceeds 2550 characters, add the paragraph as a separate block
                    $filtered_content[] = [
                        'type' => 'heading',
                        'content' => $heading,
                    ];

                    $filtered_content[] = [
                        'type' => 'paragraph',
                        'content' => $paragraph,
                    ];
                }
            } else {
                // If there is no heading, include the entire paragraph as a separate block
                $filtered_content[] = [
                    'type' => 'paragraph',
                    'content' => $block,
                ];
            }
        }
        // Close the file
        fclose($file);
        return $filtered_content;
    }

    // function count content length and make decision before calling the Api
    //  the numbers is 130 word >> less than make it draft and save the post id in database 
    //  and make cron job once daily to  delete all the posts in that array in database
    function count_and_manage_posts($post_id, $content)
    {
        //$max_char_count = 8100; // without_space
        $min_word_count = 115;
        $post = get_post($post_id);

        error_log('-- Start of count_and_manage_posts :: ' . $post->ID ."\n", 3, CUSTOM_LOG_PATH);
        error_log('-- post id : ' . $post->ID ."\n", 3, CUSTOM_LOG_PATH);
        //error_log('-- post id : ' . $post ."\n", 3, CUSTOM_LOG_PATH);// make issue...

        // when it's run inside loop?
        if ($post && $post->post_type === 'post') {

            //old way before filteration function $content = $post->post_content;

            //For string length without spaces:
            $ch_string_length = strlen( preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($content)) ) - substr_count($content, ' ');
            error_log('-- ch_string_length variable -no spaces : ' . $ch_string_length ."\n", 3, CUSTOM_LOG_PATH);

            //For word count:
            //For Arabic launguage you have to use replace("/[\x{06 ideas 

            $word_count = str_word_count(preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($content)) );
            //$word_count = str_word_count($content);

            error_log('-- here is $word count : '. $word_count ."\n", 3, CUSTOM_LOG_PATH);

            // the only one thing we missing in this counting function is numbers 10 100 1000 any numbers

            if ($word_count < $min_word_count) { // || $ch_string_length > $max_char_count
                // Update post status to draft
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'draft'
                ));


                error_log('(+_+) Content is too Short ... '."\n", 3, CUSTOM_LOG_PATH);


                // Store the post ID in the database (add your custom table logic here)
                $draft_posts = get_option('draft_posts_array', array());
                $draft_posts[] = $post_id;
                update_option('draft_posts_array', $draft_posts);
                return false;
            } else {
                error_log('-- only TRUE case in truncate function ...'."\n", 3, CUSTOM_LOG_PATH);
                return true;
            }
        } else {
            error_log('-- post_type not post/article... '."\n", 3, CUSTOM_LOG_PATH);
            return false;
        }
    }

    // Regenerating post title with handeling the empyt title situation
    function regenerate_post_title($post_id, $new_title)
    {
        $post = get_post($post_id);

        // Check if the post title is empty
        //
        // if (empty($post->post_title)) { << not working 
        if (strlen($post->post_title) < 3 ) { // || strlen($post->post_title) > 48
            // future edit, it may need adding try again so short titles will be retried and good to pass seo
            // ++ that error when happen false, the post didn't delete and count with delete_draft posts cron ...
            error_log('~-~ regenerate_post_title() return false short'."\n", 3, CUSTOM_LOG_PATH); //  < or > long
            return false; // Return zero if title is empty
        }
        
        // Update the post title
        $updated_post = array(
            'ID' => $post_id,
            'post_title' => $new_title,
        );

        wp_update_post($updated_post);

        error_log('~-~ regenerate_post_title() return true'."\n", 3, CUSTOM_LOG_PATH);

        return true; // Return 1 to indicate title regeneration was successful
    }

    // save and generate focus keyphrase
    function generate_and_set_focus_keyphrase($post_id, $api_key)
    {
        // Get the post's filtered content
        $post = get_post($post_id);
        $content = $post->post_title;
        //$message = 'give me Focus Keyphrase for this {$content}';
        //$message = 'suggest a possible focus keyphrase in Arabic, only 4 words from this brief summary {$content}';
        //$message = 'give me Focus Keyphrase for this %s, please make sure to be only 4 words in Arabic';
        $message = 'give me Focus Keyphrase for this %s, please make sure to be only 4 words in Arabic and don\'t use commas at all, try to make it one sentence';
        $message = sprintf($message, $content);

        // Generate a focus keyphrase using your existing function
        $generated_keyphrase = generate_content_with_min_word_count($message, $api_key);
        //$generated_keyphrase = ...($message, $api_key, 1 , 2000 , 1);

        if (!$generated_keyphrase) {
            // Handle the case where keyphrase generation failed
            error_log('-- generated_keyphrase function -- Error (1)' ."\n", 3, CUSTOM_LOG_PATH);
            return false;
        }
        
        error_log('~-~ before filteration: ' . print_r($generated_keyphrase, true)."\n", 3, CUSTOM_LOG_PATH);
        // filter the response 
        $generated_keyphrase = extract_arabic_text($generated_keyphrase);
        // Set the generated keyphrase as the Focus keyphrase using Yoast SEO
        $set_keyphrase_result = update_post_meta($post_id, '_yoast_wpseo_focuskw', $generated_keyphrase);

        if ($set_keyphrase_result) {
            error_log('-- Keyphrase set successfully' ."\n", 3, CUSTOM_LOG_PATH);
            return $generated_keyphrase; // Keyphrase set successfully
        } else {
            error_log('-- Failed to set the keyphrase' ."\n", 3, CUSTOM_LOG_PATH);            
            return false; // Failed to set the keyphrase
        }
    }

    // new seo title function
    function update_yoast_seo_title($post_id, $new_title)
    {
        // Check if Yoast SEO plugin is active
        if (defined('WPSEO_VERSION')) {
            // Update the post's SEO title
            update_post_meta($post_id, '_yoast_wpseo_title', $new_title);
        } else {
            // Log an error if the post doesn't exist
            error_log("TITLE :: undefined WPSEO_VERSION..."."\n", 3, CUSTOM_LOG_PATH);
        }
    }

    // Hook into the wpseo_title filter to replace the SEO title
    add_filter('wpseo_title', function ($title) use ($post_id) {
        $new_title = get_post_meta($post_id, '_yoast_generated_title', true);

        if (!empty($new_title)) {
            return $new_title;
        }

        return $title;
    });

    // new seo description function
    function update_yoast_seo_meta_description($post_id, $new_description)
    {
        // Check if Yoast SEO plugin is active
        if (defined('WPSEO_VERSION')) {
            // Update the post's meta description
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $new_description);
            //update_yoast_wpseo_meta_description($post_id, $new_description);
        } else {
            // Log an error if the post doesn't exist
            error_log("DESCRIPTION :: undefined WPSEO_VERSION..."."\n", 3, CUSTOM_LOG_PATH);
        }
    }

    // Hook into the wpseo_meta_description filter to replace the meta description
    add_filter('wpseo_metadesc', function ($description) use ($post_id) {
        $new_description = get_post_meta($post_id, '_yoast_generated_meta_description', true);

        if (!empty($new_description)) {
            return $new_description;
        }

        return $description;
    });

    // this for changing alt to match with keyphase...    
    function modify_featured_image_alt( $post_id, $keyphrase )
    {
        // Get featured image id
        $thumbnail_id = get_post_thumbnail_id( $post_id ); 

        // Update alt text
        if( $thumbnail_id ) {
            update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', $keyphrase );
            error_log("modify_featured_image_alt :: changed..."."\n", 3, CUSTOM_LOG_PATH);
            return true;
        } else {
            return false;
        }
    }

    // try to fix meta
    function checkTitleDescLength($text, $minLength=15, $maxLength=65, $overMaxLength=85)
    {

        $api_key = get_option('chatgpt_ava_api_key');
        $length = mb_strlen($text, 'UTF-8');

        if ($length < $minLength) {

            // fix title generated.
            error_log("checkTitleDescLength :failed less than Min length: "."\n", 3, CUSTOM_LOG_PATH);
            $SEO_title = "Write SEO title for previous article containing exact keyphrase with limit of 62 character in total using the Arabic language. make sure to add name of site \" Wedti.com \" in the tilte.";
            $new_seo_title = generate_content_with_min_word_count($SEO_title, $api_key);
            error_log("checkTitleDescLength :fixed: "."\n", 3, CUSTOM_LOG_PATH);
        } elseif ($length >= $minLength && $length <= $overMaxLength) {

            // pass the title length cheak
            error_log("checkTitleDescLength :pass: "."\n", 3, CUSTOM_LOG_PATH);
            return $text;
        } elseif ($length > $overMaxLength ) {

            error_log("checkTitleDescLength :failed over than Max length: "."\n", 3, CUSTOM_LOG_PATH);
            $SEO_title = " \" {$text} \" make it shorter, the \"{$length}\"characters is over than 80, using the Arabic language, make sure to insert the exact keyphrase on it";
            $new_seo_title = generate_content_with_min_word_count($SEO_title, $api_key);
            error_log("checkTitleDescLength :fixed: "."\n", 3, CUSTOM_LOG_PATH);
            return $text;
        } else {

            error_log("< Error checkTitleDescLength :faild and not fixed: "."\n", 3, CUSTOM_LOG_PATH);
            return false;
        } 
        return $text;
    }

    // try to fix meta
    function checkMetaDescLength($text, $minLength=103, $maxLength=142, $overMaxLength=150)
    {
        $api_key = get_option('chatgpt_ava_api_key');
        $length = mb_strlen($text, 'UTF-8');
        //echo $length.'<br>';

        if ($length < $minLength && $length >= 99) {
            // fix length add " ..." to the end of the text.
            $text .= " ...";
        } elseif ($length > 142 && $length <= 150) {
            // Delete the last few words to make it 142 characters.
            $text = mb_substr($text, 0, 140, 'UTF-8');
            $text .= "..";
        } elseif ($length < 99 ) {
            // length < 99 
            return $text;
        } else {
            // ask for generate again
            // length > 151
            $SEO_description = " \" {$text} \" make it shorter, the \"{$length}\"characters is over than 145, using the Arabic language, make sure to insert the exact keyphrase";
            $text = generate_content_with_min_word_count($SEO_description, $api_key);
            error_log("< 99 or > 151 :: "."\n", 3, CUSTOM_LOG_PATH);
        } 
        return $text;
    }

    // note / this function cheack in all given text not only first 156 chracters...
    function checkKeyphraseInText($keyphrase, $text)
    {
        // Remove commas and periods from the keyphrase and text
        $keyphrase = str_replace(['.', ',', '-', '،', '/', '\\', '|', '!', '?', '؟', '`', '"', "'"], '', $keyphrase);
        $text = str_replace(['.', ',', '-', '،', '/', '\\', '|', '!', '?', '؟', '`', '"', "'"], '', $text);
        
        // Split the keyphrase into individual words
        $keyphraseWords = explode(' ', $keyphrase);
        
        // Remove empty elements from the array
        $keyphraseWords = array_filter($keyphraseWords, 'strlen');

        // Initialize an array to store missing words
        $missingWords = [];
        
        // Loop through each word in the keyphrase
        foreach ($keyphraseWords as $word) {
            // Check if the word exists in the text
            if (stripos($text, $word) === false) {
                // Word not found, add it to the missingWords array
                $missingWords[] = $word;
            }
        }
        error_log('-- checkKeyphraseInText > missingWords[] >> ' . print_r($missingWords, true) ."\n", 3, CUSTOM_LOG_PATH);
        // Check if any words were missing
        if (!empty($missingWords)) {
            return $missingWords; // Return the array of missing words
        }
        return true; // All words found, return true
    }

    // this for fixing meta keyphrase text appearance 
    function checkKeyphraseInMetaDescription($keyphrase, $meta_description)
    {

        // Specify the maximum allowed length for the meta description
        $max_length = 156; // You can adjust this value as needed

        // Extract the text from the beginning up to the 156th character
        $text_to_search = mb_substr($meta_description, 0, $max_length);

        // Check if the keyphrase exists in the extracted text and return array of missing words or True...
        $key_forgetten = checkKeyphraseInText($keyphrase, $text_to_search);

        // Get the length of the result
        $keyphraseResultLength = is_array($key_forgetten) ? count($key_forgetten) : 0;
        error_log('-- checkKeyphraseInMetaDescription > keyphraseResultLength >> ' . $keyphraseResultLength ."\n", 3, CUSTOM_LOG_PATH);

        if ($keyphraseResultLength != 0) {
            // Add the keyphrase to the beginning of the meta description
            $meta_description = implode('، ', $key_forgetten) . ' / ' . $meta_description;
        }
        return $meta_description;
    }

    // this for seo title fixing keyphrase issue
    function checkKeyphraseInSeoTitle($keyphrase, $seo_title)
    {
        // Specify the maximum allowed length for the meta description
        $max_length = 65; // You can adjust this value as needed

        // Extract the text from the beginning up to the 156th character
        $text_to_search = mb_substr($seo_title, 0, $max_length);

        // Check if the keyphrase exists in the extracted text and return array of missing words or True...
        $key_forgetten = checkKeyphraseInText($keyphrase, $text_to_search);

        // Get the length of the result
        $keyphraseResultLength = is_array($key_forgetten) ? count($key_forgetten) : 0;
        error_log('-- checkKeyphraseInSeoTitle > keyphraseResultLength >> ' . $keyphraseResultLength ."\n", 3, CUSTOM_LOG_PATH);

        if ($keyphraseResultLength != 0) {
            // Add the keyphrase to the beginning of the meta description
            $seo_title = $seo_title . ' / ' . implode('، ', $key_forgetten);
        }
        return $seo_title;
    }

    // Helper function to count words in a text
    function count_words($text)
    {
        if ($text !== false && is_string($text)) {
            // for testing only
            $mzjki = str_word_count(preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($text)) );
             
            error_log('+!--!+ count_words() ::' . $mzjki ."\n", 3, CUSTOM_LOG_PATH);

            return str_word_count( preg_replace("/[\x{0600}-\x{06FF}a-zA-Z]/u", "a", strip_tags($text)) );
        } else {
            // Handle the case where content generation failed
            return 0;
        } 
    }

    function puplish_now($post_ID,$generated_content)
    {
        $updated_post = array(
            'ID' => $post_ID,
            'post_content' => $generated_content,
            'post_status' => 'publish',
        );
        wp_update_post($updated_post);
    }
    
    function append_content_to_post($post_ID, $additional_content) {
        // Get the existing post content
        $existing_content = get_post_field('post_content', $post_ID);
    
        // Concatenate the existing content with the new content
        $updated_content = $existing_content . $additional_content;
    
        // Update the post with the combined content
        $updated_post = array(
            'ID' => $post_ID,
            'post_content' => $updated_content,
            'post_status' => 'publish',
        );
    
        wp_update_post($updated_post);
    }
    
    // Recursive function to generate content until it meets the minimum word count requirement
    //function ...($filtered_content, $api_key, $temperature = 1, $max_tokens = 4096, $top_p = 1)
    function generate_content_with_min_word_count($filtered_content, $api_key)
    {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60, // Increase the timeout value
            'body' => json_encode(array(
                'messages' => array(
                    array('role' => 'system', 'content' => 'Act like an SEO professional writer, 
                    I need an optimized blog post, you will paraphrase a given article paragraphs, you should focus
                    on readability, relevance, and proper keyword placement, please avoid keyword stuffing or
                    over-optimization. Make sure to use keyphrase in the subheadings and use a cohesive structure 
                    to ensure smooth transitions between ideas using enough transition words, while writing focus on 
                    the SEO score of Yoast and the readability score. Make it coherent and proficient. Remember to 
                    (1) enclose headings in the specified heading tags to make parsing the content easier and to 
                    improve SEO use keyphrase in one subheadings. (2) Make sure that 25% of the sentences you write 
                    contain less than 20 words, please keep in mind that I will give you article as parts not one shot.'),
                    array('role' => 'user', 'content' => $filtered_content),
                ),
                'model' => 'gpt-3.5-turbo', // Use the gpt-3.5-turbo model name here
                //'temperature' => $temperature,  // Adjust temperature as needed (0.2 to 1.0)
                //'max_tokens' => $max_tokens,    // Limit the response length (adjust as needed)
                //'top_p' => $top_p,
                //'frequency_penalty' => 0,
                //'presence_penalty' => 0
            )),
        ));

        if (is_wp_error($response)) {
            // Log the error for debugging
            error_log('ChatGPT API Error: ' . $response->get_error_message()."\n", 3, CUSTOM_LOG_PATH);
            return false;
        }

        $response_body = json_decode($response['body'], true);

        if (isset($response_body['choices']) && is_array($response_body['choices']) && !empty($response_body['choices'])) {
            //$generated_content = $response_body['choices'][0]['message']['content'];
            $generated_content = $response_body['choices'][0]['message']['content'];

            // Add empty lines after each paragraph using wpautop()
            $generated_content_with_lines = wpautop($generated_content);

            return $generated_content_with_lines;
        } else {
            // Log the entire response for debugging
            error_log('ChatGPT API Response Error after decode: ' . print_r($response_body, true)."\n", 3, CUSTOM_LOG_PATH);

            return false;
        }
    }
    
    // 07/01/24
    // this function for appling new response action
    function check_response_code($word_count, $api_key, $post, $generated_content)
    {
        //word_count = 0 delete post thats mean the api return error 

        $min_word_count = 200; //min_word_generated_count  // old 250
        if ($word_count == 0) {
            $result = wp_delete_post($post->ID, true);
            error_log('error post deleted :: ID' . $post->ID ."\n", 3, CUSTOM_LOG_PATH); 
        }

        elseif ($word_count <= $min_word_count) {
            while ($word_count <= $min_word_count) {

                $message = "continue";
                //$filtered_content = chatgpt_ava_truncate_content($message, $max_tokens);
                $new_content = generate_content_with_min_word_count($message, $api_key);
                sleep(12);
                $new_content = strtolower(trim($new_content));


                // Array of words to search for in the response
                $search_words = array('of course', 'certainly', 'sure', 'help', 'questions', 'assisting', 'tasks', 'today', 'assist');

                // Check if any of the words exist in the response
                $found_words = array();
                foreach ($search_words as $word) {
                    if (strpos($new_content, $word) !== false) {
                        $found_words[] = $word;
                    }
                }

                if (!empty($found_words)) {
                    // Some of the words were found in the $response
                    // for debugging
                    error_log('The response contains the following words: ' . print_r(implode(', ', $found_words), true)."\n", 3, CUSTOM_LOG_PATH);
                    // Log the response for debugging

                    // right place for unset ?
                    unset($found_words);
                    // mzug latest update
                    //puplish_now($post->ID,$generated_content);
                    append_content_to_post($post->ID,$generated_content);
                    break; // 11/09
                    //continue; it's the right solution to berak the while loop

                } else {
                    // None of the words were found in the $response
                    error_log('The response does not contain any of the specified words: '."\n", true, 3, CUSTOM_LOG_PATH);

                    $generated_content .= $new_content;
                    $word_count = count_words($generated_content);
                }
                // emptty after $message = "continue" every time
                unset($found_words);

                //The latest content has ' . $word_count . ' words. 
                $question = 'Are you done? Answer with YES or NO'; 
                $response = generate_content_with_min_word_count($question, $api_key);
                sleep(3);

                // Convert the response to lowercase for better comparison
                $response = strtolower(trim($response));

                if ($response === '<p>yes</p>' or $response === '<p>yes.</p>') {
                    // The user (ChatGPT) is done generating content
                    //puplish_now($post->ID,$generated_content);
                    append_content_to_post($post->ID,$generated_content);
                    continue; // 11/09 replace break with continue

                } elseif ($response === '<p>no</p>' or $response === '<p>no.</p>') {
                    // The user (ChatGPT) is not done, ask to continue generating content
                    $message = "please continue";
                    //$filtered_content = chatgpt_ava_truncate_content($message, $max_tokens);
                    $new_content_2 = generate_content_with_min_word_count($message, $api_key);
                    sleep(12);


                    foreach ($search_words as $word) {
                        if (strpos($new_content_2, $word) !== false) {
                            $found_words[] = $word;
                        }
                    }

                    if (!empty($found_words)) {
                        // Some of the words were found in the $response
                        // for debugging
                        error_log('The response contains the following words: 2' . print_r(implode(', ', $found_words), true)."\n", 3, CUSTOM_LOG_PATH);
                            // Log the response for debugging  

                        unset($found_words);
                        //puplish_now($post->ID,$generated_content);
                        append_content_to_post($post->ID,$generated_content);
                        continue; // 11/09 replace ...

                    } else {
                        // None of the words were found in the $response
                        error_log('The response does not contain any of the specified words: 2'."\n", true, 3, CUSTOM_LOG_PATH);

                        $generated_content .= $new_content_2;
                        $word_count = count_words($generated_content);
                                //puplish_now($post->ID,$generated_content); //mistake ???
                    }
                    // emptty after $message = "continue" every time
                    unset($found_words);
                // raear to happen here this else case...1/aug / not very sure
                } else {
                    // Log the error for debugging
                    error_log('ChatGPT YES NO Error: ' . $post->ID, 3, CUSTOM_LOG_PATH);

                    //error_log('ChatGPT YES NO API Error: ' . $response->get_error_message()); 
                    // for debugging
                    error_log('ChatGPT YES NO API Error: ' . print_r($response, true), 3, CUSTOM_LOG_PATH);
                        // Log the response for debugging               
                    wp_update_post(array('ID' => $post->ID, 'post_status' => 'draft'));
                }
            }   
        }       
        // Update the post with the generated content and change post status to publish
        elseif ($word_count > $min_word_count) {
            // suppose he didn't finish ??? here ... mzug
            $updated_post = array(
                'ID' => $post->ID,
                'post_content' => $generated_content, // Use the content with empty lines
                'post_status' => 'publish',
            );
            //wp_update_post($updated_post);
            append_content_to_post($post->ID, $generated_content); // this is 1'st target one?!?
        } else {
            // Log the error for debugging / it's not logical to be here...
            error_log('--not logical--Content generation failed for private post ID: ' . $post->ID."\n", 3, CUSTOM_LOG_PATH);

            wp_update_post(array('ID' => $post->ID, 'post_status' => 'draft'));
        }

    }

    // Get private posts
    $private_posts = get_posts(array(
        'post_status' => 'private',
        'posts_per_page' => -1,
    ));

    // Implement API call to ChatGPT for each private post
    foreach ($private_posts as $post) { 

        // make sure the img is exist, in some cases orginal post insert video in place of feature img 
        // Check if the post has a featured image
        if (!has_featured_image($post->ID)) {
            // If the post doesn't have a featured image, delete and skip this post
            wp_delete_post($post->ID, true);
            continue;
            // Rest of the code to generate and update content based on the API response...
        } else {
            // filter added text like more news ...
            //$filterd_content = remove_custom_news($post->post_content);
            $filterd_content = test_ava_remove_custom_paragraphs($post->post_content);
            
            wp_update_post(array(
                'ID' => $post->ID,
                'post_content' => $filterd_content, // it'should be like that only without old content it's for filtreation.
            ));

            error_log('content filteration saved successfully!'."\n", 3, CUSTOM_LOG_PATH);

            // if <p> filteration return empty article cause of any Special characters issues inside article
            //  I don't have to apply nested code anymore, it's fine to break evrything with this type of posts / Special characters
            // stop before send to API
            if (empty($filterd_content)) {  
                wp_trash_post($post->ID, true);
                error_log('Error, empty->filterd_content '."\n", 3, CUSTOM_LOG_PATH);
                //break; //11/09  // now this case will never happen, it's useless code
            }
            // Let's treminate articles less than 115 Word in total, cuase ChatGPT can convert 115 to 200 word fine...For example        
            elseif (!count_and_manage_posts($post->ID, $filterd_content) ){
                error_log('+~~~~~~~post handeled~~~~~~~~~~+'."\n", 3, CUSTOM_LOG_PATH);
                continue;
            } else {
                error_log('+~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~+'."\n", 3, CUSTOM_LOG_PATH);
                // title regenerate content
                $title = $post->post_title;
                $message_title = "Using Arabic language rewrite {$title} with limit of 68 character in total"; // rewrite // reparaphras ,, 
                $generated_title = generate_content_with_min_word_count($message_title, $api_key);
                //regenerate_post_title($post->ID,$generated_title);
                // If empty title stop
                if (!regenerate_post_title($post->ID,$generated_title)) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_status' => 'draft'
                    ));
                    error_log('(^_*) Draft due error ... '."\n", 3, CUSTOM_LOG_PATH);
                    $draft_posts = get_option('draft_posts_array', array());
                    $draft_posts[] = $post_id;
                    update_option('draft_posts_array', $draft_posts);
                    continue; // 11/09 replace 
                }
                error_log('~old: ' . print_r($title, true)."\n", 3, CUSTOM_LOG_PATH);
                error_log('~new: ' . print_r($generated_title, true)."\n", 3, CUSTOM_LOG_PATH);

                $post_content = $filterd_content;
                // Limit the content length if needed
                $max_tokens = 3310; // Model's maximum context length
                //$filtered_content = chatgpt_ava_truncate_content($post_content, $max_tokens);                
                
                // Convert content to UTF-8 if needed
                $contentUTF8 = mb_convert_encoding($post_content, 'UTF-8', 'auto');
                
                // Example usage
                $split_blocks = filter_post_content($contentUTF8);

                error_log('~~hell!:( ' . print_r($split_blocks, true)."\n", 3, CUSTOM_LOG_PATH);

                sleep(2);
                // generate keyphrases to use it when writing the post...
                $generated_keyphrase = generate_and_set_focus_keyphrase($post->ID, $api_key);
                error_log('~-~: ' . print_r($generated_keyphrase, true)."\n", 3, CUSTOM_LOG_PATH);
                // the idea is to skip the current loop if there is an error with keyphrase...
                if (!$generated_keyphrase) {
                    error_log('~continue~ed next ittertion'."\n", 3, CUSTOM_LOG_PATH);
                    continue;
                }
                // future update to stop this if post is acaully deleted due to previous erroe                 
                error_log('generated_keyphrase ::' . print_r($generated_keyphrase, true)."\n" , 3, CUSTOM_LOG_PATH);

                // Update the post with an empty content so we freash 
                $updated_post = array(
                    'ID' => $post->ID,
                    'post_content' => '',
                );
                wp_update_post($updated_post);
                
                error_log('~~~~~~~~<Content Started>~~~~~~~~~~'."\n", 3, CUSTOM_LOG_PATH);
                
                $flagey = 1;
                foreach ($split_blocks as $item) {
                    
                    $blockContent = $item['content'];
                    $containsImage = $item['containsImage'];

                    if ($item['type'] === 'image') {
                    //if ($containsImage) {
                        // Handle the block with an image
                        // Extract the image content and insert it into the post
                        $imageBlock = $item['containsImage'];
                        append_content_to_post($post->ID, $imageBlock);
                    } else {
                        // Handle the block without an image
                        //..old...$message = "Reparaphras the previous article with using {$generated_keyphrase} as the Focus keyphrase, and make sure to use the exact keyphrase twice in content, covering it to become more than 320 words in total using the Arabic language. Structure the article with clear headings enclosed within the appropriate heading tags (e.g., <h1>, <h2>, etc.) and generate two subtopics inside the article to use subheadings, each one of them should have at least one paragraph. Make sure to use keyphrase in the subheadings and use a cohesive structure to ensure smooth transitions between ideas using enough transition words, while writing focus on the SEO score of Yoast and the readability score. Make it coherent and proficient. Remember to (1) enclose headings in the specified heading tags to make parsing the content easier and to improve SEO use keyphrase in one subheadings. (2) Wrap even paragraphs in <p> tags for improved readability. (3) Make sure that 25% of the sentences you write contain less than 20 words. (4) Insert an internal link to visit our site https://wedti.com and another one to follow on social media https://www.instagram.com/webwedti or facebook @webwedti";
                        $message = "Reparaphrase this {$blockContent} of the article using the Arabic language with these words ({$generated_keyphrase}) as the focus keyphrase.";
                        // Generate content and check word count until it meets the minimum requirement
                        $generated_content = generate_content_with_min_word_count($message, $api_key);
                        sleep(12);
    
                        $word_count = count_words($generated_content);
                        error_log('The response for part '. $flagey . ' api word count is: ' . print_r($word_count, true)."\n", 3, CUSTOM_LOG_PATH);
                        //error_log('The response contains the following words: ' . print_r(implode(', ', $found_words), true)."\n", 3, CUSTOM_LOG_PATH);
        
                        // Ask ChatGPT to continue generating content until it reaches the minimum word count
                        check_response_code($word_count, $api_key, $post, $generated_content);
                    }                    
                    $flagey += 1;
                }
                
                error_log('~~~~~~~~~<Content Ended>~~~~~~~~~~~'."\n", 3, CUSTOM_LOG_PATH);



                //$message = "rewrite this article {$post_content}, covering it to become less than 300 words in total using the Arabic language. Use a cohesive structure to ensure smooth transitions between ideas, focus on summarizing and shortening the content, and make sure it's at least not less than 250 words. Make it coherent and proficient.";

                //$message = "Rewrite this article {$filtered_content}, covering it to become less than 400 words in total using the Arabic language. Structure the article with clear headings enclosed within the appropriate heading tags (e.g., <h1>, <h2>, etc.) and generate subtopics inside the article to use subheadings, each one of them should have at least one paragraph. Use a cohesive structure to ensure smooth transitions between ideas, focus on summarizing and shortening the content, and make sure it's at least not less than 300 words. Make it coherent and proficient. Remember to (1) enclose headings in the specified heading tags to make parsing the content easier. (2) Wrap even paragraphs in <p> tags for improved readability. (3) make sure that 25% of the sentences you write contain less than 20 words.";
                
                //$message = "Reparaphras the previous article with using {$generated_keyphrase} as the Focus keyphrase, and make sure to use the exact keyphrase twice in content, covering it to become more than 320 words in total using the Arabic language. Structure the article with clear headings enclosed within the appropriate heading tags (e.g., <h1>, <h2>, etc.) and generate two subtopics inside the article to use subheadings, each one of them should have at least one paragraph. Make sure to use keyphrase in the subheadings and use a cohesive structure to ensure smooth transitions between ideas using enough transition words, while writing focus on the SEO score of Yoast and the readability score. Make it coherent and proficient. Remember to (1) enclose headings in the specified heading tags to make parsing the content easier and to improve SEO use keyphrase in one subheadings. (2) Wrap even paragraphs in <p> tags for improved readability. (3) Make sure that 25% of the sentences you write contain less than 20 words. (4) Insert an internal link to visit our site https://wedti.com and another one to follow on social media https://www.instagram.com/webwedti or facebook @webwedti";

                //error_log('____________________' ."\n", 3, CUSTOM_LOG_PATH);

                // Get the Yoast SEO object for the post.
                //$yoast_seo = new WPSEO_SEO_Data($post->ID);

                //error_log(print_r($yoast_seo, true)."\n", 3, CUSTOM_LOG_PATH);

                // Get the notes from Yoast SEO.
                //$notes = $yoast_seo->get_notes();

                //error_log('____________________' ."\n", 3, CUSTOM_LOG_PATH);
                //error_log(print_r($notes, true)."\n", 3, CUSTOM_LOG_PATH);

                //$message = "Rewrite this article {$filtered_content} with using of {$generated_keyphrase} as Focus keyphrase, covering it to become more than 302 words in total using the Arabic language. Structure the article with clear headings enclosed within the appropriate heading tags (e.g., <h1>, <h2>, etc.) and generate two subtopics inside the article to use subheadings, each one of them should have at least one paragraph. Use a cohesive structure to ensure smooth transitions between ideas, while writing focus on SEO score of Yoast and readability score. Make it coherent and proficient. Remember to (1) enclose headings in the specified heading tags to make parsing the content easier. (2) Wrap even paragraphs in <p> tags for improved readability. (3) make sure that 25% of the sentences you write contain less than 20 words. (4) insert internal link in the post as outgoing and other one for internal site wedti.com";

                            
                // update title for seo...
                $SEO_title = "Write SEO title for previous article containing exact keyphrase of \"{$generated_keyphrase}\" with limit of 70 character in total using the Arabic language. make sure to add name of site \" Wedti.com \" in the tilte.";
                $new_seo_title = generate_content_with_min_word_count($SEO_title, $api_key);
                // try again if fail with some api error
                if ($new_seo_title == false) {
                    sleep(5);
                    $new_seo_title = generate_content_with_min_word_count($SEO_title, $api_key);
                }
                error_log('new_seo_title ::' . print_r($new_seo_title, true)."\n" , 3, CUSTOM_LOG_PATH);

                $new_seo_title = checkTitleDescLength($new_seo_title);

                error_log('checkTitleDescLength() ::' . print_r($new_seo_title, true)."\n" , 3, CUSTOM_LOG_PATH);

                $new_seo_title = checkKeyphraseInSeoTitle($generated_keyphrase, $new_seo_title);

                error_log('checkKeyphraseInSeoTitle() ::' . print_r($new_seo_title, true)."\n" , 3, CUSTOM_LOG_PATH);

                $new_seo_title = checkTitleDescLength($new_seo_title); // should do again, cuase it make new err with long title...

                error_log('checkTitleDescLength() round 2::' . print_r($new_seo_title, true)."\n" , 3, CUSTOM_LOG_PATH);

                update_yoast_seo_title($post->ID, $new_seo_title);

                // 24-09
                //$update_data = array('ID'    => $post->ID);
                //$update_result = wp_update_post($update_data);
                //error_log('wp_update_post() ::' . print_r($update_result, true)."\n" , 3, CUSTOM_LOG_PATH);

                // update the description meta and fix it
                $SEO_description = "Write SEO Meta description for previous article containing title and exact keyphrase of \"{$generated_keyphrase}\" and make sure to use min limit character as 105 and max as 125 character in total using the Arabic language.";
                $new_SEO_description = generate_content_with_min_word_count($SEO_description, $api_key);
                // try again if fail with some api error
                if ($new_SEO_description == false) {
                    sleep(5);
                    $new_SEO_description = generate_content_with_min_word_count($SEO_description, $api_key);
                }
                error_log('$new_SEO_description ::' . print_r($new_SEO_description, true)."\n" , 3, CUSTOM_LOG_PATH);

                $new_SEO_description = checkMetaDescLength($new_SEO_description);

                error_log('checkMetaDescLength() ::' . print_r($new_SEO_description, true)."\n" , 3, CUSTOM_LOG_PATH);

                $new_SEO_description = checkKeyphraseInMetaDescription($generated_keyphrase, $new_SEO_description);

                error_log('checkKeyphraseInMetaDescription() ::' . print_r($new_SEO_description, true)."\n" , 3, CUSTOM_LOG_PATH);

                update_yoast_seo_meta_description($post->ID, $new_SEO_description);

                //triger_seo_analysis($post->ID);
                //wpseo_analyze_post($post->ID); // note/issue on papers number 6 / future update

                // fixing alt seo img thing
                modify_featured_image_alt( $post->ID, $generated_keyphrase );
            }
        }
        error_log('*----Reach the end of foreach >> post ID: ' . $post->ID."\n", 3, CUSTOM_LOG_PATH);
        sleep(3);
    }
}


// Schedule the cron job
add_action('chatgpt_ava_private_rewrite_cron', 'chatgpt_ava_private_rewrite');

// Add custom cron schedule interval to run every 15 minutes
add_filter('cron_schedules', 'chatgpt_ava_add_custom_cron_interval');
function chatgpt_ava_add_custom_cron_interval($schedules)
{
    $schedules['every_fifteen_minutes'] = array(
        'interval' => 900, // 15 minutes (in seconds)
        'display' => __('Every 15 minutes GPT'),
    );
    return $schedules;
}
