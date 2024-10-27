<?php
header('Content-Type: application/json');

// 设置允许的图片类型
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 20 * 1024 * 1024; // 20MB

try {
    // 确保上传目录存在
    $upload_dir = __DIR__ . '/uploads/images/';
    if (!is_dir($upload_dir)) {
        // 尝试创建目录
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('无法创建上传目录，请检查权限');
        }
        
        // 创建 .htaccess 文件来保护上传目录
        $htaccess = $upload_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "DirectoryIndex 403.html\n";  // 使用 403.html 作为目录默认页
            $htaccess_content .= "AddType text/plain .php\n";
            $htaccess_content .= "AddType text/plain .html\n";
            $htaccess_content .= "AddType text/plain .htm\n";
            $htaccess_content .= "AddType text/plain .htaccess\n";
            file_put_contents($htaccess, $htaccess_content);
        }
        
        // 创建 index.html 文件来防止目录列表
        $index_html = $upload_dir . 'index.html';
        if (!file_exists($index_html)) {
            file_put_contents($index_html, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>');
        }
        
        // 创建 403.html 文件来防止目录列表
        $forbidden_page = $upload_dir . '403.html';
        if (!file_exists($forbidden_page)) {
            file_put_contents($forbidden_page, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Access to this directory is forbidden.</p></body></html>');
        }
    }

    if (!isset($_FILES['image'])) {
        throw new Exception('没有收到图片文件');
    }

    $file = $_FILES['image'];
    
    // 检查错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传错误: ' . $file['error']);
    }
    
    // 检查文件类型
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('不支持的文件类型');
    }
    
    // 检查文件大小
    if ($file['size'] > $max_size) {
        throw new Exception('文件大小超过限制');
    }
    
    // 生成唯一文件名
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    
    // 移动文件到目标位置
    $filepath = $upload_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('文件保存失败');
    }
    
    // 设置文件权限
    chmod($filepath, 0644);
    
    // 在成功上传后添加 .htaccess 文件来设置图片缓存
    $images_htaccess = $upload_dir . '.htaccess';
    if (!file_exists($images_htaccess)) {
        $cache_rules = "
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg \"access plus 1 year\"
    ExpiresByType image/jpeg \"access plus 1 year\"
    ExpiresByType image/gif \"access plus 1 year\"
    ExpiresByType image/png \"access plus 1 year\"
    ExpiresByType image/webp \"access plus 1 year\"
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch \"\.(jpg|jpeg|png|gif|webp)$\">
        Header set Cache-Control \"public, max-age=31536000\"
    </FilesMatch>
</IfModule>

Options -Indexes
DirectoryIndex 403.html
AddType text/plain .php
AddType text/plain .html
AddType text/plain .htm
AddType text/plain .htaccess
";
        file_put_contents($images_htaccess, $cache_rules);
    }
    
    // 返回成功响应
    echo json_encode([
        'success' => true,
        'url' => '/uploads/images/' . $filename
    ]);
    
} catch (Exception $e) {
    error_log('Image upload error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
