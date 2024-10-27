<?php
// settings.php

class Settings {
    private $db = null;
    private $defaultSettings = [
        'site_name' => 'MoeNote',
        'site_description' => 'A simple online note that focuses on safe sharing.',
        'data_directory' => './data/',
        'db_path' => './data/notes.db',
        'favicon_path' => '/favicon.png',
        'max_expire_time' => 31536000, // 1yr
    ];
    
    public function __construct() {
        $this->initializeDatabase();
    }
    
    private function initializeDatabase() {
        // 确保数据目录存在
        $dataDir = dirname($this->defaultSettings['db_path']);
        if (!file_exists($dataDir)) {
            if (!mkdir($dataDir, 0755, true)) {
                throw new Exception('无法创建数据目录，请检查权限');
            }
            
            // 创建 .htaccess 文件来保护数据目录
            $htaccess = $dataDir . '/.htaccess';
            if (!file_exists($htaccess)) {
                $htaccess_content = "Require all denied\n";
                $htaccess_content .= "Options -Indexes\n";
                file_put_contents($htaccess, $htaccess_content);
            }
            
            // 创建 index.html
            $index_html = $dataDir . '/index.html';
            if (!file_exists($index_html)) {
                file_put_contents($index_html, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>');
            }
        }
        
        // 设置目录权限
        chmod($dataDir, 0755);
        
        // 连接或创建数据库
        $this->db = new SQLite3($this->defaultSettings['db_path']);
        
        // 设置数据库文件权限
        chmod($this->defaultSettings['db_path'], 0600);
        
        // 创建必要的表
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ');
        
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS notes (
                uuid TEXT PRIMARY KEY,
                content TEXT,
                created_at INTEGER,
                expires_at INTEGER,
                max_views INTEGER DEFAULT 0,
                current_views INTEGER DEFAULT 0,
                is_markdown INTEGER DEFAULT 0,
                is_encrypted INTEGER DEFAULT 0
            )
        ');
        
        // 在 initializeDatabase 方法中添加新表
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                code TEXT PRIMARY KEY,           -- 提取码
                filename TEXT,                   -- 原始文件名
                filepath TEXT,                   -- 存储路径
                content TEXT,                    -- 文本内容（如果是文本类型）
                type TEXT,                       -- 类型：file 或 text
                created_at INTEGER,             -- 创建时间
                expires_at INTEGER,             -- 过期时间
                max_downloads INTEGER DEFAULT 0, -- 最大下载/查看次数
                current_downloads INTEGER DEFAULT 0  -- 当前下载/查看次数
            )
        ');
        
        // 初始化默认设置
        foreach ($this->defaultSettings as $key => $value) {
            $stmt = $this->db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);
            $stmt->execute();
        }
    }
    
    public function getSetting($key, $default = null) {
        try {
            $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :key');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                return $row['value'];
            }
        } catch (Exception $e) {
            // 如果发生错误，返回默认值
        }
        
        return isset($this->defaultSettings[$key]) ? $this->defaultSettings[$key] : $default;
    }
    
    public function setSetting($key, $value) {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        return $stmt->execute();
    }
    
    public function getDefaultSettings() {
        return $this->defaultSettings;
    }
}
