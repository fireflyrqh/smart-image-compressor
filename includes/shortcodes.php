<?php
/**
 * 图片压缩插件短代码
 */

if (!defined('ABSPATH')) exit;

/**
 * 注册短代码
 */
function image_compressor_register_shortcodes() {
    add_shortcode('image_compressor_stats', 'image_compressor_stats_shortcode');
}
add_action('init', 'image_compressor_register_shortcodes');

/**
 * 显示压缩统计信息的短代码
 * 
 * 使用示例: [image_compressor_stats show="saved,count,last_compressed" format="table"]
 * 
 * @param array $atts 短代码属性
 * @return string 生成的HTML
 */
function image_compressor_stats_shortcode($atts) {
    // 检查用户权限 - 只有登录用户才能查看
    if (!is_user_logged_in()) {
        return '<p>' . __('您需要登录才能查看压缩统计信息。', 'image-compressor') . '</p>';
    }
    
    // 默认属性
    $atts = shortcode_atts(array(
        'show' => 'saved,count,last_compressed', // 要显示的项目: saved, count, last_compressed, all
        'format' => 'table', // table, list, inline
        'title' => __('图片压缩统计', 'image-compressor'),
        'show_title' => 'yes',
    ), $atts, 'image_compressor_stats');
    
    // 获取日志记录器
    if (!class_exists('Image_Compressor_Logger')) {
        return '<p class="image-compressor-error">' . __('日志系统未初始化', 'image-compressor') . '</p>';
    }
    
    $logger = Image_Compressor_Logger::get_instance();
    $stats = $logger->get_stats();
    
    if (!$stats) {
        return '<p class="image-compressor-notice">' . __('暂无压缩统计信息', 'image-compressor') . '</p>';
    }
    
    // 确定要显示的项目
    $show_items = array_map('trim', explode(',', $atts['show']));
    if (in_array('all', $show_items)) {
        $show_items = array('saved', 'count', 'last_compressed');
    }
    
    // 准备数据
    $data = array();
    
    if (in_array('saved', $show_items)) {
        $data[__('节省空间', 'image-compressor')] = $logger->format_size($stats['total_saved']);
    }
    
    if (in_array('count', $show_items)) {
        $data[__('已压缩图片', 'image-compressor')] = number_format($stats['total_compressed']);
    }
    
    if (in_array('last_compressed', $show_items) && !empty($stats['last_compressed'])) {
        $data[__('最后压缩时间', 'image-compressor')] = date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'), 
            strtotime($stats['last_compressed'])
        );
    }
    
    // 生成输出
    $output = '';
    
    // 添加标题
    if ($atts['show_title'] !== 'no' && !empty($atts['title'])) {
        $output .= '<h3 class="image-compressor-stats-title">' . esc_html($atts['title']) . '</h3>';
    }
    
    // 根据格式生成内容
    switch ($atts['format']) {
        case 'list':
            $output .= '<ul class="image-compressor-stats-list">';
            foreach ($data as $label => $value) {
                $output .= sprintf(
                    '<li><strong>%s:</strong> %s</li>',
                    esc_html($label),
                    esc_html($value)
                );
            }
            $output .= '</ul>';
            break;
            
        case 'inline':
            $items = array();
            foreach ($data as $label => $value) {
                $items[] = sprintf(
                    '<span class="stat-item"><strong>%s:</strong> %s</span>',
                    esc_html($label),
                    esc_html($value)
                );
            }
            $output .= '<div class="image-compressor-stats-inline">' . implode(' | ', $items) . '</div>';
            break;
            
        case 'table':
        default:
            $output .= '<table class="image-compressor-stats-table">';
            $output .= '<tbody>';
            foreach ($data as $label => $value) {
                $output .= sprintf(
                    '<tr><th>%s</th><td>%s</td></tr>',
                    esc_html($label),
                    esc_html($value)
                );
            }
            $output .= '</tbody></table>';
            break;
    }
    
    // 添加样式
    $output .= '
    <style>
        .image-compressor-stats-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
        }
        .image-compressor-stats-table th,
        .image-compressor-stats-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .image-compressor-stats-table th {
            background-color: #f5f5f5;
            width: 30%;
        }
        .image-compressor-stats-list {
            list-style: none;
            padding: 0;
            margin: 1em 0;
        }
        .image-compressor-stats-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .image-compressor-stats-inline {
            margin: 1em 0;
        }
        .stat-item {
            margin-right: 15px;
            display: inline-block;
        }
        .image-compressor-stats-title {
            margin-bottom: 15px;
        }
        .image-compressor-notice {
            padding: 10px;
            background: #f8f9fa;
            border-left: 4px solid #2271b1;
        }
        .image-compressor-error {
            color: #dc3232;
            padding: 10px;
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
    </style>';
    
    return $output;
}
