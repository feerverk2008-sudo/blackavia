<?php
error_reporting(0);
session_start();

// ------------------------- АВТО-УСТАНОВКА (БД И КОНФИГ) -------------------------
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_db'])) {
    $host = $_POST['db_host'];
    $name = $_POST['db_name'];
    $user = $_POST['db_user'];
    $pass = $_POST['db_pass'];
    $conn = @new mysqli($host, $user, $pass, $name);
    if ($conn->connect_error) die("<h3>Ошибка БД: ".$conn->connect_error."</h3><a href='?'>Назад</a>");
    
    $sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram VARCHAR(100) NOT NULL UNIQUE,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        player_id VARCHAR(10) UNIQUE NOT NULL,
        balance INT DEFAULT 0,
        total_won INT DEFAULT 0,
        total_lost INT DEFAULT 0,
        reg_date DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        player_id VARCHAR(10),
        amount INT,
        type VARCHAR(50),
        status VARCHAR(20),
        payment_id VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ";
    if ($conn->multi_query($sql)) while ($conn->next_result()) ;
    
    $config = "<?php\n\$db_host='$host';\n\$db_user='$user';\n\$db_pass='$pass';\n\$db_name='$name';\n?>";
    file_put_contents($configFile, $config);
    echo "<script>alert('Установка завершена! Перезагрузите страницу.'); location.href='?';</script>";
    exit;
}

if (!file_exists($configFile)) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Установка BLACK VAIL</title><style>body{background:#000;color:#0f0;padding:2rem;}</style></head><body>
    <h1>Установка казино BLACK VAIL</h1>
    <form method="post">
        <input type="hidden" name="install_db" value="1">
        <input type="text" name="db_host" placeholder="Хост MySQL (обычно localhost)" required><br>
        <input type="text" name="db_name" placeholder="Имя базы данных" required><br>
        <input type="text" name="db_user" placeholder="Логин MySQL" required><br>
        <input type="password" name="db_pass" placeholder="Пароль MySQL" required><br>
        <button type="submit">Установить</button>
    </form>
    </body></html>';
    exit;
}

require_once $configFile;
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Ошибка подключения к БД");

// ------------------------- ФУНКЦИИ -------------------------
function getUserByTelegram($conn, $telegram) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE telegram = ?");
    $stmt->bind_param("s", $telegram);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function getUserByPlayerId($conn, $player_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE player_id = ?");
    $stmt->bind_param("s", $player_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
function updateBalance($conn, $player_id, $new_balance) {
    $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE player_id = ?");
    $stmt->bind_param("is", $new_balance, $player_id);
    $stmt->execute();
    $_SESSION['balance'] = $new_balance;
}
function createUser($conn, $telegram, $password) {
    $users = $conn->query("SELECT * FROM users");
    $username = ltrim($telegram, '@');
    $orig = $username;
    $counter = 1;
    while ($conn->query("SELECT id FROM users WHERE username='$username'")->num_rows > 0) {
        $username = $orig . '_' . $counter++;
    }
    $player_id = substr(md5(uniqid()), 0, 6);
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (telegram, username, password, player_id, balance) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("ssss", $telegram, $username, $hashed, $player_id);
    if ($stmt->execute()) {
        return ['playerId' => $player_id, 'balance' => 0, 'username' => $username];
    }
    return null;
}

// ------------------------- ОБРАБОТКА ВХОДА/РЕГИСТРАЦИИ -------------------------
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $telegram = trim($_POST['telegram']);
    $password = $_POST['password'];
    if (strlen($password) < 4) {
        $login_error = 'Пароль минимум 4 символа';
    } else {
        $user = getUserByTelegram($conn, $telegram);
        if ($user) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['player_id'] = $user['player_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['balance'] = $user['balance'];
            } else {
                $login_error = 'Неверный пароль';
            }
        } else {
            $newUser = createUser($conn, $telegram, $password);
            if ($newUser) {
                $_SESSION['player_id'] = $newUser['playerId'];
                $_SESSION['username'] = $newUser['username'];
                $_SESSION['balance'] = $newUser['balance'];
            } else {
                $login_error = 'Ошибка создания пользователя';
            }
        }
    }
}

// ------------------------- ВЕБХУК ДЛЯ NOWPAYMENTS (имитация, но с реальным кодом) -------------------------
// Здесь ты разместишь реальный обработчик от NOWPayments. Для теста используем GET-параметр.
if (isset($_GET['nowpayments_webhook']) && isset($_GET['payment_id']) && isset($_GET['player_id']) && isset($_GET['amount'])) {
    $payment_id = $_GET['payment_id'];
    $player_id = $_GET['player_id'];
    $amount = intval($_GET['amount']);
    // Проверяем, не обработан ли уже этот платёж
    $check = $conn->query("SELECT id FROM transactions WHERE payment_id='$payment_id' LIMIT 1");
    if ($check->num_rows == 0) {
        $user = getUserByPlayerId($conn, $player_id);
        if ($user) {
            $new_balance = $user['balance'] + $amount;
            updateBalance($conn, $player_id, $new_balance);
            $conn->query("INSERT INTO transactions (player_id, amount, type, status, payment_id) VALUES ('$player_id', $amount, 'deposit', 'completed', '$payment_id')");
            // Логируем для админки
        }
    }
    die('OK');
}

