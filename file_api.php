<?php
header('Content-Type: application/json');

require_once 'settings.php';
require_once 'FileShare.php';

$fileShare = new FileShare();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 处理上传
        $data = [
            'expire_time' => $_POST['expire_time'] ?? 86400,
            'max_downloads' => $_POST['max_downloads'] ?? 0
        ];
        
        if (isset($_FILES['file']) || isset($_POST['content'])) {
            if (isset($_POST['content'])) {
                $data['content'] = $_POST['content'];
            }
            
            $code = $fileShare->save($data);
            echo json_encode([
                'status' => 'success',
                'code' => $code
            ]);
        } else {
            throw new Exception('未收到文件或文本内容');
        }
    } else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 处理下载/获取
        if (isset($_GET['download'])) {
            $code = $_GET['download'];
            $file = $fileShare->get($code);
            
            if (!$file || $file['type'] !== 'file') {
                throw new Exception('文件不存在或已过期');
            }
            
            // 获取文件 MIME 类型
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['filepath']);
            finfo_close($finfo);
            
            // 清除之前的输出缓冲和头信息
            ob_clean();
            header('Content-Type: ' . $mime_type);
            header('Content-Transfer-Encoding: binary');
            header('Content-Disposition: attachment; filename="' . rawurlencode($file['filename']) . '"');
            header('Content-Length: ' . filesize($file['filepath']));
            header('Accept-Ranges: bytes');
            
            // 处理断点续传
            if (isset($_SERVER['HTTP_RANGE'])) {
                list($start, $end) = sscanf($_SERVER['HTTP_RANGE'], 'bytes=%d-%d');
                $filesize = filesize($file['filepath']);
                
                if (!isset($end)) {
                    $end = $filesize - 1;
                }
                
                $length = $end - $start + 1;
                
                header('HTTP/1.1 206 Partial Content');
                header("Content-Range: bytes $start-$end/$filesize");
                header('Content-Length: ' . $length);
                
                $fp = fopen($file['filepath'], 'rb');
                fseek($fp, $start);
                $buffer = 1024 * 8;
                while ($length > 0) {
                    $read = min($buffer, $length);
                    echo fread($fp, $read);
                    flush();
                    $length -= $read;
                }
                fclose($fp);
            } else {
                readfile($file['filepath']);
            }
            exit;
        } else {
            $code = $_GET['code'] ?? null;
            if (!$code) {
                throw new Exception('未提供提取码');
            }
            
            $file = $fileShare->get($code);
            if (!$file) {
                throw new Exception('文件不存在或已过期');
            }
            
            // 返回文件信息或文本内容
            echo json_encode([
                'status' => 'success',
                'type' => $file['type'],
                'content' => $file['content'],
                'filename' => $file['filename'],
                'current_downloads' => $file['current_downloads'],
                'max_downloads' => $file['max_downloads'],
                'expires_at' => $file['expires_at'],
                'expires_at_formatted' => date('Y-m-d H:i:s', $file['expires_at'])  // 添加格式化的时间
            ]);
        }
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
