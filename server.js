const express = require('express');
const cors = require('cors');
const sqlite3 = require('sqlite3').verbose();
const axios = require('axios');
const crypto = require('crypto');
const app = express();

app.use(cors());
app.use(express.json());

const NOWPAYMENTS_API_KEY = '5D3VJ3Z-KCM4QPP-J9P19CQ-2HA8GBC';
const NOWPAYMENTS_API_URL = 'https://api.nowpayments.io/v1';

const db = new sqlite3.Database('blackvail.db');
db.serialize(() => {
  db.run(`CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id TEXT UNIQUE,
    telegram TEXT,
    username TEXT,
    password TEXT,
    balance INTEGER DEFAULT 0,
    total_won INTEGER DEFAULT 0,
    total_lost INTEGER DEFAULT 0
  )`);
  db.run(`CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id TEXT,
    payment_id TEXT UNIQUE,
    amount INTEGER,
    status TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )`);
});

function getPlayerByTelegram(telegram, cb) {
  db.get(`SELECT * FROM users WHERE telegram = ?`, [telegram], cb);
}
function getPlayerById(player_id, cb) {
  db.get(`SELECT * FROM users WHERE player_id = ?`, [player_id], cb);
}
function updateBalance(player_id, newBalance, cb) {
  db.run(`UPDATE users SET balance = ? WHERE player_id = ?`, [newBalance, player_id], cb);
}
function createPlayer(telegram, password, cb) {
  let username = telegram.replace('@', '');
  let player_id = crypto.randomBytes(3).toString('hex').toUpperCase();
  db.run(`INSERT INTO users (player_id, telegram, username, password, balance) VALUES (?, ?, ?, ?, 1000)`,
    [player_id, telegram, username, password], function(err) {
      if (err) return cb(err);
      cb(null, { player_id, username, balance: 1000 });
    });
}

app.post('/api/login', (req, res) => {
  const { telegram, password } = req.body;
  getPlayerByTelegram(telegram, (err, user) => {
    if (err) return res.json({ error: 'Ошибка БД' });
    if (!user) {
      createPlayer(telegram, password, (err2, newUser) => {
        if (err2) return res.json({ error: 'Ошибка создания' });
        res.json({ playerId: newUser.player_id, username: newUser.username, balance: newUser.balance });
      });
    } else {
      if (user.password !== password) return res.json({ error: 'Неверный пароль' });
      res.json({ playerId: user.player_id, username: user.username, balance: user.balance });
    }
  });
});

app.get('/api/balance/:player_id', (req, res) => {
  getPlayerById(req.params.player_id, (err, user) => {
    if (err || !user) return res.json({ balance: 0 });
    res.json({ balance: user.balance });
  });
});

app.post('/api/place_bet', (req, res) => {
  const { player_id, bet, game } = req.body;
  if (!player_id || !bet) return res.json({ error: 'Не хватает данных' });
  getPlayerById(player_id, (err, user) => {
    if (err || !user) return res.json({ error: 'Пользователь не найден' });
    if (user.balance < bet) return res.json({ error: 'Недостаточно средств' });
    const newBalance = user.balance - bet;
    updateBalance(player_id, newBalance, (err2) => {
      if (err2) return res.json({ error: 'Ошибка БД' });
      res.json({ success: true, new_balance: newBalance });
    });
  });
});

app.post('/api/cashout_crash', (req, res) => {
  const { player_id, win, multiplier } = req.body;
  if (!player_id || !win) return res.json({ error: 'Нет данных' });
  getPlayerById(player_id, (err, user) => {
    if (err || !user) return res.json({ error: 'Пользователь не найден' });
    const newBalance = user.balance + win;
    db.run(`UPDATE users SET balance = ?, total_won = total_won + ? WHERE player_id = ?`,
      [newBalance, win, player_id], (err2) => {
        if (err2) return res.json({ error: 'Ошибка БД' });
        res.json({ success: true, new_balance: newBalance });
      });
  });
});