// ------------------------- API ДЛЯ ИГР (AJAX) -------------------------
if (isset($_GET['api']) && isset($_SESSION['player_id'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $player_id = $_SESSION['player_id'];
    
    // Получить баланс
    if ($action == 'balance') {
        $user = getUserByPlayerId($conn, $player_id);
        echo json_encode(['balance' => $user['balance']]);
        exit;
    }
    
    // Креш-игра: сделать ставку и получить множитель (здесь упрощённо, для полноценной игры нужен цикл)
    if ($action == 'crash_bet') {
        $bet = intval($_POST['bet']);
        $user = getUserByPlayerId($conn, $player_id);
        if ($user['balance'] < $bet) die(json_encode(['error' => 'Недостаточно средств']));
        $new_balance = $user['balance'] - $bet;
        updateBalance($conn, $player_id, $new_balance);
        // Генерируем крах (подкрученный)
        $rand = mt_rand(1, 100);
        if ($rand <= 80) $crash = mt_rand(101, 180) / 100;
        elseif ($rand <= 95) $crash = mt_rand(181, 300) / 100;
        else $crash = mt_rand(301, 3000) / 100;
        $crash = round($crash, 2);
        // Сохраняем сессию игры
        $_SESSION['crash_bet'] = $bet;
        $_SESSION['crash_multiplier'] = 1.0;
        $_SESSION['crash_crash_point'] = $crash;
        $_SESSION['crash_active'] = true;
        echo json_encode(['success' => true, 'new_balance' => $new_balance, 'crash_point' => $crash]);
        exit;
    }
    
    // Забрать выигрыш (кэшаут)
    if ($action == 'crash_cashout') {
        if (!isset($_SESSION['crash_active'])) die(json_encode(['error' => 'Нет активной игры']));
        $mult = $_SESSION['crash_multiplier'];
        $bet = $_SESSION['crash_bet'];
        $win = round($bet * $mult);
        $user = getUserByPlayerId($conn, $player_id);
        $new_balance = $user['balance'] + $win;
        updateBalance($conn, $player_id, $new_balance);
        $conn->query("UPDATE users SET total_won = total_won + $win WHERE player_id='$player_id'");
        unset($_SESSION['crash_active']);
        echo json_encode(['success' => true, 'win' => $win, 'multiplier' => $mult]);
        exit;
    }
    
    // Получить текущий множитель (для анимации)
    if ($action == 'crash_multiplier') {
        if (!isset($_SESSION['crash_active'])) echo json_encode(['active' => false, 'multiplier' => 1.0]);
        else {
            // Увеличиваем множитель (имитация роста)
            $mult = $_SESSION['crash_multiplier'] + 0.02;
            $_SESSION['crash_multiplier'] = $mult;
            $crash_point = $_SESSION['crash_crash_point'];
            if ($mult >= $crash_point) {
                // Крах
                unset($_SESSION['crash_active']);
                echo json_encode(['active' => false, 'crashed' => true, 'multiplier' => $crash_point]);
            } else {
                echo json_encode(['active' => true, 'multiplier' => $mult]);
            }
        }
        exit;
    }
    
    // Слот-игра (простой пример)
    if ($action == 'slot_spin') {
        $bet = intval($_POST['bet']);
        $user = getUserByPlayerId($conn, $player_id);
        if ($user['balance'] < $bet) die(json_encode(['error' => 'Недостаточно средств']));
        $new_balance = $user['balance'] - $bet;
        updateBalance($conn, $player_id, $new_balance);
        // Эмуляция слота (эмодзи)
        $symbols = ['🍒', '🍋', '🍊', '🍉', '🔔', '💎'];
        $reels = [$symbols[array_rand($symbols)], $symbols[array_rand($symbols)], $symbols[array_rand($symbols)]];
        $win = 0;
        if ($reels[0] == $reels[1] && $reels[1] == $reels[2]) {
            $win = $bet * 10;
            $new_balance += $win;
            updateBalance($conn, $player_id, $new_balance);
            $conn->query("UPDATE users SET total_won = total_won + $win WHERE player_id='$player_id'");
        }
        echo json_encode(['reels' => $reels, 'win' => $win, 'new_balance' => $new_balance]);
        exit;
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>BLACK VAIL | КАЗИНО</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif;}
        body{background:#000;color:#eee;}
        .bg{position:fixed;top:0;left:0;width:100%;height:100%;background:radial-gradient(circle at 30% 20%,#1a0000,#000);z-index:-2;}
        .container{max-width:1300px;margin:0 auto;padding:20px;}
        .header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;background:rgba(0,0,0,0.7);border-radius:60px;padding:10px 30px;margin-bottom:30px;border:1px solid #f44;}
        .avatar{width:70px;border-radius:50%;border:2px solid gold;}
        h1{font-size:2.5rem;background:linear-gradient(135deg,#ff2a2a,#ffcc00);-webkit-background-clip:text;background-clip:text;color:transparent;}
        .user-panel{background:#111;padding:10px 20px;border-radius:40px;gap:20px;display:flex;border:1px solid #fc0;}
        .game-btn{background:#8b0000;color:#ffcc00;padding:15px 30px;font-size:1.2rem;border-radius:50px;cursor:pointer;border:none;margin:10px;}
        .game-section{margin-top:30px; background:#111; border-radius:30px; padding:20px;}
        .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:1000;justify-content:center;align-items:center;}
        .modal-content{background:#1a0000;border:3px solid #f00;border-radius:30px;width:800px;max-width:90%;padding:20px;}
        .close{float:right;font-size:30px;cursor:pointer;color:#f44;}
        #multiplier{font-size:3rem; text-align:center; color:#0f0;}
        .slot-reels{font-size:3rem; text-align:center; letter-spacing:20px;}
        .login-box{background:#111;padding:40px;border-radius:30px;max-width:400px;margin:100px auto;}
        .login-box input{width:100%;padding:10px;margin:10px 0;}
        .login-box button{width:100%;padding:10px;background:#f44;border:none;font-weight:bold;}
    </style>
</head>
<body>
<div class="bg"></div>
<div class="container">
    <div class="header">
        <img src="https://cdn-icons-png.flaticon.com/512/271/271284.png" class="avatar">
        <h1>BLACK VAIL</h1>
        <?php if(isset($_SESSION['player_id'])): ?>
        <div class="user-panel">
            <span>👤 <strong><?=htmlspecialchars($_SESSION['username'])?></strong></span>
            <span>🆔 <strong><?=htmlspecialchars($_SESSION['player_id'])?></strong></span>
            <span>💰 <span id="balanceDisplay"><?=$_SESSION['balance']?></span> грн</span>
        </div>
        <?php endif; ?>
    </div>
    <?php if(!isset($_SESSION['player_id'])): ?>
        <div class="login-box">
            <form method="post">
                <input type="text" name="telegram" placeholder="Telegram @username" required>
                <input type="password" name="password" placeholder="Пароль (мин 4)" required>
                <button type="submit" name="login">Войти / Регистрация</button>
                <?php if($login_error) echo "<p style='color:red'>$login_error</p>"; ?>
            </form>
        </div>
    <?php else: ?>
        <div style="text-align:center; margin-bottom:20px;">
            <button class="game-btn" id="crashBtn">✈️ АВИАТОР (КРЕШ)</button>
            <button class="game-btn" id="slotBtn">🎰 СЛОТ</button>
            <button class="game-btn" id="depositBtn">💳 ПОПОЛНИТЬ 500 грн</button>
        </div>
        <div id="gameArea" class="game-section"></div>
    <?php endif; ?>
</div>

<!-- Модалка для игры -->
<div id="gameModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <div id="gameModalContent"></div>
    </div>
</div>

<script>
let currentBalance = <?= isset($_SESSION['balance']) ? $_SESSION['balance'] : 0 ?>;
let apiBase = window.location.pathname;

async function fetchAPI(action, data={}) {
    let url = apiBase + '?api=' + action;
    let options = { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'} };
    if (Object.keys(data).length) {
        let body = new URLSearchParams(data);
        options.body = body;
    }
    let res = await fetch(url, options);
    return res.json();
}

function updateBalanceUI() {
    fetchAPI('balance').then(data => {
        if (data.balance !== undefined) {
            currentBalance = data.balance;
            document.getElementById('balanceDisplay').innerText = currentBalance;
        }
    });
}

// ------------------------- КРЕШ ИГРА (AVIA) -------------------------
let crashInterval = null;
let crashActive = false;
let crashBet = 0;

async function startCrashGame() {
    let bet = prompt('Ставка (от 10 грн):', '10');
    bet = parseInt(bet);
    if (isNaN(bet) || bet < 10) return alert('Минимум 10 грн');
    if (bet > currentBalance) return alert('Недостаточно средств');
    let res = await fetchAPI('crash_bet', { bet });
    if (res.error) return alert(res.error);
    currentBalance = res.new_balance;
    document.getElementById('balanceDisplay').innerText = currentBalance;
    crashActive = true;
    crashBet = bet;
    let modal = document.getElementById('gameModal');
    let modalContent = document.getElementById('gameModalContent');
    modalContent.innerHTML = `
        <h2>AVIA (КРЕШ)</h2>
        <div id="multiplier" style="font-size:4rem;">1.00x</div>
        <button id="cashoutBtn" style="padding:10px 20px;">ЗАБРАТЬ ВЫИГРЫШ</button>
        <p id="crashStatus"></p>
    `;
    modal.style.display = 'flex';
    if (crashInterval) clearInterval(crashInterval);
    crashInterval = setInterval(async () => {
        if (!crashActive) return;
        let multRes = await fetchAPI('crash_multiplier');
        if (multRes.crashed) {
            clearInterval(crashInterval);
            crashActive = false;
            document.getElementById('multiplier').innerHTML = multRes.multiplier.toFixed(2) + 'x (КРАХ!)';
            document.getElementById('crashStatus').innerHTML = '💥 Вы проиграли ставку!';
            document.getElementById('cashoutBtn').disabled = true;
            updateBalanceUI();
        } else if (multRes.active) {
            document.getElementById('multiplier').innerHTML = multRes.multiplier.toFixed(2) + 'x';
        } else {
            clearInterval(crashInterval);
            crashActive = false;
        }
    }, 200);
    document.getElementById('cashoutBtn').onclick = async () => {
        if (!crashActive) return;
        let cashRes = await fetchAPI('crash_cashout');
        if (cashRes.success) {
            clearInterval(crashInterval);
            crashActive = false;
            document.getElementById('multiplier').innerHTML = cashRes.multiplier.toFixed(2) + 'x';
            document.getElementById('crashStatus').innerHTML = `✅ Вы выиграли ${cashRes.win} грн!`;
            document.getElementById('cashoutBtn').disabled = true;
            currentBalance += cashRes.win;
            document.getElementById('balanceDisplay').innerText = currentBalance;
            updateBalanceUI();
        } else {
            alert(cashRes.error);
        }
    };
}

// ------------------------- СЛОТ -------------------------
async function playSlot() {
    let bet = prompt('Ставка на спин (от 10 грн):', '10');
    bet = parseInt(bet);
    if (isNaN(bet) || bet < 10) return alert('Минимум 10 грн');
    if (bet > currentBalance) return alert('Недостаточно средств');
    let res = await fetchAPI('slot_spin', { bet });
    if (res.error) return alert(res.error);
    currentBalance = res.new_balance;
    document.getElementById('balanceDisplay').innerText = currentBalance;
    let modal = document.getElementById('gameModal');
    let modalContent = document.getElementById('gameModalContent');
    modalContent.innerHTML = `
        <h2>СЛОТ</h2>
        <div class="slot-reels">${res.reels.join(' ')}</div>
        <p>Выигрыш: ${res.win} грн</p>
        <button onclick="location.reload()">Закрыть</button>
    `;
    modal.style.display = 'flex';
}

// ------------------------- ДЕПОЗИТ ЧЕРЕЗ NOWPAYMENTS (500 грн) -------------------------
function depositNow() {
    let player_id = '<?= $_SESSION['player_id'] ?? '' ?>';
    let amount = 500;
    // Формируем ссылку на NOWPayments. В реальности ты должен создать платёж через их API и получить invoice_url.
    // Для простоты используем демо-ссылку с параметрами, которые обработает твой вебхук.
    // Но правильнее: сделать AJAX запрос на твой сервер, который вызовет API NOWPayments и вернёт URL.
    // Ниже упрощённый вариант для демонстрации.
    let paymentUrl = `https://nowpayments.io/payment?i=DEMO&amount=500&currency=USD&order_id=${player_id}`;
    // В реальности после успешной оплаты NOWPayments отправит вебхук на твой сайт (например, /?nowpayments_webhook=1&payment_id=...&player_id=...&amount=500)
    // Тебе нужно будет обработать этот запрос и начислить баланс.
    alert('В боевой версии здесь будет переход на NOWPayments. Сейчас – демо.');
    // Для теста можно сэмулировать вебхук:
    // window.location.href = '/?nowpayments_webhook=1&payment_id=test&player_id='+player_id+'&amount=500';
}
// ------------------------- ОБРАБОТЧИКИ КНОПОК -------------------------
document.getElementById('crashBtn')?.addEventListener('click', startCrashGame);
document.getElementById('slotBtn')?.addEventListener('click', playSlot);
document.getElementById('depositBtn')?.addEventListener('click', depositNow);

// Закрытие модалки
document.querySelector('.close')?.addEventListener('click', () => {
    document.getElementById('gameModal').style.display = 'none';
    if (crashInterval) clearInterval(crashInterval);
    crashActive = false;
});
</script>
</body>
</html>
