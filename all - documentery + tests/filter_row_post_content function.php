<?php
    // Filteration function for all inclusions and exclusions like more news and so on...
    function filter_row_post_content($post_id){

        $post = get_post($post_id);

        $content = $post->post_content;
        
        // filter content Yallakora.com (1) case
        // Check if the content contains "اقرأ أيضاً.." text
        if (strpos($content, 'اقرأ أيضا:') !== false) {

            // Remove "اقرأ أيضاً.." text
            $content = preg_replace('/اقرأ أيضا:/', '', $content);
            error_log('----mm\'----'. $content ."\n", 3, CUSTOM_LOG_PATH);

            // Solving empty article return because of ' single quotation
            $content = str_replace("'", '[SINGLE_QUOTE]', $content); // Replace single quotation marks with a placeholder
            $content = str_replace('"', '[DOUBLE_QUOTE]', $content); // Replace double quotation marks with a placeholder

            error_log('----xx\'----'. $content ."\n", 3, CUSTOM_LOG_PATH);

            // Split content into paragraphs
            $paragraphs = explode('</p>', $content);
            error_log('----pp\'----'. $paragraphs ."\n", 3, CUSTOM_LOG_PATH);

            // Find paragraphs with <a> tags and remove following paragraphs
            $new_content = '';
            $inside_link_paragraph = false;
            foreach ($paragraphs as $paragraph) {
                error_log('----ff\'----' ."\n", 3, CUSTOM_LOG_PATH);
                if (strpos($paragraph, '<a') !== false) {
                    $inside_link_paragraph = true;
                    error_log('----if1\'----' ."\n", 3, CUSTOM_LOG_PATH);
                }
                if (!$inside_link_paragraph) {
                    $new_content .= $paragraph . '</p>';
                    error_log('----if2\'----' ."\n", 3, CUSTOM_LOG_PATH);                
                }
                if (strpos($paragraph, '</a>') !== false) {
                    $inside_link_paragraph = false;
                    error_log('----if3\'----' ."\n", 3, CUSTOM_LOG_PATH);                
                }
            }

            error_log('----------------------------------------' ."\n", 3, CUSTOM_LOG_PATH);
            // After processing, replace the placeholders back with single quotation marks
            $new_content = str_replace('[SINGLE_QUOTE]', "'", $new_content);
            $new_content = str_replace('[DOUBLE_QUOTE]', '"', $new_content);
            error_log('-- Yallakora case(1) --: ' . $new_content ."\n", 3, CUSTOM_LOG_PATH);

            // Check if filtered content is empty
            //if (empty($new_content)) {
            //    return $content; // Return original content if filtered content is empty
            //    error_log('** ALERT ************************* : '."\n", 3, CUSTOM_LOG_PATH);
            //}
            error_log('** ALERT ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^ : '."\n", 3, CUSTOM_LOG_PATH);
            return $new_content;            
        }
        error_log('** ALERT %%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% : '."\n", 3, CUSTOM_LOG_PATH);
        return $content; // Return original content if "اقرأ أيضاً.." text is not found
    }