app.post('/api/cashout_slot', (req, res) => {
  const { player_id, win } = req.body;
  if (!player_id || !win) return res.json({ error: 'Нет данных' });
  getPlayerById(player_id, (err, user) => {
    if (err || !user) return res.json({ error: 'Пользователь не найден' });
    const newBalance = user.balance + win;
    db.run(`UPDATE users SET balance = ?, total_won = total_won + ? WHERE player_id = ?`,
      [newBalance, win, player_id], (err2) => {
        if (err2) return res.json({ error: 'Ошибка БД' });
        res.json({ success: true, new_balance: newBalance });
      });
  });
});

app.post('/api/create-payment', (req, res) => {
  const { player_id } = req.body;
  if (!player_id) return res.json({ error: 'Нет ID игрока' });
  axios.post(`${NOWPAYMENTS_API_URL}/payment`, {
    price_amount: 500,
    price_currency: 'USD',
    order_id: player_id,
    ipn_callback_url: `https://${req.headers.host}/api/nowpayments-webhook`
  }, {
    headers: { 'x-api-key': NOWPAYMENTS_API_KEY }
  }).then(response => {
    const { payment_id, invoice_url } = response.data;
    db.run(`INSERT INTO payments (player_id, payment_id, amount, status) VALUES (?, ?, 500, 'pending')`,
      [player_id, payment_id]);
    res.json({ invoice_url });
  }).catch(e => {
    console.error(e);
    res.json({ error: 'Ошибка создания платежа' });
  });
});

app.post('/api/nowpayments-webhook', (req, res) => {
  const { payment_id, order_id, payment_status, actually_paid } = req.body;
  if (payment_status === 'finished') {
    db.get(`SELECT * FROM payments WHERE payment_id = ?`, [payment_id], (err, payment) => {
      if (err || !payment) return res.sendStatus(200);
      if (payment.status === 'pending') {
        const amount = actually_paid || 500;
        getPlayerById(order_id, (err2, user) => {
          if (user) {
            const newBalance = user.balance + amount;
            updateBalance(order_id, newBalance, () => {
              db.run(`UPDATE payments SET status = 'completed' WHERE payment_id = ?`, [payment_id]);
              res.sendStatus(200);
            });
          } else res.sendStatus(200);
        });
      } else res.sendStatus(200);
    });
  } else res.sendStatus(200);
});

app.get('/admin', (req, res) => {
  db.all(`SELECT player_id, username, telegram, balance, total_won, total_lost FROM users`, [], (err, rows) => {
    let html = `<html><head><meta charset="UTF-8"><title>Black VAIL Admin</title><style>body{background:#3d0000;color:#ffddcc;font-family:monospace;padding:20px;}table{border-collapse:collapse;width:100%;background:#2a0000;}th,td{border:1px solid #ff8844;padding:8px;}</style></head><body><h1>Admin BLACK VAIL</h1><form method="post" action="/admin/update"><input name="player_id" placeholder="Player ID"><input name="new_balance" placeholder="New Balance"><button>Изменить баланс</button></form><table><tr><th>Player ID</th><th>Username</th><th>Telegram</th><th>Balance</th><th>Won</th><th>Lost</th></tr>`;
    rows.forEach(r => {
      html += `<tr><td>${r.player_id}</td><td>${r.username}</td><td>${r.telegram}</td><td>${r.balance}</td><td>${r.total_won}</td><td>${r.total_lost}</td></tr>`;
    });
    html += `</table></body></html>`;
    res.send(html);
  });
});
app.post('/admin/update', express.urlencoded({ extended: true }), (req, res) => {
  const { player_id, new_balance } = req.body;
  if (player_id && new_balance) {
    db.run(`UPDATE users SET balance = ? WHERE player_id = ?`, [parseInt(new_balance), player_id]);
  }
  res.redirect('/admin');
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
