<?php
// settings.php

class Settings {
    private $db = null;
    private $defaultSettings = [
        'site_name' => 'MoeNote',
        'site_description' => 'A simple online note that focuses on safe sharing.',
        'data_directory' => './data-ekcvbq1/',
        'db_path' => './data-ekdvbq/notes.db',
        'favicon_path' => '/favicon.ico',
        'max_expire_time' => 31536000, // 1yr
    ];
    
    public function __construct() {
        $this->connectDb();
    }
    
    private function connectDb() {
        $dbPath = $this->defaultSettings['db_path'];
        if (!file_exists($dbPath)) {
            throw new Exception("数据库文件不存在");
        }
        $this->db = new SQLite3($dbPath);
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
