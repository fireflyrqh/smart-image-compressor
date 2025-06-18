jQuery(document).ready(function($){
    // 批量压缩功能
    $('#image-compressor-batch-btn').on('click', function() {
        let $button = $(this);
        let $status = $('#image-compressor-batch-status');
        let batch = 0;
        
        // 防止重复点击
        if ($button.prop('disabled')) {
            return false;
        }
        
        // 显示进度条
        $status.html('<div class="progress-bar-container"><div class="progress-bar"></div><div class="progress-text">0%</div></div>');
        $button.prop('disabled', true).text('压缩中...');
        
        // 创建进度条元素
        let $progressBar = $status.find('.progress-bar');
        let $progressText = $status.find('.progress-text');
        
        // 递归处理批次
        function processBatch() {
            $.ajax({
                url: imageCompressorParams.ajaxurl,
                type: 'POST',
                data: {
                    action: 'image_compressor_batch_compress',
                    nonce: imageCompressorParams.nonce,
                    batch: batch
                },
                success: function(response) {
                    if (response.success) {
                        // 更新进度
                        let data = response.data;
                        if (data.progress) {
                            $progressBar.width(data.progress + '%');
                            $progressText.text(data.progress + '%');
                        }
                        
                        // 添加信息
                        $status.append('<p>' + data.message + '</p>');
                        
                        if (!data.done) {
                            // 处理下一批次
                            batch++;
                            setTimeout(processBatch, 1000); // 延迟1秒以减轻服务器负担
                        } else {
                            // 完成所有批次
                            $button.prop('disabled', false).text('开始批量压缩');
                            $status.append('<p class="success-message">所有图片压缩完成！</p>');
                        }
                    } else {
                        // 处理错误
                        $status.append('<p class="error-message">错误：' + (response.data ? response.data.message : '未知错误') + '</p>');
                        $button.prop('disabled', false).text('重试批量压缩');
                    }
                },
                error: function() {
                    $status.append('<p class="error-message">服务器通信错误，请稍后再试</p>');
                    $button.prop('disabled', false).text('重试批量压缩');
                }
            });
        }
        
        // 开始处理
        processBatch();
    });
    
    // 媒体库压缩按钮功能
    $(document).on('click', '.compress-image-button', function() {
        let $button = $(this);
        let attachmentId = $button.data('id');
        
        if ($button.prop('disabled')) {
            return false;
        }
        
        $button.prop('disabled', true).text(imageCompressorParams.compressing);
        
        $.ajax({
            url: imageCompressorParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'image_compressor_compress_single',
                nonce: imageCompressorParams.nonce,
                attachment_id: attachmentId
            },
            success: function(response) {
                if (response.success) {
                    $button.after('<span class="compression-result success">' + response.data.message + '</span>');
                    $button.text(imageCompressorParams.recompress);
                } else {
                    $button.after('<span class="compression-result error">' + 
                        (response.data ? response.data.message : imageCompressorParams.error) + '</span>');
                }
                $button.prop('disabled', false);
                
                // 5秒后移除消息
                setTimeout(function() {
                    $button.siblings('.compression-result').fadeOut(function() {
                        $(this).remove();
                    });
                }, 5000);
            },
            error: function() {
                $button.after('<span class="compression-result error">' + imageCompressorParams.error + '</span>');
                $button.prop('disabled', false);
            }
        });
    });
});
