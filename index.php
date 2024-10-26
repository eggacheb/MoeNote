<?php
// index.php
require_once 'settings.php';

class PasteBoard {
    private $db;
    private $settings;
    
    public function __construct() {
        $this->settings = new Settings();
        $this->db = new SQLite3($this->settings->getSetting('db_path'));
    }
    
    public function getPaste($uuid) {
        $stmt = $this->db->prepare('SELECT * FROM notes WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 检查是否过期
            if (time() > $row['expires_at']) {
                $this->deletePaste($uuid);
                return null;
            }
            
            // 检查访问次数
            if ($row['max_views'] > 0 && $row['current_views'] >= $row['max_views']) {
                $this->deletePaste($uuid);
                return null;
            }
            
            // 更新访问次数
            $stmt = $this->db->prepare('UPDATE notes SET current_views = current_views + 1 WHERE uuid = :uuid');
            $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
            $stmt->execute();
            
            return $row;
        }
        return null;
    }
    
    private function deletePaste($uuid) {
        $stmt = $this->db->prepare('DELETE FROM notes WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->execute();
    }
}

$pasteboard = new PasteBoard();
$settings = new Settings();
$paste = null;
$uuid = isset($_GET['id']) ? $_GET['id'] : null;

if ($uuid) {
    $paste = $pasteboard->getPaste($uuid);
    if (!$paste) {
        header("HTTP/1.0 404 Not Found");
        echo "Paste not found or expired";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings->getSetting('site_name')); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($settings->getSetting('favicon_path')); ?>">
    <link rel="stylesheet" href="/css/marked.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="container">
        <h1><?php echo htmlspecialchars($settings->getSetting('site_name')); ?></h1>
        <p><?php echo htmlspecialchars($settings->getSetting('site_description')); ?></p>
        
        <?php if ($paste): ?>
            <div class="info">
                浏览次数: <?php echo $paste['current_views']; ?>/<?php echo $paste['max_views'] ? $paste['max_views'] : '∞'; ?>
                | 过期时间: <?php echo date('Y-m-d H:i:s', $paste['expires_at']); ?>
            </div>
            <div id="content" <?php echo $paste['is_encrypted'] ? 'style="display:none;"' : ''; ?>>
                <?php if ($paste['is_markdown']): ?>
                    <div id="markdown-content"></div>
                <?php else: ?>
                    <pre><?php echo htmlspecialchars($paste['content']); ?></pre>
                <?php endif; ?>
            </div>
            <?php if ($paste['is_encrypted']): ?>
                <div id="decrypt-section">
                    <p>此内容已加密。解密密钥应该在URL的#后面。</p>
                    <div id="decryption-error" style="color: red; display: none;">
                        解密失败。请检查解密密钥。
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form id="pasteForm">
                <div class="editor-container">
                    <textarea name="content" placeholder="在此粘贴您的内容..."></textarea>
                    <div class="markdown-preview" style="display: none;"></div>
                </div>
                
                <div class="options">
                    <div class="option">
                        <label>过期时间:</label>
                        <select name="expire_time">
                            <option value="3600">1小时</option>
                            <option value="86400">1天</option>
                            <option value="604800">1周</option>
                            <option value="2592000" selected>1个月</option>
                            <option value="31536000">1年</option>
                        </select>
                    </div>
                    <div class="option">
                        <label>最大浏览次数 (0表示无限制):</label>
                        <input type="number" name="max_views" value="0" min="0" max="25565">
                    </div>
                    <div class="option">
                        <label>
                            <input type="checkbox" name="is_markdown"> 启用Markdown
                        </label>
                    </div>
                    <div class="option">
                        <label>
                            <input type="checkbox" name="is_encrypted"> 启用加密
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit">创建笔记</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script>
        // 修改获取网站域名的方法
        const siteUrl = (() => {
            // 优先使用 X-Forwarded-Host 和 X-Forwarded-Proto
            const forwardedHost = '<?php echo isset($_SERVER["HTTP_X_FORWARDED_HOST"]) ? $_SERVER["HTTP_X_FORWARDED_HOST"] : ""; ?>';
            const forwardedProto = '<?php echo isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) ? $_SERVER["HTTP_X_FORWARDED_PROTO"] : ""; ?>';
            
            if (forwardedHost && forwardedProto) {
                return `${forwardedProto}://${forwardedHost}`;
            }
            
            // 如果没有转发的头部信息，则使用当前请求的 Host
            const protocol = window.location.protocol;
            const host = window.location.host;
            return `${protocol}//${host}`;
        })();
        
        <?php if ($paste && $paste['is_markdown']): ?>
        document.getElementById('markdown-content').innerHTML = marked.parse(<?php echo json_encode($paste['content']); ?>);
        <?php endif; ?>

        <?php if ($paste && $paste['is_encrypted']): ?>
        const encryptedContent = <?php echo json_encode($paste['content']); ?>;
        const decryptionKey = window.location.hash.substring(1);
        
        if (decryptionKey) {
            try {
                const decryptedBytes = CryptoJS.AES.decrypt(encryptedContent, decryptionKey);
                const decryptedContent = decryptedBytes.toString(CryptoJS.enc.Utf8);
                
                if (decryptedContent) {
                    const content = document.getElementById('content');
                    content.style.display = 'block';
                    if (<?php echo $paste['is_markdown'] ? 'true' : 'false'; ?>) {
                        // 修改这里：使用 markdown-content div
                        const markdownContent = document.getElementById('markdown-content');
                        markdownContent.innerHTML = marked.parse(decryptedContent);
                        
                        // 确保图片样式一致
                        markdownContent.querySelectorAll('img').forEach(img => {
                            img.style.maxWidth = '100%';  // 改为100%
                            img.style.height = 'auto';
                            img.style.maxHeight = '600px';  // 增加最大高度
                            img.style.margin = '0.5rem auto';
                            img.style.display = 'block';
                            
                            // 添加点击放大功能
                            img.addEventListener('click', function(e) {
                                e.preventDefault();
                                const modal = document.createElement('div');
                                modal.style.cssText = `
                                    position: fixed;
                                    top: 0;
                                    left: 0;
                                    width: 100%;
                                    height: 100%;
                                    background: rgba(0, 0, 0, 0.9);
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                    z-index: 1000;
                                    cursor: zoom-out;
                                `;
                                
                                const modalImg = document.createElement('img');
                                modalImg.src = this.src;
                                modalImg.style.cssText = `
                                    max-width: 90%;
                                    max-height: 90vh;
                                    object-fit: contain;
                                    border-radius: 4px;
                                `;
                                
                                modal.appendChild(modalImg);
                                document.body.appendChild(modal);
                                
                                modal.addEventListener('click', function() {
                                    document.body.removeChild(modal);
                                });
                            });
                        });
                    } else {
                        content.innerHTML = `<pre>${decryptedContent}</pre>`;
                    }
                    document.getElementById('decrypt-section').style.display = 'none';
                } else {
                    document.getElementById('decryption-error').style.display = 'block';
                }
            } catch (e) {
                document.getElementById('decryption-error').style.display = 'block';
            }
        }
        <?php endif; ?>

        if (document.getElementById('pasteForm')) {
            document.getElementById('pasteForm').onsubmit = async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = Object.fromEntries(formData.entries());
                
                if (data.is_encrypted) {
                    const key = CryptoJS.lib.WordArray.random(32).toString();
                    data.content = CryptoJS.AES.encrypt(data.content, key).toString();
                    data.encryption_key = key;
                }
                
                try {
                    const response = await fetch('api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    if (result.status === 'success') {
                        let url = `${window.location.origin}/${result.uuid}`; // let url = `${window.location.origin}${window.location.pathname}?id=${result.uuid}`;
                        if (data.is_encrypted) {
                            url += '#' + result.encryption_key;
                        }
                        window.location.href = url;
                    } else {
                        alert('Error creating paste: ' + result.message);
                    }
                } catch (error) {
                    alert('Error creating paste: ' + error.message);
                }
            };
        }
        document.addEventListener('DOMContentLoaded', function() {
            const markdownCheckbox = document.querySelector('input[name="is_markdown"]');
            const editorContainer = document.querySelector('.editor-container');
            const textarea = document.querySelector('textarea[name="content"]');
            const markdownPreview = document.querySelector('.markdown-preview');

            let isPreviewScrolling = false;
            let isEditorScrolling = false;
            let lastEditorScrollTop = 0;
            let lastPreviewScrollTop = 0;

            // 只保留输入窗口的滚动同步
            textarea.addEventListener('scroll', function() {
                if (!isPreviewScrolling && markdownPreview.style.display !== 'none') {
                    isEditorScrolling = true;
                    
                    // 计算输入窗口的滚动百分比变化
                    const editorScrollPercentage = this.scrollTop / (this.scrollHeight - this.clientHeight);
                    const previewMaxScroll = markdownPreview.scrollHeight - markdownPreview.clientHeight;
                    
                    // 根据输入窗口的滚动百分比设置预览窗口的位置
                    markdownPreview.scrollTop = previewMaxScroll * editorScrollPercentage;
                    
                    setTimeout(() => isEditorScrolling = false, 50);
                }
            });

            // 删除 markdownPreview 的滚动监听器
            // 移除这段代码：
            // markdownPreview.addEventListener('scroll', function() { ... });

            markdownCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    editorContainer.classList.add('split-view');
                    markdownPreview.style.display = 'block';
                    // 立即同步高度
                    markdownPreview.style.height = `${textarea.offsetHeight}px`;
                    updatePreview(markdownPreview, textarea);
                } else {
                    editorContainer.classList.remove('split-view');
                    markdownPreview.style.display = 'none';
                }
            });

            // 在每次入内容更新时更新预览
            textarea.addEventListener('input', function() {
                updatePreview(markdownPreview, textarea);
            });

            // 添加粘贴事件处理
            textarea.addEventListener('paste', async function(e) {
                const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                
                for (let item of items) {
                    if (item.type.indexOf('image') === 0) {
                        e.preventDefault();
                        
                        const blob = item.getAsFile();
                        const formData = new FormData();
                        formData.append('image', blob);
                        
                        try {
                            // 显示上传进度提示
                            const uploadingText = `![Uploading...]()\n`;
                            const startPos = this.selectionStart;
                            this.value = this.value.substring(0, startPos) + 
                                        uploadingText + 
                                        this.value.substring(this.selectionEnd);
                            
                            // 发送图片到服务器
                            const response = await fetch('upload.php', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            
                            if (result.success) {
                                // 使用完整的URL路径
                                const imageMarkdown = `![](${siteUrl}${result.url})\n`;
                                const currentContent = this.value;
                                const uploadingIndex = currentContent.indexOf(uploadingText);
                                
                                if (uploadingIndex !== -1) {
                                    this.value = currentContent.substring(0, uploadingIndex) + 
                                               imageMarkdown + 
                                               currentContent.substring(uploadingIndex + uploadingText.length);
                                    
                                    // 触发预览更新
                                    updatePreview(markdownPreview, textarea);
                                }
                            } else {
                                // 处理上传失败
                                alert('图片上传失败: ' + result.message);
                            }
                        } catch (error) {
                            alert('图片上传出错: ' + error.message);
                        }
                    }
                }
            });
        });

        function updatePreview(markdownPreview, textarea) {
            if (markdownPreview && markdownPreview.style.display !== 'none') {
                try {
                    const content = textarea.value || '';
                    const parsedContent = marked.parse(content);
                    markdownPreview.innerHTML = parsedContent;
                    
                    // 为预览窗口中的图片添加点击事件
                    markdownPreview.querySelectorAll('img').forEach(img => {
                        img.addEventListener('click', function(e) {
                            e.preventDefault();
                            // 创建模态框显示大图
                            const modal = document.createElement('div');
                            modal.style.cssText = `
                                position: fixed;
                                top: 0;
                                left: 0;
                                width: 100%;
                                height: 100%;
                                background: rgba(0, 0, 0, 0.9);
                                display: flex;
                                justify-content: center;
                                align-items: center;
                                z-index: 1000;
                                cursor: zoom-out;
                            `;
                            
                            const modalImg = document.createElement('img');
                            modalImg.src = this.src;
                            modalImg.style.cssText = `
                                max-width: 90%;
                                max-height: 90vh;
                                object-fit: contain;
                                border-radius: 4px;
                            `;
                            
                            modal.appendChild(modalImg);
                            document.body.appendChild(modal);
                            
                            // 点击模态框关闭
                            modal.addEventListener('click', function() {
                                document.body.removeChild(modal);
                            });
                        });
                    });
                } catch (error) {
                    console.error('Markdown parsing error:', error);
                    markdownPreview.innerHTML = '<p style="color: red;">Error parsing markdown content</p>';
                }
            }
        }
    </script>
</body>
</html>
