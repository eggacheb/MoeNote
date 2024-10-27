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
    
    private function getChineseTime($timestamp) {
        date_default_timezone_set('Asia/Shanghai');
        return $timestamp;
    }
    
    public function getPaste($uuid) {
        $stmt = $this->db->prepare('SELECT * FROM notes WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 检查是否过期（使用中国时间）
            date_default_timezone_set('Asia/Shanghai');
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
                | 过期时间: <?php 
                    date_default_timezone_set('Asia/Shanghai');
                    echo date('Y-m-d H:i:s', $paste['expires_at']); 
                ?>
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
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="paste">URL分享</button>
                <button class="tab-button" data-tab="code">提取码分享</button>
                <button class="tab-button" data-tab="file">文件分享</button>
            </div>
            
            <!-- 原有的文本分享表单 -->
            <form id="pasteForm" style="display: block;">
                <div class="editor-container">
                    <textarea name="content" placeholder="在此粘贴您要分享的内容"></textarea>
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
            
            <!-- 提取码分享表单 -->
            <form id="textShareForm" style="display: none;">
                <div class="editor-container">
                    <textarea name="content" placeholder="在此输入您要分享的文本内容..."></textarea>
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
                        <label>最大查看次数 (0表示无限制):</label>
                        <input type="number" name="max_downloads" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit">生成提取码</button>
                </div>
            </form>
            
            <!-- 文件上传表单 -->
            <form id="fileForm" style="display: none;">
                <div class="file-upload-container">
                    <input type="file" name="file" id="fileInput">
                    <div class="file-info"></div>
                    <div class="upload-progress" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-bar-fill"></div>
                        </div>
                        <div class="progress-text">0%</div>
                    </div>
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
                        <label>最大下载次数 (0表示无限制):</label>
                        <input type="number" name="max_downloads" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit">上传文件</button>
                </div>
            </form>
            
            <!-- 提取码输入区域 -->
            <div class="code-input-container">
                <input type="text" id="codeInput" placeholder="输入提取码">
                <button onclick="getFile()">获取内容</button>
            </div>
            
            <!-- 文件信息显示区域 -->
            <div id="fileInfo" style="display: none;">
                <div class="info">
                    <span class="downloads"></span>
                    <span class="expires"></span>
                </div>
                <div class="content"></div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script>
        // 修改获取网站域名的方法
        const siteUrl = (() => {
            // 优使用 X-Forwarded-Host 和 X-Forwarded-Proto
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
                    
                    // 根据输入窗口的滚动百比设置预览窗口的位置
                    markdownPreview.scrollTop = previewMaxScroll * editorScrollPercentage;
                    
                    setTimeout(() => isEditorScrolling = false, 50);
                }
            });

            // 删除 markdownPreview 的滚动监听器
            // 移除这代码：
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

        // 在原有的 script 标中添加/修改
        document.addEventListener('DOMContentLoaded', function() {
            // 标签切换
            const tabButtons = document.querySelectorAll('.tab-button');
            const pasteForm = document.getElementById('pasteForm');
            const textShareForm = document.getElementById('textShareForm');
            const fileForm = document.getElementById('fileForm');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tab = this.dataset.tab;
                    
                    // 更新按钮状态
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // 显示/隐藏表单
                    pasteForm.style.display = tab === 'paste' ? 'block' : 'none';
                    textShareForm.style.display = tab === 'code' ? 'block' : 'none';
                    fileForm.style.display = tab === 'file' ? 'block' : 'none';
                });
            });
            
            // 文本分享表单处理
            if (textShareForm) {
                textShareForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const formData = new FormData(e.target);
                    
                    try {
                        const response = await fetch('file_api.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const result = await response.json();
                        if (result.status === 'success') {
                            // 显示提取码和分享链接
                            const shareUrl = `${window.location.origin}?code=${result.code}`;
                            const modal = document.createElement('div');
                            modal.className = 'modal';
                            modal.innerHTML = `
                                <div class="modal-content">
                                    <h3>分享成功！</h3>
                                    <div class="share-info">
                                        <div class="copy-group">
                                            <label>提取码：</label>
                                            <span class="copy-text" data-clipboard="${result.code}">${result.code}</span>
                                            <button class="copy-btn" onclick="copyText(this.previousElementSibling)">复制</button>
                                        </div>
                                        <div class="copy-group">
                                            <label>分享链接：</label>
                                            <span class="copy-text" data-clipboard="${shareUrl}">${shareUrl}</span>
                                            <button class="copy-btn" onclick="copyText(this.previousElementSibling)">复制</button>
                                        </div>
                                    </div>
                                    <button class="close-btn" onclick="this.parentElement.parentElement.remove()">关闭</button>
                                </div>
                            `;
                            document.body.appendChild(modal);
                        } else {
                            alert('分享失败：' + result.message);
                        }
                    } catch (error) {
                        alert('分享出错：' + error.message);
                    }
                };
            }
            
            // 文件上传表单处理
            if (fileForm) {
                const fileInput = document.getElementById('fileInput');
                const fileInfo = document.querySelector('.file-info');
                const progressBar = document.querySelector('.progress-bar-fill');
                const progressText = document.querySelector('.progress-text');
                const uploadProgress = document.querySelector('.upload-progress');
                
                fileInput.addEventListener('change', function() {
                    if (this.files[0]) {
                        const file = this.files[0];
                        const size = (file.size / 1024 / 1024).toFixed(2);
                        fileInfo.textContent = `文件名: ${file.name}, 大小: ${size}MB`;
                    }
                });
                
                fileForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const formData = new FormData(e.target);
                    
                    try {
                        uploadProgress.style.display = 'block';
                        
                        const xhr = new XMLHttpRequest();
                        xhr.upload.onprogress = (e) => {
                            if (e.lengthComputable) {
                                const percentComplete = Math.round((e.loaded / e.total) * 100);
                                progressBar.style.width = percentComplete + '%';
                                progressText.textContent = percentComplete + '%';
                            }
                        };
                        
                        // 使用 Promise 包装 XHR 请求
                        const response = await new Promise((resolve, reject) => {
                            xhr.onload = () => {
                                if (xhr.status === 200) {
                                    try {
                                        resolve(JSON.parse(xhr.responseText));
                                    } catch (e) {
                                        reject(new Error('解析响应失败'));
                                    }
                                } else {
                                    reject(new Error('上传失败'));
                                }
                            };
                            xhr.onerror = () => reject(new Error('网络错误'));
                            
                            xhr.open('POST', 'file_api.php', true);
                            xhr.send(formData);
                        });
                        
                        if (response.status === 'success') {
                            const shareUrl = `${window.location.origin}?code=${response.code}`;
                            const modal = document.createElement('div');
                            modal.className = 'modal';
                            modal.innerHTML = `
                                <div class="modal-content">
                                    <h3>上传成功！</h3>
                                    <div class="share-info">
                                        <div class="copy-group">
                                            <label>提取码：</label>
                                            <span class="copy-text" data-clipboard="${response.code}">${response.code}</span>
                                            <button class="copy-btn" onclick="copyText(this.previousElementSibling)">复制</button>
                                        </div>
                                        <div class="copy-group">
                                            <label>分享链接：</label>
                                            <span class="copy-text" data-clipboard="${shareUrl}">${shareUrl}</span>
                                            <button class="copy-btn" onclick="copyText(this.previousElementSibling)">复制</button>
                                        </div>
                                    </div>
                                    <button class="close-btn" onclick="this.parentElement.parentElement.remove()">关闭</button>
                                </div>
                            `;
                            document.body.appendChild(modal);
                            
                            // 重置进度条
                            setTimeout(() => {
                                uploadProgress.style.display = 'none';
                                progressBar.style.width = '0%';
                                progressText.textContent = '0%';
                            }, 1000);
                        } else {
                            alert('上传失败：' + response.message);
                        }
                    } catch (error) {
                        alert('上传出错：' + error.message);
                    }
                };
            }
        });

        // 修改获取文件函数
        async function getFile() {
            const code = document.getElementById('codeInput').value.trim();
            if (!code) {
                alert('请输入提取码');
                return;
            }
            
            // 显示加载动画
            const loadingModal = document.createElement('div');
            loadingModal.className = 'modal';
            loadingModal.innerHTML = `
                <div class="modal-content">
                    <h3>正在获取内容...</h3>
                    <div class="loading-spinner"></div>
                </div>
            `;
            document.body.appendChild(loadingModal);
            
            try {
                const response = await fetch(`file_api.php?code=${code}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    if (result.content) {
                        // 文本内容
                        const fileInfo = document.getElementById('fileInfo');
                        fileInfo.style.display = 'block';
                        fileInfo.querySelector('.content').innerHTML = `<pre>${result.content}</pre>`;
                        
                        // 添加过期时间显示
                        const info = fileInfo.querySelector('.info');
                        if (info) {
                            info.innerHTML = `
                                下载次数: ${result.current_downloads}/${result.max_downloads ? result.max_downloads : '∞'}
                                | 过期时间: ${result.expires_at_formatted}
                            `;
                        }
                    } else {
                        // 文件下载 - 创建下载链接
                        const downloadUrl = `${siteUrl}/file_api.php?download=${code}&filename=${encodeURIComponent(result.filename)}`;
                        window.location.href = downloadUrl;
                    }
                } else {
                    alert(result.message || '获取内容失败');
                }
            } catch (error) {
                alert('获取文件失败：' + error.message);
            } finally {
                // 移除加载动画
                loadingModal.remove();
            }
        }

        // 添加复制功能
        function copyText(element) {
            const text = element.getAttribute('data-clipboard');
            navigator.clipboard.writeText(text).then(() => {
                // 显示复制成功提示
                const originalText = element.nextElementSibling.textContent;
                element.nextElementSibling.textContent = '已复制！';
                setTimeout(() => {
                    element.nextElementSibling.textContent = originalText;
                }, 1000);
            }).catch(err => {
                console.error('复制失败:', err);
            });
        }

        // 在 DOMContentLoaded 事件中添加自动填充提取码的功能
        document.addEventListener('DOMContentLoaded', function() {
            // 检查 URL 中是否有提取码
            const urlParams = new URLSearchParams(window.location.search);
            const code = urlParams.get('code');
            if (code) {
                // 自动填充提取码并触发获取
                const codeInput = document.getElementById('codeInput');
                if (codeInput) {
                    codeInput.value = code;
                    getFile(); // 自动获取文件
                }
            }
            // ... 其他现有的 DOMContentLoaded 代码 ...
        });
    </script>

    <!-- 添加模态框样式 -->
    <style>
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
    }

    .modal-content {
        background: var(--container-bg);
        padding: 2rem;
        border-radius: 8px;
        box-shadow: var(--shadow);
        text-align: center;
    }

    .modal-content h3 {
        margin-bottom: 1rem;
    }

    .modal-content strong {
        font-size: 1.2rem;
        color: var(--primary-color);
    }

    .modal-content button {
        margin-top: 1rem;
        padding: 0.5rem 1rem;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    .modal-content button:hover {
        background: var(--primary-hover);
    }
    </style>
</body>
</html>
