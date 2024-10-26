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
            mkdir($dataDir, 0755, true);
        }
        
        // 连接或创建数据库
        $this->db = new SQLite3($this->defaultSettings['db_path']);
        
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
