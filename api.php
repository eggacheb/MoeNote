<?php
// api.php
header('Content-Type: application/json');

require_once 'settings.php';

class PasteAPI {
    private $db;
    private $settings;
    
    public function __construct() {
        $this->settings = new Settings();
        $this->db = new SQLite3($this->settings->getSetting('db_path'));
    }
    
    public function createPaste($data) {
        // 验证输入
        if (empty($data['content'])) {
            return ['status' => 'error', 'message' => 'Content is required'];
        }
        
        // 验证和规范化参数
        $expire_time = isset($data['expire_time']) ? (int)$data['expire_time'] : 2592000; // 默认1个月
        $max_expire_time = (int)$this->settings->getSetting('max_expire_time', 31536000);
        if ($expire_time > $max_expire_time) {
            $expire_time = $max_expire_time;
        }
        
        $max_views = isset($data['max_views']) ? (int)$data['max_views'] : 0;
        if ($max_views > 25565) {
            $max_views = 25565;
        }
        
        $is_markdown = isset($data['is_markdown']) && $data['is_markdown'] ? 1 : 0;
        $is_encrypted = isset($data['is_encrypted']) && $data['is_encrypted'] ? 1 : 0;
        
        // 生成UUID
        $uuid = $this->generateUUID();
        
        // 准备数据
        $now = time();
        $expires_at = $now + $expire_time;
        
        // 插入数据
        $stmt = $this->db->prepare('INSERT INTO notes (
            uuid, content, created_at, expires_at, max_views, 
            current_views, is_encrypted, is_markdown
        ) VALUES (
            :uuid, :content, :created_at, :expires_at, :max_views,
            0, :is_encrypted, :is_markdown
        )');
        
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->bindValue(':content', $data['content'], SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':expires_at', $expires_at, SQLITE3_INTEGER);
        $stmt->bindValue(':max_views', $max_views, SQLITE3_INTEGER);
        $stmt->bindValue(':is_encrypted', $is_encrypted, SQLITE3_INTEGER);
        $stmt->bindValue(':is_markdown', $is_markdown, SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            $response = ['status' => 'success', 'uuid' => $uuid];
            if ($is_encrypted && isset($data['encryption_key'])) {
                $response['encryption_key'] = $data['encryption_key'];
            }
            return $response;
        }
        
        return ['status' => 'error', 'message' => 'Failed to create paste'];
    }
    
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// 处理请求
$method = $_SERVER['REQUEST_METHOD'];
$api = new PasteAPI();

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    echo json_encode($api->createPaste($data));
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
