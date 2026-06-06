[auth.php](https://github.com/user-attachments/files/28666948/auth.php)
<?php
// auth.php - 修改后的认证文件，添加房间状态检查
session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$usersFile = 'users.txt';
$ipLogFile = 'ip_log.txt';

// 确保用户文件存在
if (!file_exists($usersFile)) {
    file_put_contents($usersFile, '');
}

switch ($action) {
    case 'register':
        registerUser();
        break;
        
    case 'login':
        loginUser();
        break;
        
    case 'logout':
        logoutUser();
        break;
        
    case 'check':
        checkLoginStatus();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效操作']);
        break;
}

function registerUser() {
    global $usersFile, $ipLogFile;
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'];
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
        return;
    }
    
    // 验证用户名格式（支持中文）
    if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]{2,20}$/u', $username)) {
        echo json_encode(['success' => false, 'message' => '用户名只能包含中文、字母、数字和下划线，长度2-20位']);
        return;
    }
    
    // 验证密码长度
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => '密码长度至少6位']);
        return;
    }
    
    // 防恶意注册：检查IP注册频率
    if (!checkIpRegisterLimit($ip)) {
        echo json_encode(['success' => false, 'message' => '注册过于频繁，请稍后再试']);
        return;
    }
    
    // 检查用户名是否已存在
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            echo json_encode(['success' => false, 'message' => '用户名已存在']);
            return;
        }
    }
    
    // 创建新用户
    $userData = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s'),
        'ip' => $ip
    ];
    
    $jsonLine = json_encode($userData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    
    if (file_put_contents($usersFile, $jsonLine, FILE_APPEND | LOCK_EX)) {
        // 记录IP注册信息
        logIpRegistration($ip);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '注册失败，请重试']);
    }
}

function loginUser() {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
        return;
    }
    
    // 验证用户凭据
    $users = getUsers();
    $userFound = false;
    
    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password'])) {
            // 登录成功
            $_SESSION['user'] = $username;
            $_SESSION['login_time'] = time();
            $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];
            
            // 设置登录标记
            $_SESSION['last_activity'] = time();
            
            echo json_encode(['success' => true]);
            $userFound = true;
            break;
        }
    }
    
    if (!$userFound) {
        echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
    }
}

function logoutUser() {
    // 离开当前房间
    if (isset($_SESSION['current_room'])) {
        unset($_SESSION['current_room']);
    }
    
    session_destroy();
    echo json_encode(['success' => true]);
}

function checkLoginStatus() {
    // 检查会话是否过期（30分钟）
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_destroy();
        echo json_encode(['loggedIn' => false]);
        return;
    }
    
    // 更新最后活动时间
    $_SESSION['last_activity'] = time();
    
    if (isset($_SESSION['user'])) {
        $response = [
            'loggedIn' => true, 
            'username' => $_SESSION['user']
        ];
        
        // 检查是否有当前房间
        if (isset($_SESSION['current_room'])) {
            $response['current_room'] = $_SESSION['current_room'];
        }
        
        echo json_encode($response);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
}

function getUsers() {
    global $usersFile;
    $users = [];
    
    if (file_exists($usersFile)) {
        $lines = file($usersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $user = json_decode($line, true);
            if ($user) {
                $users[] = $user;
            }
        }
    }
    
    return $users;
}

// 防恶意注册功能：检查IP注册频率
function checkIpRegisterLimit($ip) {
    global $ipLogFile;
    
    if (!file_exists($ipLogFile)) {
        return true;
    }
    
    $ipLogs = file($ipLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $recentRegistrations = 0;
    $timeLimit = time() - 3600; // 1小时内
    
    foreach ($ipLogs as $log) {
        $logData = json_decode($log, true);
        if ($logData && $logData['ip'] === $ip) {
            if ($logData['timestamp'] > $timeLimit) {
                $recentRegistrations++;
            }
        }
    }
    
    // 限制：1小时内最多注册3个账号
    return $recentRegistrations < 3;
}

// 记录IP注册信息
function logIpRegistration($ip) {
    global $ipLogFile;
    
    $logData = [
        'ip' => $ip,
        'timestamp' => time(),
        'date' => date('Y-m-d H:i:s')
    ];
    
    $jsonLine = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($ipLogFile, $jsonLine, FILE_APPEND | LOCK_EX);
}
?>
