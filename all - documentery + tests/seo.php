<?php

// Load the Yoast SEO plugin
// require_once 'wp-content/plugins/wordpress-seo/wp-seo.php';
require_once plugin_dir_path( __FILE__ ) . 'wordpress-seo/wp-seo.php';

// Get the current post ID
$post_id = get_the_ID();

// Get the SEO analysis results for the current post
$analysis_results = YoastSEO()->meta->for_post( $post_id )->get_results();

// Loop through the results and print them
foreach ( $analysis_results as $result ) {
    // Get the score, status, text, and marker of the result
    $score = $result->get_score();
    $status = $result->get_status();
    $text = $result->get_text();
    $marker = $result->get_marker();

    // Format the output
    echo "<p><strong>Score:</strong> $score</p>";
    echo "<p><strong>Status:</strong> $status</p>";
    echo "<p><strong>Text:</strong> $text</p>";
    echo "<p><strong>Marker:</strong> $marker</p>";
    echo "<hr>";
}
?>
