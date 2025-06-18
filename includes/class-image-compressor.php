<?php
/**
 * 图片压缩处理类
 */
class Image_Compressor {
    /**
     * 构造函数
     */
    public function __construct() {
        // 确保临时目录存在
        $this->ensure_temp_directory();
    }
    
    /**
     * 确保临时目录存在
     */
    private function ensure_temp_directory() {
        $upload_dir = wp_upload_dir();
        if (!isset($upload_dir['basedir'])) {
            return;
        }
        
        $temp_dir = $upload_dir['basedir'] . '/image-compressor-temp';
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
    }
    
    /**
     * 压缩上传的图片
     * 
     * @param array $file 上传的文件信息
     * @return array 处理后的文件信息
     */
    public function compress_uploaded_image($file) {
        // 验证文件是否有效
        if (!is_array($file) || !isset($file['file']) || !file_exists($file['file'])) {
            return $file;
        }
        
        $settings = get_option('image_compressor_settings');
        
        // 临时保存原始文件信息
        $original_size = filesize($file['file']);
        
        // 获取文件类型
        $file_type = wp_check_filetype($file['file']);
        $mime_type = $file_type['type'];
        $extension = strtolower(pathinfo($file['file'], PATHINFO_EXTENSION));
        
        if (!$mime_type) {
            return $file;
        }
        
        // 支持的图片类型
        $supported_types = array(
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/x-icon' => 'ico'
        );
        
        // 检查是否支持该类型
        if (!in_array($mime_type, array_keys($supported_types))) {
            return $file;
        }
        
        // 基于文件大小动态计算压缩质量
        $quality = $this->calculate_quality($original_size);
        
        // 根据文件类型选择压缩方法
        $result = false;
        $compression = isset($settings['png_compression']) ? (int)$settings['png_compression'] : 8;
        
        switch ($mime_type) {
            case 'image/jpeg':
                $result = $this->compress_jpeg($file['file'], $quality);
                break;
                
            case 'image/png':
                $result = $this->compress_png($file['file'], $compression);
                break;
                
            case 'image/webp':
                $quality = isset($settings['webp_quality']) ? (int)$settings['webp_quality'] : 80;
                $result = $this->compress_webp($file['file'], $quality);
                break;
                
            case 'image/gif':
                $result = $this->compress_gif($file['file']);
                break;
                
            case 'image/bmp':
            case 'image/x-ms-bmp':
                $result = $this->compress_bmp($file['file'], $quality);
                break;
                
            case 'image/tiff':
            case 'image/tiff-fx':
                $result = $this->compress_tiff($file['file'], $quality);
                break;
                
            case 'image/x-icon':
                $result = $this->compress_ico($file['file']);
                break;
                
            default:
                // 尝试使用通用方法
                $result = $this->compress_generic($file['file'], $quality);
        }
        
        // 如果压缩成功，获取压缩后的大小
        if ($result) {
            $compressed_size = filesize($file['file']);
            
            // 保存压缩信息到元数据
            $compression_data = array(
                'original_size' => $original_size,
                'compressed_size' => $compressed_size,
                'compression_time' => current_time('mysql'),
                'quality_used' => $quality
            );
            
            // 更新统计信息
            $this->update_compression_stats($original_size, $compressed_size);
        }
        
        // 返回处理后的文件
        return $file;
    }
    
