<?php
if (!defined('ABSPATH')) exit;

// 添加管理菜单
add_action('admin_menu', 'image_compressor_admin_menu');

function image_compressor_admin_menu() {
    // 主菜单
    add_media_page(
        __('图片压缩器', 'image-compressor'),
        __('图片压缩器', 'image-compressor'),
        'manage_options',
        'image-compressor',
        'image_compressor_admin_page'
    );
    
    // 日志子菜单
    add_submenu_page(
        'upload.php',
        __('图片压缩日志', 'image-compressor'),
        __('压缩日志', 'image-compressor'),
        'manage_options',
        'image-compressor-logs',
        'image_compressor_logs_page'
    );
}

/**
 * 显示日志页面
 */
function image_compressor_logs_page() {
    require_once plugin_dir_path(dirname(__FILE__)) . 'admin/logs-page.php';
}

// 注册插件设置
function image_compressor_register_settings() {
    // 注册设置
    register_setting(
        'image_compressor_settings_group',  // 设置组名称
        'image_compressor_settings',        // 选项名称
        'image_compressor_sanitize_settings' // 验证回调函数
    );
    
    // 添加设置节
    add_settings_section(
        'image_compressor_main_section',
        __('压缩设置', 'image-compressor'),
        null,
        'image_compressor_settings_group'
    );
}
add_action('admin_init', 'image_compressor_register_settings');

// 验证和清理设置项
function image_compressor_sanitize_settings($input) {
    $sanitized = array();
    
    // 压缩阈值
    $sanitized['threshold_size'] = isset($input['threshold_size']) ? absint($input['threshold_size']) : 500;
    if ($sanitized['threshold_size'] < 100) $sanitized['threshold_size'] = 100;
    if ($sanitized['threshold_size'] > 10000) $sanitized['threshold_size'] = 10000;
    
    // JPEG质量
    $sanitized['jpeg_quality'] = isset($input['jpeg_quality']) ? absint($input['jpeg_quality']) : 80;
    if ($sanitized['jpeg_quality'] < 1) $sanitized['jpeg_quality'] = 1;
    if ($sanitized['jpeg_quality'] > 100) $sanitized['jpeg_quality'] = 100;
    
    // PNG压缩级别
    $sanitized['png_compression'] = isset($input['png_compression']) ? absint($input['png_compression']) : 8;
    if ($sanitized['png_compression'] < 0) $sanitized['png_compression'] = 0;
    if ($sanitized['png_compression'] > 9) $sanitized['png_compression'] = 9;
    
    // WebP质量
    $sanitized['webp_quality'] = isset($input['webp_quality']) ? absint($input['webp_quality']) : 80;
    if ($sanitized['webp_quality'] < 1) $sanitized['webp_quality'] = 1;
    if ($sanitized['webp_quality'] > 100) $sanitized['webp_quality'] = 100;
    
    // 布尔值选项
    $sanitized['auto_compress'] = isset($input['auto_compress']) ? 1 : 0;
    $sanitized['compress_original'] = isset($input['compress_original']) ? 1 : 0;
    
    // 压缩算法
    $sanitized['compression_algorithm'] = isset($input['compression_algorithm']) && 
                                        in_array($input['compression_algorithm'], array('adaptive', 'linear', 'fixed')) ? 
                                        $input['compression_algorithm'] : 'adaptive';
    
    return $sanitized;
}

function image_compressor_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('无权限访问此页面', 'image-compressor'));
    }
    ?>
    <div class="wrap">
        <h1><?php _e('图片压缩器设置', 'image-compressor'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('image_compressor_settings_group');
            $settings = get_option('image_compressor_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('压缩阈值 (KB)', 'image-compressor'); ?></th>
                    <td><input type="number" name="image_compressor_settings[threshold_size]" value="<?php echo esc_attr($settings['threshold_size'] ?? 500); ?>" min="100" max="10000" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('JPEG质量 (1-100)', 'image-compressor'); ?></th>
                    <td><input type="number" name="image_compressor_settings[jpeg_quality]" value="<?php echo esc_attr($settings['jpeg_quality'] ?? 80); ?>" min="1" max="100" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('PNG压缩级别 (0-9)', 'image-compressor'); ?></th>
                    <td><input type="number" name="image_compressor_settings[png_compression]" value="<?php echo esc_attr($settings['png_compression'] ?? 8); ?>" min="0" max="9" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('WebP质量 (1-100)', 'image-compressor'); ?></th>
                    <td><input type="number" name="image_compressor_settings[webp_quality]" value="<?php echo esc_attr($settings['webp_quality'] ?? 80); ?>" min="1" max="100" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('启用自动压缩上传图片', 'image-compressor'); ?></th>
                    <td><input type="checkbox" name="image_compressor_settings[auto_compress]" value="1" <?php checked($settings['auto_compress'] ?? 1, 1); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('压缩后删除原图', 'image-compressor'); ?></th>
                    <td><input type="checkbox" name="image_compressor_settings[compress_original]" value="1" <?php checked($settings['compress_original'] ?? 1, 1); ?> /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('压缩算法', 'image-compressor'); ?></th>
                    <td>
                        <select name="image_compressor_settings[compression_algorithm]">
                            <option value="adaptive" <?php selected($settings['compression_algorithm'] ?? '', 'adaptive'); ?>><?php _e('自适应', 'image-compressor'); ?></option>
                            <option value="linear" <?php selected($settings['compression_algorithm'] ?? '', 'linear'); ?>><?php _e('线性', 'image-compressor'); ?></option>
                            <option value="fixed" <?php selected($settings['compression_algorithm'] ?? '', 'fixed'); ?>><?php _e('固定', 'image-compressor'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr/>
        <h2><?php _e('批量压缩', 'image-compressor'); ?></h2>
        <p><?php _e('如需压缩所有现有媒体库图片，请点击下方按钮。', 'image-compressor'); ?></p>
        <button class="button button-primary" id="image-compressor-batch-btn"><?php _e('开始批量压缩', 'image-compressor'); ?></button>
        <div id="image-compressor-batch-status"></div>
    </div>
    <?php
}
