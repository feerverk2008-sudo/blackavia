<?php
session_start();
$admin_pass = 'admin'; // Пароль для входа в админку (смени)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if ($_POST['admin_password'] === $admin_pass) $_SESSION['admin_auth'] = true;
}
if (!isset($_SESSION['admin_auth'])) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Admin</title></head><body style="background:#000;color:#0f0;"><form method="post"><input type="password" name="admin_password" placeholder="Пароль"><button>Войти</button></form></body></html>';
    exit;
}
require_once 'config.php';
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("DB error");

// Изменение баланса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balance'])) {
    $player_id = $_POST['player_id'];
    $new = intval($_POST['new_balance']);
    $conn->query("UPDATE users SET balance = $new WHERE player_id = '$player_id'");
    header('Location: admin.php');
    exit;
}
$users = $conn->query("SELECT * FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>BLACK VAIL Admin</title><style>body{background:#3d0000;color:#ffddcc;font-family:monospace;padding:20px;}table{border-collapse:collapse;width:100%;background:#2a0000;}th,td{border:1px solid #ff8844;padding:8px;}th{background:#660000;}input,button{background:#5a1e1e;border:1px solid #ffaa00;color:#ffdd99;padding:5px;}</style></head><body><h1>Админ-панель BLACK VAIL</h1>
<table border="1"><tr><th>ID игрока</th><th>Логин</th><th>Telegram</th><th>Баланс</th><th>Выиграно</th><th>Проиграно</th><th>Действие</th></tr>
<?php while($row = $users->fetch_assoc()): ?>
<tr>
    <td><?=$row['player_id']?></td>
    <td><?=htmlspecialchars($row['username'])?></td>
    <td><?=htmlspecialchars($row['telegram'])?></td>
    <td><?=$row['balance']?></td>
    <td><?=$row['total_won']?></td>
    <td><?=$row['total_lost']?></td>
    <td>
        <form method="post">
            <input type="hidden" name="player_id" value="<?=$row['player_id']?>">
            <input type="number" name="new_balance" value="<?=$row['balance']?>">
            <button type="submit" name="update_balance">Изменить</button>
        </form>
    </td>
</tr>
<?php endwhile; ?>
</table>
</body></html>