    /**
     * 计算压缩质量 - 文件越大，压缩质量越低（更强的压缩）
     * 
     * @param int $file_size 文件大小（字节）
     * @return int 压缩质量（0-100）
     */
    private function calculate_quality($file_size) {
        $settings = get_option('image_compressor_settings');
        $algorithm = isset($settings['compression_algorithm']) ? $settings['compression_algorithm'] : 'adaptive';
        
        // 基础质量设置
        $base_quality = isset($settings['jpeg_quality']) ? $settings['jpeg_quality'] : 80;
        
        // 自适应算法
        if ($algorithm === 'adaptive') {
            $file_size_mb = $file_size / (1024 * 1024); // 转换为MB
            
            if ($file_size_mb <= 0.5) {
                return $base_quality; // 500KB以下使用基本质量
            } elseif ($file_size_mb <= 1) {
                return $base_quality - 5; // 0.5-1MB
            } elseif ($file_size_mb <= 2) {
                return $base_quality - 10; // 1-2MB
            } elseif ($file_size_mb <= 5) {
                return $base_quality - 15; // 2-5MB
            } else {
                return $base_quality - 20; // 5MB以上
            }
        } 
        
        // 线性比例算法
        elseif ($algorithm === 'linear') {
            $file_size_mb = $file_size / (1024 * 1024);
            $quality_reduction = min(30, round($file_size_mb * 10)); // 每MB降低10%的质量，最多降低30%
            return max(50, $base_quality - $quality_reduction); // 最低不低于50
        }
        
        // 默认返回基础质量
        return $base_quality;
    }
    
    /**
     * 压缩JPEG图片
     * 
     * @param string $file_path 文件路径
     * @param int $quality 质量（0-100）
     * @return bool 成功与否
     */
    private function compress_jpeg($file_path, $quality) {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // 读取图片
        $image = @imagecreatefromjpeg($file_path);
        if (!$image) {
            return false;
        }
        
        // 备份原始文件
        $original_backup = $this->create_backup($file_path);
        if (!$original_backup) {
            imagedestroy($image);
            return false;
        }
        
        // 保存压缩后的图片
        $result = imagejpeg($image, $file_path, $quality);
        imagedestroy($image);
        
        // 如果压缩后文件更大，还原原始文件
        if ($result && file_exists($original_backup) && file_exists($file_path) && filesize($file_path) > filesize($original_backup)) {
            copy($original_backup, $file_path);
        }
        
        // 删除备份
        if (file_exists($original_backup)) {
            @unlink($original_backup);
        }
        
        return $result;
    }
    
    /**
     * 压缩PNG图片
     * 
     * @param string $file_path 文件路径
     * @param int $compression 压缩级别（0-9）
     * @return bool 成功与否
     */
    private function compress_png($file_path, $compression) {
        if (!function_exists('imagecreatefrompng')) {
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // 读取图片
        $image = @imagecreatefrompng($file_path);
        if (!$image) {
            return false;
        }
        
        // 保留透明度
        imagealphablending($image, false);
        imagesavealpha($image, true);
        
        // 备份原始文件
        $original_backup = $this->create_backup($file_path);
        if (!$original_backup) {
            imagedestroy($image);
            return false;
        }
        
        // 保存压缩后的图片
        $result = imagepng($image, $file_path, $compression);
        imagedestroy($image);
        
        // 如果压缩后文件更大，还原原始文件
        if ($result && file_exists($original_backup) && file_exists($file_path) && filesize($file_path) > filesize($original_backup)) {
            copy($original_backup, $file_path);
        }
        
        // 删除备份
        if (file_exists($original_backup)) {
            @unlink($original_backup);
        }
        
        return $result;
    }
    
    /**
     * 压缩WebP图片
     * 
     * @param string $file_path 文件路径
     * @param int $quality 质量（0-100）
     * @return bool 成功与否
     */
    private function compress_webp($file_path, $quality) {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // 读取图片
        $image = @imagecreatefromwebp($file_path);
        if (!$image) {
            return false;
        }
        
        // 备份原始文件
        $original_backup = $this->create_backup($file_path);
        if (!$original_backup) {
            imagedestroy($image);
            return false;
        }
        
        // 保存压缩后的图片
        $result = imagewebp($image, $file_path, $quality);
        imagedestroy($image);
        
        // 如果压缩后文件更大，还原原始文件
        if ($result && file_exists($original_backup) && file_exists($file_path) && filesize($file_path) > filesize($original_backup)) {
            copy($original_backup, $file_path);
        }
        
        // 删除备份
        if (file_exists($original_backup)) {
            @unlink($original_backup);
        }
        
        return $result;
    }
    
    /**
     * 创建文件备份
     * 
     * @param string $file_path 文件路径
     * @return string|bool 备份文件路径或失败时返回false
     */
    private function create_backup($file_path) {
        $upload_dir = wp_upload_dir();
        if (!isset($upload_dir['basedir'])) {
            return false;
        }
        
        $temp_dir = $upload_dir['basedir'] . '/image-compressor-temp';
        
        // 确保临时目录存在
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                return false;
            }
        }
        
