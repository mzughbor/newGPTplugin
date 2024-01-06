<?

function filter_post_content($content) {
    // Split the content into blocks based on headings
    $blocks = preg_split('/<h[1-6].*?>/', $content);

    // Remove empty blocks
    $blocks = array_filter($blocks);

    $filtered_content = '';

    foreach ($blocks as $block) {
        // Get the heading and its paragraph
        preg_match('/<h[1-6].*?>(.*?)<\/h[1-6]>/s', $block, $matches);

        if (!empty($matches[1])) {
            // If there is a heading, include it and the paragraph
            $heading = $matches[0];
            $paragraph = substr($block, strlen($matches[0]));

            // Check if the total length is less than or equal to 2550 characters
            if (strlen($heading . $paragraph) <= 2550) {
                $filtered_content .= $heading . $paragraph;
            } else {
                // If the total length exceeds 2550 characters, truncate the paragraph
                $truncated_paragraph = substr($paragraph, 0, 2550 - strlen($heading));
                $filtered_content .= $heading . $truncated_paragraph;
            }
        } else {
            // If there is no heading, include the entire paragraph
            $filtered_content .= $block;
        }
    }

    return $filtered_content;
}

// Hook the function to the_content filter
add_filter('the_content', 'filter_post_content');
