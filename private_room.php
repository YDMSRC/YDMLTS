[private_room.php](https://github.com/user-attachments/files/28666961/private_room.php)
<?php
// private_room.php - 私聊房间功能，添加房间成员验证
session_start();
header('Content-Type: application/json; charset=utf-8');

// 检查用户是否已登录
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$roomsFile = 'private_rooms.txt';
$roomMessagesDir = 'private_messages/';

// 确保目录存在
if (!file_exists($roomMessagesDir)) {
    mkdir($roomMessagesDir, 0777, true);
}

// 处理不同操作
switch ($action) {
    case 'create':
        createRoom();
        break;
        
    case 'join':
        joinRoom();
        break;
        
    case 'leave':
        leaveRoom();
        break;
        
    case 'list':
        listRooms();
        break;
        
    case 'check_member':
        checkRoomMembership();
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '无效操作']);
        break;
}

function createRoom() {
    global $roomsFile;
    
    $roomName = $_POST['room_name'] ?? '';
    $password = $_POST['password'] ?? '';
    $username = $_SESSION['user'];
    
    if (empty($roomName) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '房间名称和密码不能为空']);
        return;
    }
    
    // 验证房间名称格式
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $roomName)) {
        echo json_encode(['success' => false, 'message' => '房间名称只能包含字母、数字和下划线，长度3-20位']);
        return;
    }
    
    // 验证密码长度
    if (strlen($password) < 4) {
        echo json_encode(['success' => false, 'message' => '密码长度至少4位']);
        return;
    }
    
    // 检查用户是否已经创建了太多房间（限制每个用户最多3个房间）
    if (getUserRoomCount($username) >= 3) {
        echo json_encode(['success' => false, 'message' => '每个用户最多只能创建3个房间']);
        return;
    }
    
    // 检查房间是否已存在
    $rooms = getRooms();
    foreach ($rooms as $room) {
        if ($room['name'] === $roomName) {
            echo json_encode(['success' => false, 'message' => '房间名称已存在']);
            return;
        }
    }
    
    // 创建房间
    $roomData = [
        'name' => $roomName,
        'creator' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('Y-m-d H:i:s'),
        'members' => [$username]
    ];
    
    $rooms[] = $roomData;
    
    if (saveRooms($rooms)) {
        // 设置当前房间
        $_SESSION['current_room'] = $roomName;
        echo json_encode(['success' => true, 'room' => $roomData]);
    } else {
        echo json_encode(['success' => false, 'message' => '创建房间失败']);
    }
}

function joinRoom() {
    global $roomsFile;
    
    $roomName = $_POST['room_name'] ?? '';
    $password = $_POST['password'] ?? '';
    $username = $_SESSION['user'];
    
    if (empty($roomName) || empty($password)) {
        echo json_encode(['success' => false, 'message' => '房间名称和密码不能为空']);
        return;
    }
    
    // 查找房间
    $rooms = getRooms();
    $roomIndex = -1;
    
    foreach ($rooms as $index => $room) {
        if ($room['name'] === $roomName) {
            $roomIndex = $index;
            break;
        }
    }
    
    if ($roomIndex === -1) {
        echo json_encode(['success' => false, 'message' => '房间不存在']);
        return;
    }
    
    // 验证密码
    if (!password_verify($password, $rooms[$roomIndex]['password'])) {
        echo json_encode(['success' => false, 'message' => '密码错误']);
        return;
    }
    
    // 添加用户到房间成员列表（如果不在列表中）
    if (!in_array($username, $rooms[$roomIndex]['members'])) {
        $rooms[$roomIndex]['members'][] = $username;
        
        if (!saveRooms($rooms)) {
            echo json_encode(['success' => false, 'message' => '加入房间失败']);
            return;
        }
    }
    
    // 设置当前房间
    $_SESSION['current_room'] = $roomName;
    echo json_encode(['success' => true, 'room' => $rooms[$roomIndex]]);
}

function leaveRoom() {
    $username = $_SESSION['user'];
    
    // 清除当前房间
    unset($_SESSION['current_room']);
    echo json_encode(['success' => true]);
}

function listRooms() {
    $rooms = getRooms();
    $publicRooms = [];
    
    // 只返回公开信息（不包含密码）
    foreach ($rooms as $room) {
        $publicRooms[] = [
            'name' => $room['name'],
            'creator' => $room['creator'],
            'created_at' => $room['created_at'],
            'member_count' => count($room['members'])
        ];
    }
    
    echo json_encode(['success' => true, 'rooms' => $publicRooms]);
}

function checkRoomMembership() {
    $roomName = $_GET['room_name'] ?? '';
    $username = $_SESSION['user'];
    
    if (empty($roomName)) {
        echo json_encode(['success' => false, 'message' => '房间名称不能为空']);
        return;
    }
    
    $rooms = getRooms();
    foreach ($rooms as $room) {
        if ($room['name'] === $roomName) {
            if (in_array($username, $room['members'])) {
                echo json_encode(['success' => true, 'is_member' => true]);
                return;
            } else {
                echo json_encode(['success' => true, 'is_member' => false]);
                return;
            }
        }
    }
    
    echo json_encode(['success' => false, 'message' => '房间不存在']);
}

function getRooms() {
    global $roomsFile;
    
    if (!file_exists($roomsFile)) {
        return [];
    }
    
    $content = file_get_contents($roomsFile);
    if (empty($content)) {
        return [];
    }
    
    return json_decode($content, true) ?? [];
}

function saveRooms($rooms) {
    global $roomsFile;
    
    return file_put_contents($roomsFile, json_encode($rooms, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function getUserRoomCount($username) {
    $rooms = getRooms();
    $count = 0;
    
    foreach ($rooms as $room) {
        if ($room['creator'] === $username) {
            $count++;
        }
    }
    
    return $count;
}
?>