        $backup_file = $temp_dir . '/' . basename($file_path) . '.bak';
        
        if (!copy($file_path, $backup_file)) {
            return false;
        }
        
        return $backup_file;
    }
    
    /**
     * 更新压缩统计信息
     * 
     * @param int $original_size 原始大小
     * @param int $compressed_size 压缩后大小
     */
    private function update_compression_stats($original_size, $compressed_size) {
        $stats = get_option('image_compressor_stats', array(
            'total_images' => 0,
            'total_original_size' => 0,
            'total_compressed_size' => 0,
            'compression_started' => current_time('mysql')
        ));
        
        $stats['total_images']++;
        $stats['total_original_size'] += $original_size;
        $stats['total_compressed_size'] += $compressed_size;
        $stats['last_compressed'] = current_time('mysql');
        
        update_option('image_compressor_stats', $stats);
    }
    
    /**
     * 压缩媒体库中的单个附件
     * 
     * @param int $attachment_id 附件ID
     * @return bool 成功与否
     */
    public function compress_attachment($attachment_id) {
        // 确保是图片
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $original_size = filesize($file_path);
        
        // 获取文件类型
        $file_type = wp_check_filetype($file_path);
        $mime_type = $file_type['type'];
        
        if (!$mime_type) {
            return false;
        }
        
        // 计算压缩质量
        $quality = $this->calculate_quality($original_size);
        
        // 根据图片类型执行压缩
        $result = false;
        $settings = get_option('image_compressor_settings');
        
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $result = $this->compress_jpeg($file_path, $quality);
                break;
                
            case 'image/png':
                $png_compression = isset($settings['png_compression']) ? (int)$settings['png_compression'] : 8;
                $result = $this->compress_png($file_path, $png_compression);
                break;
                
            case 'image/gif':
                $result = $this->compress_gif($file_path);
                break;
                
            case 'image/webp':
                $webp_quality = isset($settings['webp_quality']) ? (int)$settings['webp_quality'] : 80;
                $result = $this->compress_webp($file_path, $webp_quality);
                break;
                
            case 'image/bmp':
            case 'image/x-ms-bmp':
                $result = $this->compress_bmp($file_path, $quality);
                break;
                
            case 'image/tiff':
            case 'image/tiff-fx':
                $result = $this->compress_tiff($file_path, $quality);
                break;
                
            case 'image/x-icon':
                $result = $this->compress_ico($file_path);
                break;
                
            default:
                // 尝试使用通用方法
                $result = $this->compress_generic($file_path, $quality);
        }
        
        // 如果压缩成功，更新元数据
        if ($result && file_exists($file_path)) {
            $compressed_size = filesize($file_path);
            
            // 保存压缩信息
            $compression_data = array(
                'original_size' => $original_size,
                'compressed_size' => $compressed_size,
                'compression_time' => current_time('mysql'),
                'quality_used' => $quality
            );
            update_post_meta($attachment_id, '_image_compression_data', $compression_data);
            
            // 更新统计信息
            $this->update_compression_stats($original_size, $compressed_size);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 压缩GIF图片
     * 
     * @param string $file_path 文件路径
     * @return bool 成功与否
     */
    private function compress_gif($file_path) {
        // 检查是否支持GIF处理
        if (!function_exists('imagecreatefromgif') || !function_exists('imagegif')) {
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // 检查是否为动画GIF
        $is_animated = $this->is_animated_gif($file_path);
        
        // 如果是动画GIF，不进行处理
        if ($is_animated) {
            return false;
        }
        
        // 读取图片
        $image = @imagecreatefromgif($file_path);
        if (!$image) {
            return false;
        }
        
        // 备份原始文件
        $original_backup = $this->create_backup($file_path);
        if (!$original_backup) {
            imagedestroy($image);
            return false;
        }
        
        // 保存压缩后的图片
        $result = imagegif($image, $file_path);
        imagedestroy($image);
        
        // 如果压缩后文件更大，还原原始文件
        if ($result && file_exists($original_backup) && file_exists($file_path) && filesize($file_path) > filesize($original_backup)) {
            copy($original_backup, $file_path);
        }
        
        // 删除备份
        if (file_exists($original_backup)) {
            @unlink($original_backup);
        }
        
        return $result;
    }
    
    /**
     * 检查GIF是否为动画
     * 
     * @param string $filename GIF文件路径
     * @return bool 是否为动画
     */
    private function is_animated_gif($filename) {
        if (!($fh = @fopen($filename, 'rb'))) {
            return false;
        }
        
        $count = 0;
        // 一个GIF文件可能有多个帧，我们检查是否有多个帧
        while (!feof($fh) && $count < 2) {
            $chunk = fread($fh, 1024 * 100); // 读取100KB
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk, $matches);
        }
        
        fclose($fh);
        return $count > 1;
    }
    
    /**
     * 压缩BMP图片
     * 
     * @param string $file_path 文件路径
     * @param int $quality 质量（0-100）
     * @return bool 成功与否
     */
    private function compress_bmp($file_path, $quality) {
        // 检查是否支持BMP处理
        if (!function_exists('imagecreatefrombmp') || !function_exists('imagejpeg')) {
            return false;
        }
        
        // 检查文件是否存在
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // 读取BMP图片
        $image = @imagecreatefrombmp($file_path);
        if (!$image) {
            return false;
        }
        
        // 创建临时文件路径
        $temp_file = tempnam(sys_get_temp_dir(), 'bmp_');
        
        // 将BMP转换为JPEG并压缩
        $result = imagejpeg($image, $temp_file, $quality);
        imagedestroy($image);
        
        if ($result && file_exists($temp_file)) {
            // 备份原始文件
            $original_backup = $this->create_backup($file_path);
            if (!$original_backup) {
                @unlink($temp_file);
                return false;
            }
            
            // 替换原始文件
            $success = copy($temp_file, $file_path);
            @unlink($temp_file);
            
            // 如果转换失败，恢复备份
            if (!$success) {
                if (file_exists($original_backup)) {
                    copy($original_backup, $file_path);
                    @unlink($original_backup);
                }
                return false;
            }
            
            // 删除备份
            if (file_exists($original_backup)) {
                @unlink($original_backup);
            }
            
            return true;
        }
        
        @unlink($temp_file);
        return false;
    }
    
    /**
     * 压缩TIFF图片
     * 
     * @param string $file_path 文件路径
     * @param int $quality 质量（0-100）
     * @return bool 成功与否
     */
    private function compress_tiff($file_path, $quality) {
        // 检查是否支持ImageMagick
        if (!extension_loaded('imagick')) {
            return false;
        }
        
        try {
            $image = new Imagick($file_path);
            
            // 设置压缩质量
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality($quality);
            
            // 如果是多页TIFF，只处理第一页
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
            }
            
            // 备份原始文件
            $original_backup = $this->create_backup($file_path);
            if (!$original_backup) {
                $image->clear();
                return false;
            }
            
            // 保存压缩后的图片
            $result = $image->writeImages($file_path, true);
            $image->clear();
            
            // 如果压缩失败，恢复备份
            if (!$result) {
                if (file_exists($original_backup)) {
                    copy($original_backup, $file_path);
                    @unlink($original_backup);
                }
                return false;
            }
            
            // 删除备份
            if (file_exists($original_backup)) {
                @unlink($original_backup);
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 压缩ICO图标
     * 
     * @param string $file_path 文件路径
     * @return bool 成功与否
     */
    private function compress_ico($file_path) {
        // ICO文件通常很小，不进行压缩
        // 只检查文件大小，如果超过阈值，则转换为PNG
        
        $max_size = 50 * 1024; // 50KB
        
        if (filesize($file_path) <= $max_size) {
            return true; // 文件足够小，不需要压缩
        }
        
        // 尝试转换为PNG
        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $image_data = file_get_contents($file_path);
            if ($image_data === false) {
                return false;
            }
            
            $image = @imagecreatefromstring($image_data);
            if ($image === false) {
                return false;
            }
            
            // 创建临时文件
            $temp_file = tempnam(sys_get_temp_dir(), 'ico_');
            
            // 保存为PNG
            $result = imagepng($image, $temp_file, 9);
            imagedestroy($image);
            
            if ($result && filesize($temp_file) < filesize($file_path)) {
                // 备份原始文件
                $original_backup = $this->create_backup($file_path);
                if (!$original_backup) {
                    @unlink($temp_file);
                    return false;
                }
                
                // 替换为PNG
                $new_file_path = preg_replace('/\.[^.\s]{3,4}$/', '.png', $file_path);
                $success = rename($temp_file, $new_file_path);
                
                if ($success) {
                    // 更新文件路径
                    $file_path = $new_file_path;
                    
                    // 删除备份
                    if (file_exists($original_backup)) {
                        @unlink($original_backup);
                    }
                    
                    return true;
                } else {
                    // 恢复备份
                    if (file_exists($original_backup)) {
                        copy($original_backup, $file_path);
                        @unlink($original_backup);
                    }
                    @unlink($temp_file);
                    return false;
                }
            }
            
            @unlink($temp_file);
        }
        
        return false;
    }
    
    /**
     * 通用图片压缩方法
     * 尝试使用GD或ImageMagick进行压缩
     * 
     * @param string $file_path 文件路径
     * @param int $quality 质量（0-100）
     * @return bool 成功与否
     */
    private function compress_generic($file_path, $quality) {
        // 首先尝试使用GD
        if (function_exists('getimagesize') && function_exists('imagecreatefromstring')) {
            $image_info = @getimagesize($file_path);
            if ($image_info === false) {
                return false;
            }
            
            $image_data = file_get_contents($file_path);
            if ($image_data === false) {
                return false;
            }
            
            $image = @imagecreatefromstring($image_data);
            if ($image === false) {
                return false;
            }
            
            // 创建临时文件
            $temp_file = tempnam(sys_get_temp_dir(), 'img_');
            
            // 尝试保存为JPEG
            $result = imagejpeg($image, $temp_file, $quality);
            imagedestroy($image);
            
            if ($result && filesize($temp_file) < filesize($file_path)) {
                // 备份原始文件
                $original_backup = $this->create_backup($file_path);
                if (!$original_backup) {
                    @unlink($temp_file);
                    return false;
                }
                
                // 替换文件
                $success = copy($temp_file, $file_path);
                @unlink($temp_file);
                
                // 如果失败，恢复备份
                if (!$success) {
                    if (file_exists($original_backup)) {
                        copy($original_backup, $file_path);
                        @unlink($original_backup);
                    }
                    return false;
                }
                
                // 删除备份
                if (file_exists($original_backup)) {
                    @unlink($original_backup);
                }
                
                return true;
            }
            
            @unlink($temp_file);
        }
        
        // 如果GD失败，尝试使用ImageMagick
        if (extension_loaded('imagick')) {
            try {
                $image = new Imagick($file_path);
                
                // 设置压缩质量
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality($quality);
                
                // 如果是多页图像，只处理第一页
                if ($image->getNumberImages() > 1) {
                    $image = $image->coalesceImages();
                }
                
                // 备份原始文件
                $original_backup = $this->create_backup($file_path);
                if (!$original_backup) {
                    $image->clear();
                    return false;
                }
                
                // 保存压缩后的图片
                $result = $image->writeImages($file_path, true);
                $image->clear();
                
                // 如果压缩失败，恢复备份
                if (!$result) {
                    if (file_exists($original_backup)) {
                        copy($original_backup, $file_path);
                        @unlink($original_backup);
                    }
                    return false;
                }
                
                // 删除备份
                if (file_exists($original_backup)) {
                    @unlink($original_backup);
                }
                
                return true;
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
}
