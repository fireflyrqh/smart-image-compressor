<?php
/**
 * Plugin Name: 智能图片压缩器
 * Plugin URI: https://github.com/username/wordpress-image-compressor
 * Description: 自动压缩上传的大图片，图片越大压缩率越高。支持后台一键压缩现有图片库。
 * Version: 1.0.0
 * Author: IT小埋
 * Author URI: https://github.com/username
 * Text Domain: image-compressor
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// 防止直接访问此文件
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('IMAGE_COMPRESSOR_VERSION', '1.0.0');
define('IMAGE_COMPRESSOR_PLUGIN_FILE', __FILE__);
define('IMAGE_COMPRESSOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMAGE_COMPRESSOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IMAGE_COMPRESSOR_ASSETS_URL', IMAGE_COMPRESSOR_PLUGIN_URL . 'assets/');
define('IMAGE_COMPRESSOR_THRESHOLD_SIZE', 500 * 1024); // 500KB 的阈值

// 激活插件时执行
register_activation_hook(__FILE__, 'image_compressor_activate');
function image_compressor_activate() {
    // 创建默认设置
    $default_options = array(
        'threshold_size' => 500, // KB
        'jpeg_quality' => 80,
        'png_compression' => 8,
        'webp_quality' => 80,
        'enable_webp' => true,
        'auto_compress' => true,
        'compress_original' => true,
        'compression_algorithm' => 'adaptive', // 自适应压缩算法
    );
    
    // 只有在选项不存在时才添加
    if (!get_option('image_compressor_settings')) {
        add_option('image_compressor_settings', $default_options);
    }
}

// 停用插件时清理
register_deactivation_hook(__FILE__, 'image_compressor_deactivate');
function image_compressor_deactivate() {
    // 清理定时任务
    wp_clear_scheduled_hook('image_compressor_batch_process');
    wp_clear_scheduled_hook('image_compressor_cleanup_logs');
    
    // 清理插件可能创建的临时文件
    $upload_dir = wp_upload_dir();
    if (isset($upload_dir['basedir'])) {
        $temp_dir = $upload_dir['basedir'] . '/image-compressor-temp';
        
        if (file_exists($temp_dir) && is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($temp_dir);
        }
    }
}

// 卸载插件时执行
register_uninstall_hook(__FILE__, 'image_compressor_uninstall');
function image_compressor_uninstall() {
    // 删除插件设置
    delete_option('image_compressor_settings');
    delete_option('image_compressor_stats');
    
    // 清理可能存在的任何任务队列
    wp_clear_scheduled_hook('image_compressor_batch_process');
}

// 包含必要的文件
require_once IMAGE_COMPRESSOR_PLUGIN_DIR . 'includes/class-image-compressor.php';
require_once IMAGE_COMPRESSOR_PLUGIN_DIR . 'includes/class-image-compressor-logger.php';
require_once IMAGE_COMPRESSOR_PLUGIN_DIR . 'admin/admin-page.php';
require_once IMAGE_COMPRESSOR_PLUGIN_DIR . 'includes/ajax-handlers.php';
require_once IMAGE_COMPRESSOR_PLUGIN_DIR . 'includes/shortcodes.php';

// 初始化插件
function image_compressor_init() {
    // 加载文本域
    load_plugin_textdomain('image-compressor', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // 初始化日志系统
    if (class_exists('Image_Compressor_Logger')) {
        $logger = Image_Compressor_Logger::get_instance();
    }
}
add_action('plugins_loaded', 'image_compressor_init');

// 挂钩上传图片的动作
function image_compressor_handle_upload($file) {
    // 获取日志记录器
    $logger = class_exists('Image_Compressor_Logger') ? Image_Compressor_Logger::get_instance() : null;
    if (!is_array($file) || !isset($file['file']) || !isset($file['size']) || !isset($file['type'])) {
        return $file;
    }
    
    $settings = get_option('image_compressor_settings');
    
    // 检查是否启用自动压缩
    if (!empty($settings['auto_compress'])) {
        $threshold_size = !empty($settings['threshold_size']) ? $settings['threshold_size'] * 1024 : IMAGE_COMPRESSOR_THRESHOLD_SIZE;
        
        // 检查文件大小是否超过阈值
        if ($file['size'] > $threshold_size && preg_match('/(jpg|jpeg|png|gif|webp)$/i', $file['type'])) {
            // 确保类已加载
            if (class_exists('Image_Compressor')) {
                $compressor = new Image_Compressor();
                $original_size = filesize($file['file']);
                $file = $compressor->compress_uploaded_image($file);
                
                // 记录日志
                if ($logger && file_exists($file['file'])) {
                    $compressed_size = filesize($file['file']);
                    $saved = $original_size - $compressed_size;
                    $saved_percent = $original_size > 0 ? ($saved / $original_size) * 100 : 0;
                    
                    $logger->log('auto', 
                        sprintf(
                            __('自动压缩新上传的图片: 原大小 %s, 压缩后 %s, 节省 %s (%.2f%%)', 'image-compressor'),
                            $logger->format_size($original_size),
                            $logger->format_size($compressed_size),
                            $logger->format_size($saved),
                            $saved_percent
                        ),
                        $file['file'],
                        $original_size,
                        $compressed_size
                    );
                }
            }
        }
    }
    
    return $file;
}
add_filter('wp_handle_upload', 'image_compressor_handle_upload');

// 加载管理界面所需的JS和CSS
function image_compressor_admin_enqueue_scripts($hook) {
    $media_pages = array('upload.php', 'media-new.php');
    $settings_page = 'media_page_image-compressor';
    
    if (in_array($hook, $media_pages) || $hook === $settings_page) {
        wp_enqueue_style('image-compressor-admin', IMAGE_COMPRESSOR_ASSETS_URL . 'css/admin-style.css', array(), IMAGE_COMPRESSOR_VERSION);
        wp_enqueue_script('image-compressor-admin', IMAGE_COMPRESSOR_ASSETS_URL . 'js/admin-script.js', array('jquery'), IMAGE_COMPRESSOR_VERSION, true);
        
        wp_localize_script('image-compressor-admin', 'imageCompressorParams', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('image_compressor_nonce'),
            'compressing' => __('正在压缩...', 'image-compressor'),
            'compress' => __('压缩', 'image-compressor'),
            'recompress' => __('重新压缩', 'image-compressor'),
            'error' => __('发生错误', 'image-compressor')
        ));
    }
}
add_action('admin_enqueue_scripts', 'image_compressor_admin_enqueue_scripts');
