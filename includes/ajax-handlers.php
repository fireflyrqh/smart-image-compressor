<?php
/**
 * Ajax处理函数
 */
if (!defined('ABSPATH')) exit;

// 引入日志类
require_once dirname(__FILE__) . '/class-image-compressor-logger.php';

/**
 * 处理批量压缩请求
 */
function image_compressor_ajax_batch_compress() {
    // 检查安全性
    check_ajax_referer('image_compressor_nonce', 'nonce');
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('无权限执行此操作', 'image-compressor')));
        return;
    }
    
    // 获取当前批次和大小
    $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
    $batch_size = 5; // 每批处理5个附件
    
    // 查询未压缩的图片
    $args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp'),
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'offset'         => $batch * $batch_size,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_image_compression_data',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => '_image_needs_recompression',
                'value'   => '1',
                'compare' => '=',
            ),
        ),
        'fields'         => 'ids',
    );
    
    $query = new WP_Query($args);
    $attachment_ids = $query->posts;
    
    // 如果没有更多图片
    if (empty($attachment_ids)) {
        wp_send_json_success(array(
            'message' => __('所有图片已压缩完成', 'image-compressor'),
            'done' => true,
            'processed' => 0,
            'total_processed' => $batch * $batch_size,
        ));
        return;
    }
    
    // 处理当前批次的图片
    $processed = 0;
    $compressor = new Image_Compressor();
    
    foreach ($attachment_ids as $attachment_id) {
        $original_size = filesize(get_attached_file($attachment_id));
        $result = $compressor->compress_attachment($attachment_id);
        
        if ($result) {
            $compressed_size = filesize(get_attached_file($attachment_id));
            $saved = $original_size - $compressed_size;
            $saved_percent = $original_size > 0 ? ($saved / $original_size) * 100 : 0;
            
            // 记录日志
            if (class_exists('Image_Compressor_Logger')) {
                $logger = Image_Compressor_Logger::get_instance();
                $logger->log(
                    'manual',
                    sprintf(
                        __('批量压缩: %s - 原大小 %s, 压缩后 %s, 节省 %s (%.2f%%)', 'image-compressor'),
                        basename(get_attached_file($attachment_id)),
                        $logger->format_size($original_size),
                        $logger->format_size($compressed_size),
                        $logger->format_size($saved),
                        $saved_percent
                    ),
                    get_attached_file($attachment_id),
                    $original_size,
                    $compressed_size
                );
            }
            
            $processed++;
        }
    }
    
    // 获取可能仍需处理的图片总数
    $remaining_args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp'),
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_image_compression_data',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => '_image_needs_recompression',
                'value'   => '1',
                'compare' => '=',
            ),
        ),
        'fields'         => 'ids',
    );
    
    $remaining_query = new WP_Query($remaining_args);
    $remaining = count($remaining_query->posts) - $processed;
    
    // 返回响应
    $total_processed = $batch * $batch_size + $processed;
    $total = $remaining + $total_processed;
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('已处理 %d 张图片，总计 %d 张，剩余 %d 张', 'image-compressor'),
            $processed,
            $total,
            $remaining
        ),
        'done' => false,
        'processed' => $processed,
        'total_processed' => $total_processed,
        'remaining' => $remaining,
        'total' => $total,
        'progress' => $total > 0 ? round(($total_processed / $total) * 100) : 100,
    ));
}
add_action('wp_ajax_image_compressor_batch_compress', 'image_compressor_ajax_batch_compress');

/**
 * 处理单个图片压缩请求
 */
function image_compressor_ajax_compress_single() {
    // 检查安全性
    check_ajax_referer('image_compressor_nonce', 'nonce');
    
    // 检查权限
    if (!current_user_can('upload_files')) {
        wp_send_json_error(array('message' => __('无权限执行此操作', 'image-compressor')));
        return;
    }
    
    // 获取附件ID
    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    if (!$attachment_id) {
        wp_send_json_error(array('message' => __('无效的附件ID', 'image-compressor')));
        return;
    }
    
    // 执行压缩
    $compressor = new Image_Compressor();
    $result = $compressor->compress_attachment($attachment_id);
    
    if ($result) {
        // 获取压缩信息
        $compression_data = get_post_meta($attachment_id, '_image_compression_data', true);
        $original_size = isset($compression_data['original_size']) ? $compression_data['original_size'] : 0;
        $compressed_size = isset($compression_data['compressed_size']) ? $compression_data['compressed_size'] : 0;
        $saved = $original_size - $compressed_size;
        $percent = $original_size > 0 ? round(($saved / $original_size) * 100) : 0;
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('压缩成功！节省了 %s (%d%%)', 'image-compressor'),
                size_format($saved),
                $percent
            ),
            'original_size' => size_format($original_size),
            'compressed_size' => size_format($compressed_size),
            'saved_size' => size_format($saved),
            'percent' => $percent,
        ));
    } else {
        wp_send_json_error(array('message' => __('压缩失败，请稍后再试', 'image-compressor')));
    }
}
add_action('wp_ajax_image_compressor_compress_single', 'image_compressor_ajax_compress_single');
