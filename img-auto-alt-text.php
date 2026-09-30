<?php
/**
 * Plugin Name: MG Auto Alt Text
 * Description: Bulk manage image alt text with search, pagination, filters, and editable fields.
 * Version: 1.5.0
 * Author: Mark Gil Sumanlad
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'mg_auto_alt_text_menu');

function mg_auto_alt_text_menu() {
    add_media_page(
        'MG Auto Alt Text',
        'MG Auto Alt Text',
        'manage_options',
        'mg-auto-alt-text',
        'mg_auto_alt_text_page'
    );
}

function mg_auto_alt_text_page() {
    if (isset($_POST['mg_save_alt_text'])) {
        check_admin_referer('mg_save_alt_text_action');

        $updated = mg_save_custom_alt_texts();

        echo '<div class="notice notice-success"><p>Saved alt text for ' . esc_html($updated) . ' image(s).</p></div>';
    }

    $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'missing';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 25;

    if (!in_array($view, array('missing', 'all'), true)) {
        $view = 'missing';
    }

    $result = mg_get_images_by_view($view, $search, $paged, $per_page);
    $images = $result['images'];
    $total_items = $result['total'];
    $total_pages = max(1, ceil($total_items / $per_page));

    echo '<div class="wrap">';
    echo '<h1>MG Auto Alt Text</h1>';
    echo '<p>Manage image alt text from one simple screen. Search, filter, edit, and save image alt text.</p>';

    echo '<p>';
    echo '<a href="' . esc_url(admin_url('upload.php?page=mg-auto-alt-text&view=missing')) . '" class="button ' . ($view === 'missing' ? 'button-primary' : '') . '">Missing Alt Text Only</a> ';
    echo '<a href="' . esc_url(admin_url('upload.php?page=mg-auto-alt-text&view=all')) . '" class="button ' . ($view === 'all' ? 'button-primary' : '') . '">All Images</a>';
    echo '</p>';

    echo '<form method="get" style="margin-bottom:16px;">';
    echo '<input type="hidden" name="page" value="mg-auto-alt-text">';
    echo '<input type="hidden" name="view" value="' . esc_attr($view) . '">';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search filename or alt text..." style="min-width:320px;"> ';
    echo '<button type="submit" class="button">Search</button> ';
    if (!empty($search)) {
        echo '<a class="button" href="' . esc_url(admin_url('upload.php?page=mg-auto-alt-text&view=' . $view)) . '">Clear</a>';
    }
    echo '</form>';

    echo '<p><strong>' . esc_html($total_items) . '</strong> image(s) found.</p>';

    if (empty($images)) {
        echo '<div class="notice notice-info"><p>No images found for this view.</p></div>';
        echo '</div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('mg_save_alt_text_action');

    echo '<table class="widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width:90px;">Image</th>';
    echo '<th>Filename</th>';
    echo '<th>Alt Text</th>';
    echo '<th style="width:120px;">Status</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($images as $image) {
        $file_path = get_attached_file($image->ID);
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $suggested_alt = mg_clean_filename_for_alt($filename);
        $existing_alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
        $thumb = wp_get_attachment_image($image->ID, array(70, 70));

        $alt_value = !empty($existing_alt) ? $existing_alt : $suggested_alt;
        $status = !empty($existing_alt) ? 'Has Alt' : 'Missing';

        echo '<tr>';
        echo '<td>' . $thumb . '</td>';
        echo '<td>' . esc_html($filename) . '</td>';
        echo '<td>';
        echo '<input type="text" name="alt_text[' . esc_attr($image->ID) . ']" value="' . esc_attr($alt_value) . '" style="width:100%;" />';
        echo '</td>';
        echo '<td>';

        if ($status === 'Has Alt') {
            echo '<span style="color:green;font-weight:600;">Has Alt</span>';
        } else {
            echo '<span style="color:#b32d2e;font-weight:600;">Missing</span>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<p>';
    echo '<button type="submit" name="mg_save_alt_text" class="button button-primary">Save Alt Text</button>';
    echo '</p>';

    echo '</form>';

    mg_render_pagination($view, $search, $paged, $total_pages);

    echo '</div>';
}

function mg_get_images_by_view($view = 'missing', $search = '', $paged = 1, $per_page = 25) {
    $args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    $images = get_posts($args);
    $filtered_images = array();

    foreach ($images as $image) {
        $existing_alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
        $file_path = get_attached_file($image->ID);
        $filename = pathinfo($file_path, PATHINFO_FILENAME);

        if ($view === 'missing' && !empty($existing_alt)) {
            continue;
        }

        if (!empty($search)) {
            $search_lower = strtolower($search);
            $filename_lower = strtolower($filename);
            $alt_lower = strtolower($existing_alt);

            if (strpos($filename_lower, $search_lower) === false && strpos($alt_lower, $search_lower) === false) {
                continue;
            }
        }

        $filtered_images[] = $image;
    }

    $total = count($filtered_images);
    $offset = ($paged - 1) * $per_page;
    $paged_images = array_slice($filtered_images, $offset, $per_page);

    return array(
        'images' => $paged_images,
        'total'  => $total,
    );
}

function mg_render_pagination($view, $search, $paged, $total_pages) {
    if ($total_pages <= 1) {
        return;
    }

    echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 16px 0;">';

    $base_url = admin_url('upload.php?page=mg-auto-alt-text&view=' . $view);

    if (!empty($search)) {
        $base_url = add_query_arg('s', rawurlencode($search), $base_url);
    }

    if ($paged > 1) {
        echo '<a class="button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">&laquo;</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '">&lsaquo;</a> ';
    }

    echo '<span style="margin:0 8px;">Page ' . esc_html($paged) . ' of ' . esc_html($total_pages) . '</span>';

    if ($paged < $total_pages) {
        echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">&rsaquo;</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">&raquo;</a>';
    }

    echo '</div></div>';
}

function mg_save_custom_alt_texts() {
    if (empty($_POST['alt_text']) || !is_array($_POST['alt_text'])) {
        return 0;
    }

    $updated_count = 0;

    foreach ($_POST['alt_text'] as $attachment_id => $alt_text) {
        $attachment_id = absint($attachment_id);
        $alt_text = sanitize_text_field($alt_text);

        if (!$attachment_id) {
            continue;
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        $updated_count++;
    }

    return $updated_count;
}

function mg_clean_filename_for_alt($filename) {
    $text = str_replace(array('-', '_'), ' ', $filename);
    $text = preg_replace('/\d+/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    $text = ucwords($text);

    return $text;
}