<?php
/**
 * 图片压缩日志页面
 */
if (!defined('ABSPATH')) exit;

// 确保用户有权限访问此页面
if (!current_user_can('manage_options')) {
    wp_die(__('您没有权限访问此页面', 'image-compressor'));
}

// 获取日志记录器
$logger = class_exists('Image_Compressor_Logger') ? Image_Compressor_Logger::get_instance() : null;

// 处理日志清空操作
if (isset($_POST['action']) && $_POST['action'] === 'clear_logs' && check_admin_referer('clear_compression_logs')) {
    if ($logger) {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}image_compressor_logs");
        echo '<div class="notice notice-success"><p>' . __('日志已清空', 'image-compressor') . '</p></div>';
    }
}

// 获取日志和统计信息
$logs = $logger ? $logger->get_logs(100) : array();
$stats = $logger ? $logger->get_stats() : null;
?>

<div class="wrap">
    <h1><?php _e('图片压缩日志', 'image-compressor'); ?></h1>
    
    <?php if ($stats): ?>
    <div class="card">
        <h2><?php _e('压缩统计', 'image-compressor'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <td width="25%"><strong><?php _e('已压缩图片总数', 'image-compressor'); ?>:</strong></td>
                    <td><?php echo number_format($stats['total_compressed']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('原始总大小', 'image-compressor'); ?>:</strong></td>
                    <td><?php echo $logger->format_size($stats['total_original_size']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('压缩后总大小', 'image-compressor'); ?>:</strong></td>
                    <td><?php echo $logger->format_size($stats['total_compressed_size']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('总共节省', 'image-compressor'); ?>:</strong></td>
                    <td><?php 
                        echo $logger->format_size($stats['total_saved']) . ' (';
                        echo $stats['avg_saved_percent'] . '% ' . __('平均节省', 'image-compressor') . ')';
                    ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('最后压缩时间', 'image-compressor'); ?>:</strong></td>
                    <td><?php echo $stats['last_compressed'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stats['last_compressed'])) : __('无记录', 'image-compressor'); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2><?php _e('最近日志', 'image-compressor'); ?></h2>
        
        <form method="post" action="" style="margin-bottom: 20px;">
            <?php wp_nonce_field('clear_compression_logs'); ?>
            <input type="hidden" name="action" value="clear_logs">
            <?php submit_button(__('清空所有日志', 'image-compressor'), 'delete', 'submit', false, array(
                'onclick' => 'return confirm("' . esc_attr__('确定要清空所有日志吗？此操作不可撤销。', 'image-compressor') . '");'
            )); ?>
        </form>
        
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php 
                    printf(
                        _n('%s 条日志', '%s 条日志', count($logs), 'image-compressor'),
                        number_format(count($logs))
                    );
                ?></span>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%"><?php _e('日期', 'image-compressor'); ?></th>
                    <th width="10%"><?php _e('类型', 'image-compressor'); ?></th>
                    <th><?php _e('消息', 'image-compressor'); ?></th>
                    <th width="15%"><?php _e('节省空间', 'image-compressor'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->log_date)); ?></td>
                            <td>
                                <?php 
                                $type_labels = array(
                                    'auto' => __('自动', 'image-compressor'),
                                    'manual' => __('手动', 'image-compressor'),
                                    'error' => __('错误', 'image-compressor'),
                                    'info' => __('信息', 'image-compressor')
                                );
                                echo isset($type_labels[$log->log_type]) ? $type_labels[$log->log_type] : $log->log_type;
                                ?>
                            </td>
                            <td>
                                <?php echo esc_html($log->message); ?>
                                <?php if ($log->file_path): ?>
                                    <div class="row-actions">
                                        <span class="file-path">
                                            <?php echo esc_html(basename($log->file_path)); ?>
                                            <?php if (file_exists($log->file_path)): ?>
                                                (<?php echo $logger->format_size(filesize($log->file_path)); ?>)
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->original_size > 0 && $log->compressed_size > 0): ?>
                                    <?php 
                                    $saved = $log->original_size - $log->compressed_size;
                                    echo $logger->format_size($saved);
                                    echo ' (' . number_format($log->saved_percent, 2) . '%)';
                                    ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4"><?php _e('没有找到日志记录', 'image-compressor'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .progress {
        background-color: #f5f5f5;
        border-radius: 3px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1) inset;
        height: 20px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    .progress-bar {
        background-color: #0073aa;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        font-size: 12px;
        line-height: 20px;
        transition: width 0.6s ease;
    }
    .file-path {
        color: #666;
        font-size: 12px;
        font-style: italic;
    }
</style>
