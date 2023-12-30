<?php
/*
Plugin Name: ChatGPT with Ava - Private Rewrites
Description: Rewrite private post content using ChatGPT API as a cron job.
Version: 1.0
Author: mZughbor
*/

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
            ?>
        </form>
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
        $tokens = str_word_count($content, 1);
        $total_tokens = count($tokens);
        if ($total_tokens > $max_tokens) {
            $content = implode(' ', array_slice($tokens, 0, $max_tokens));
        }
        return $content;
    }

    // Limit the content length if needed
    $max_tokens = 3770; // Model's maximum context length
    $filtered_content = chatgpt_ava_truncate_content($message, $max_tokens);

    // Insert your ChatGPT API call here using the chat completions endpoint
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'timeout' => 30, // Increase the timeout value
        'body' => json_encode(array(
            'prompt' => $filtered_content, // Use 'prompt' instead of 'messages'
            'max_tokens' => 3000, // Adjust as needed
            'model' => 'gpt-3.5-turbo', // Use the Ada model name here // gpt-3.5-turbo // text-davinci-003
            'temperature' => 0.8, // Control randomness (optional, adjust as needed)
        )),
    ));

    if (is_wp_error($response)) {
        $output = $response->get_error_message();
    } else {
        $response_body = json_decode($response['body'], true);

        if (isset($response_body['choices']) && is_array($response_body['choices']) && !empty($response_body['choices'])) {
            $output = $response_body['choices'][0]['message']['content'];
        } else {
            $output = 'Generated content from ChatGPT';
        }
    }

    echo $output;
    wp_die();
}



// Function to handle private rewrites and schedule it as a cron job
function chatgpt_ava_schedule_private_rewrites()
{
    if (!wp_next_scheduled('chatgpt_ava_private_rewrite_cron')) {
        wp_schedule_event(time(), 'every_fifteen_minutes', 'chatgpt_ava_private_rewrite_cron');
    }
}
add_action('wp', 'chatgpt_ava_schedule_private_rewrites');

// Function to find private posts and rewrite their content
// Function to find private posts and rewrite their content
// Function to find private posts and rewrite their content
function chatgpt_ava_private_rewrite()
{
    $api_key = get_option('chatgpt_ava_api_key');

    // Helper function to truncate the content to fit within the token limit
    function chatgpt_ava_truncate_content($content, $max_tokens)
    {
        // Truncate the content to fit within the token limit
        $tokens = str_word_count($content, 1);
        $total_tokens = count($tokens);
        if ($total_tokens > $max_tokens) {
            $content = implode(' ', array_slice($tokens, 0, $max_tokens));
        }
        return $content;
    }
    
    
    
    // Function to count tokens using the ChatGPT API
    function count_tokens($text, $api_key)
    {
        $response = wp_remote_post('https://api.openai.com/v1/tokenizations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30, // Increase the timeout value
            'body' => json_encode(array(
                'texts' => array($text),
                'model' => 'gpt-3.5-turbo',
            )),
        ));

        if (is_wp_error($response)) {
            return 0;
        } else {
            $response_body = json_decode($response['body'], true);
            if (isset($response_body['lengths'][0])) {
                return $response_body['lengths'][0];
            }
        }

        return 0;
    }
    
    
    // Get private posts
    $private_posts = get_posts(array(
        'post_status' => 'private',
        'posts_per_page' => -1,
    ));

    // Implement API call to ChatGPT for each private post
    foreach ($private_posts as $post) {
        $post_content = $post->post_content;
        // Updated $message to target 300 words using the Ada model
        $message = "rewrite this article {$post_content}, covering it to become less than 300 word in total using the Arabic language. Use a cohesive structure to ensure smooth transitions between ideas, focus on summarizing and shorten the content, and make sure it'll be at least not less than 250 word, make it hot and proficient";

        // Limit the content length if needed
        $max_tokens = 3770; // Model's maximum context length
        $filtered_content = chatgpt_ava_truncate_content($message, $max_tokens);

        // Insert your ChatGPT API call here using the chat completions endpoint
        // 
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30, // Increase the timeout value
            'body' => json_encode(array(
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are a helpful assistant.'),
                    array('role' => 'user', 'content' => $filtered_content),
                ),
                'model' => 'gpt-3.5-turbo', // Use the chat model name here (Ada)
            )),
        ));
        
        if (is_wp_error($response)) {
            error_log('ChatGPT API Error: ' . $response->get_error_message());
            // Convert the failed post back to draft
            wp_update_post(array('ID' => $post->ID, 'post_status' => 'draft'));
            // Move on to the next post
            continue;            
        } else {
            $response_body = json_decode($response['body'], true);

            if (isset($response_body['choices']) && is_array($response_body['choices']) && !empty($response_body['choices'])) {
                $generated_content = $response_body['choices'][0]['message']['content'];
                //$generated_content = $response_body['choices'][0]['text'];

                // Update the post with the generated content and change post status to publish
                $updated_post = array(
                    'ID' => $post->ID,
                    'post_content' => $generated_content,
                    'post_status' => 'publish',
                );
                wp_update_post($updated_post);
            } else {
                // Log the entire response for debugging
                error_log('ChatGPT API Response Error: ' . print_r($response_body, true));
                // Convert the failed post back to draft
                wp_update_post(array('ID' => $post->ID, 'post_status' => 'draft'));                
            }
        }
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
        'display' => __('Every 15 minutes'),
    );
    return $schedules;
}
