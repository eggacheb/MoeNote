<?php
class FileShare {
    private $db;
    private $settings;
    private $upload_dir;
    private $cleanup_interval = 3600; // 清理间隔：1小时
    
    public function __construct() {
        $this->settings = new Settings();
        $this->db = new SQLite3($this->settings->getSetting('db_path'));
        $this->upload_dir = __DIR__ . '/uploads/files/';
        
        // 确保上传目录存在并创建必要的保护文件
        $this->initializeUploadDirectory();
        
        // 检查是否需要清理过期文件
        $this->checkAndCleanup();
    }
    
    // 初始化上传目录和保护文件
    private function initializeUploadDirectory() {
        // 创建上传目录
        if (!is_dir($this->upload_dir)) {
            if (!mkdir($this->upload_dir, 0755, true)) {
                throw new Exception('无法创建上传目录，请检查权限');
            }
            
            // 创建 .htaccess 文件来保护上传目录
            $htaccess = $this->upload_dir . '.htaccess';
            if (!file_exists($htaccess)) {
                $htaccess_content = "Options -Indexes\n";
                $htaccess_content .= "DirectoryIndex 403.html\n";
                $htaccess_content .= "AddType text/plain .php\n";
                $htaccess_content .= "AddType text/plain .html\n";
                $htaccess_content .= "AddType text/plain .htm\n";
                $htaccess_content .= "AddType text/plain .htaccess\n";
                file_put_contents($htaccess, $htaccess_content);
            }
            
            // 创建 index.html 文件来防止目录列表
            $index_html = $this->upload_dir . 'index.html';
            if (!file_exists($index_html)) {
                file_put_contents($index_html, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>');
            }
            
            // 创建 403.html 文件
            $forbidden_page = $this->upload_dir . '403.html';
            if (!file_exists($forbidden_page)) {
                file_put_contents($forbidden_page, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Access to this directory is forbidden.</p></body></html>');
            }
        }
        
        // 设置目录权限
        chmod($this->upload_dir, 0755);
    }
    
    // 检查并清理过期文件
    private function checkAndCleanup() {
        $lastCleanup = $this->settings->getSetting('last_cleanup', 0);
        
        // 使用中国时间
        date_default_timezone_set('Asia/Shanghai');
        $currentTime = time();
        
        if ($currentTime - $lastCleanup > $this->cleanup_interval) {
            $this->settings->setSetting('last_cleanup', $currentTime);
            
            $stmt = $this->db->prepare('
                SELECT code, type, filepath 
                FROM files 
                WHERE expires_at < :current_time 
                OR (max_downloads > 0 AND current_downloads >= max_downloads)
            ');
            $stmt->bindValue(':current_time', $currentTime, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            // 删除过期的文件和记录
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['type'] === 'file' && $row['filepath'] && file_exists($row['filepath'])) {
                    unlink($row['filepath']);
                }
                
                $deleteStmt = $this->db->prepare('DELETE FROM files WHERE code = :code');
                $deleteStmt->bindValue(':code', $row['code'], SQLITE3_TEXT);
                $deleteStmt->execute();
            }
            
            // 清理空目录
            $this->cleanEmptyDirectories($this->upload_dir);
        }
    }
    
    // 清理空目录
    private function cleanEmptyDirectories($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || 
                $file === 'index.html' || $file === '403.html') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanEmptyDirectories($path);
                // 如果目录为空（只包含 . 和 ..），则删除
                $subFiles = scandir($path);
                if (count($subFiles) <= 2) { // 只有 . 和 ..
                    rmdir($path);
                }
            }
        }
    }
    
    // 生成随机提取码
    private function generateCode($length = 6) {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    // 保存文件或文本
    public function save($data) {
        $this->checkAndCleanup();
        
        $code = $this->generateCode();
        
        // 使用中国时间
        date_default_timezone_set('Asia/Shanghai');
        $created_at = time(); // 当前中国时间
        $expires_at = $created_at + intval($data['expire_time']); // 过期时间也是中国时间
        
        if (isset($_FILES['file'])) {
            // 处理文件上传
            $file = $_FILES['file'];
            $filename = $file['name'];
            $filepath = $this->upload_dir . uniqid() . '_' . bin2hex(random_bytes(4)) . '_' . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('文件上传失败');
            }
            
            $type = 'file';
            $content = null;
        } else {
            // 处理文本内容
            $filename = null;
            $filepath = null;
            $type = 'text';
            $content = $data['content'];
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO files (code, filename, filepath, content, type, created_at, expires_at, max_downloads)
            VALUES (:code, :filename, :filepath, :content, :type, :created_at, :expires_at, :max_downloads)
        ');
        
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':filepath', $filepath, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $created_at, SQLITE3_INTEGER);
        $stmt->bindValue(':expires_at', $expires_at, SQLITE3_INTEGER);
        $stmt->bindValue(':max_downloads', intval($data['max_downloads']), SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            return $code;
        }
        throw new Exception('保存失败');
    }
    
    // 获取文件或文本
    public function get($code) {
        $this->checkAndCleanup();
        
        $stmt = $this->db->prepare('SELECT * FROM files WHERE code = :code');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 使用中国时间进行比较
            date_default_timezone_set('Asia/Shanghai');
            if (time() > $row['expires_at']) {
                $this->delete($code);
                return null;
            }
            
            // 检查下载次数
            if ($row['max_downloads'] > 0 && $row['current_downloads'] >= $row['max_downloads']) {
                $this->delete($code);
                return null;
            }
            
            // 更新下载次数
            $stmt = $this->db->prepare('
                UPDATE files 
                SET current_downloads = current_downloads + 1 
                WHERE code = :code
            ');
            $stmt->bindValue(':code', $code, SQLITE3_TEXT);
            $stmt->execute();
            
            // 返回更多文件信息
            return [
                'type' => $row['type'],
                'content' => $row['content'],
                'filename' => $row['filename'],
                'filepath' => $row['filepath'],
                'current_downloads' => $row['current_downloads'],
                'max_downloads' => $row['max_downloads'],
                'expires_at' => $row['expires_at']
            ];
        }
        return null;
    }
    
    // 删除文件或文本
    private function delete($code) {
        $stmt = $this->db->prepare('SELECT * FROM files WHERE code = :code');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['type'] === 'file' && $row['filepath'] && file_exists($row['filepath'])) {
                unlink($row['filepath']);
            }
        }
        
        $stmt = $this->db->prepare('DELETE FROM files WHERE code = :code');
        $stmt->bindValue(':code', $code, SQLITE3_TEXT);
        $stmt->execute();
    }
}
