<?php
/**
 * 图片压缩日志类
 */
class Image_Compressor_Logger {
    private static $instance = null;
    private $log_table_name;
    private $log_retention_days = 15; // 日志保留天数
    
    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 构造函数
     */
    private function __construct() {
        global $wpdb;
        $this->log_table_name = $wpdb->prefix . 'image_compressor_logs';
        
        // 注册激活钩子
        register_activation_hook(IMAGE_COMPRESSOR_PLUGIN_FILE, array($this, 'create_log_table'));
        
        // 添加定时任务
        add_action('image_compressor_cleanup_logs', array($this, 'cleanup_old_logs'));
        if (!wp_next_scheduled('image_compressor_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'image_compressor_cleanup_logs');
        }
    }
    
    /**
     * 创建日志表
     */
    public function create_log_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->log_table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            log_date datetime NOT NULL,
            log_type varchar(20) NOT NULL,
            message text NOT NULL,
            file_path varchar(255) DEFAULT NULL,
            original_size int(11) DEFAULT 0,
            compressed_size int(11) DEFAULT 0,
            saved_percent decimal(5,2) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY log_date (log_date),
            KEY log_type (log_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * 记录日志
     */
    public function log($type, $message, $file_path = null, $original_size = 0, $compressed_size = 0) {
        global $wpdb;
        
        $saved_percent = 0;
        if ($original_size > 0 && $compressed_size > 0) {
            $saved_percent = (($original_size - $compressed_size) / $original_size) * 100;
        }
        
        $wpdb->insert(
            $this->log_table_name,
            array(
                'log_date' => current_time('mysql'),
                'log_type' => $type, // 'auto', 'manual', 'error', 'info'
                'message' => $message,
                'file_path' => $file_path,
                'original_size' => $original_size,
                'compressed_size' => $compressed_size,
                'saved_percent' => round($saved_percent, 2)
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%f')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * 清理过期日志
     */
    public function cleanup_old_logs() {
        global $wpdb;
        $date = date('Y-m-d H:i:s', strtotime("-$this->log_retention_days days"));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->log_table_name} WHERE log_date < %s",
                $date
            )
        );
    }
    
    /**
     * 获取日志
     */
    public function get_logs($limit = 100, $type = null) {
        global $wpdb;
        
        $query = "SELECT * FROM {$this->log_table_name}";
        $params = array();
        
        if ($type) {
            $query .= " WHERE log_type = %s";
            $params[] = $type;
        }
        
        $query .= " ORDER BY log_date DESC LIMIT %d";
        $params[] = $limit;
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * 获取统计信息
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = array(
            'total_compressed' => 0,
            'total_original_size' => 0,
            'total_compressed_size' => 0,
            'total_saved' => 0,
            'avg_saved_percent' => 0,
            'last_compressed' => ''
        );
        
        // 获取压缩统计
        $result = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_compressed,
                SUM(original_size) as total_original_size,
                SUM(compressed_size) as total_compressed_size,
                AVG(saved_percent) as avg_saved_percent,
                MAX(log_date) as last_compressed
             FROM {$this->log_table_name}
             WHERE log_type IN ('auto', 'manual') AND original_size > 0"
        );
        
        if ($result) {
            $stats['total_compressed'] = (int) $result->total_compressed;
            $stats['total_original_size'] = (int) $result->total_original_size;
            $stats['total_compressed_size'] = (int) $result->total_compressed_size;
            $stats['total_saved'] = $stats['total_original_size'] - $stats['total_compressed_size'];
            $stats['avg_saved_percent'] = round((float) $result->avg_saved_percent, 2);
            $stats['last_compressed'] = $result->last_compressed;
        }
        
        return $stats;
    }
    
    /**
     * 格式化文件大小
     */
    public function format_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }
}

// 初始化日志类
function image_compressor_logger_init() {
    return Image_Compressor_Logger::get_instance();
}
add_action('plugins_loaded', 'image_compressor_logger_init');
