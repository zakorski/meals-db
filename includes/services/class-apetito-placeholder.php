<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One shared "Photo coming soon" media attachment for Apetito-pulled products.
 * Created once, reused forever via an option. Products created without a real
 * photo get this as their thumbnail and a _mealsdb_placeholder_image=1 meta so
 * an "awaiting photos" list is a meta query, not an image comparison.
 */
class MealsDB_Apetito_Placeholder {

    private const OPTION = 'mealsdb_apetito_placeholder_id';
    private const ASSET  = 'assets/images/photo-coming-soon.png';

    /** @return int attachment ID, or 0 on failure. */
    public static function get_or_create(): int {
        $existing = (int) get_option(self::OPTION, 0);
        if ($existing > 0 && function_exists('wp_attachment_is_image') && wp_attachment_is_image($existing)) {
            return $existing;
        }

        // Create the attachment by copying the bundled asset into uploads.
        if (!function_exists('wp_upload_dir') || !function_exists('wp_insert_attachment')) {
            return 0;
        }
        $src = defined('MEALS_DB_PLUGIN_DIR') ? MEALS_DB_PLUGIN_DIR . self::ASSET : dirname(__DIR__, 2) . '/' . self::ASSET;
        if (!is_readable($src)) { return 0; }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) { return 0; }
        $dest = trailingslashit($uploads['path']) . 'mealsdb-photo-coming-soon.png';
        if (!@copy($src, $dest)) { return 0; }

        $filetype = wp_check_filetype(basename($dest), null);
        $attachment = [
            'guid'           => trailingslashit($uploads['url']) . basename($dest),
            'post_mime_type' => $filetype['type'] ?: 'image/png',
            'post_title'     => __('Photo coming soon', 'meals-db'),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $dest);
        if (is_wp_error($attach_id) || !$attach_id) { return 0; }

        if (function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata($attach_id, $dest);
            wp_update_attachment_metadata($attach_id, $meta);
        }

        update_option(self::OPTION, (int) $attach_id);
        return (int) $attach_id;
    }
}
