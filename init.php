<?php
// init.php

// 定义基础配置
$CONFIG = [
    'data_dir' => './data-ekdvbq/',
    'db_path' => './data-ekdvbq/notes.db',
];

// 创建数据目录
if (!file_exists($CONFIG['data_dir'])) {
    if (!mkdir($CONFIG['data_dir'], 0755, true)) {
        die("无法创建数据目录：" . $CONFIG['data_dir'] . "\n");
    }
    echo "已创建数据目录：" . $CONFIG['data_dir'] . "\n";
} else {
    echo "数据目录已存在：" . $CONFIG['data_dir'] . "\n";
}

// 检查目录权限
if (!is_writable($CONFIG['data_dir'])) {
    die("数据目录不可写：" . $CONFIG['data_dir'] . "\n");
}

// 初始化数据库
if (file_exists($CONFIG['db_path'])) {
    echo "数据库文件已存在。是否重新初始化？(y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    if (strtolower($line) !== 'y') {
        die("操作取消\n");
    }
    unlink($CONFIG['db_path']);
}

try {
    $db = new SQLite3($CONFIG['db_path']);
    
    // 创建笔记表
    $db->exec('CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT UNIQUE NOT NULL,
        content TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        max_views INTEGER NOT NULL DEFAULT 0,
        current_views INTEGER NOT NULL DEFAULT 0,
        is_encrypted INTEGER NOT NULL DEFAULT 0,
        is_markdown INTEGER NOT NULL DEFAULT 0
    )');
    
    // 创建设置表
    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    
    // 创建索引
    $db->exec('CREATE INDEX IF NOT EXISTS idx_uuid ON notes (uuid)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_expires_at ON notes (expires_at)');
    
    // 设置文件权限
    chmod($CONFIG['db_path'], 0644);
    
    echo "数据库初始化完成\n";
    
} catch (Exception $e) {
    die("数据库初始化失败：" . $e->getMessage() . "\n");
}
