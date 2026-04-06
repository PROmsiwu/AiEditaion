<?php
session_start();

// ─── Database Setup (SQLite) ───────────────────────────────────────────────────
$db = new PDO('sqlite:nexus_users.db');
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ─── API Handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

    // JSON body support
    $jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'register') {
        $name     = trim($_POST['name'] ?? $jsonBody['name'] ?? '');
        $phone    = trim($_POST['phone'] ?? $jsonBody['phone'] ?? '');
        $password = $_POST['password'] ?? $jsonBody['password'] ?? '';

        if (!$name || !$phone || !$password) {
            echo json_encode(['success' => false, 'error' => 'جميع الحقول مطلوبة']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'الرقم مسجّل مسبقاً']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, phone, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $phone, $hash]);
        $userId = $db->lastInsertId();

        $_SESSION['user_id']   = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_phone']= $phone;

        echo json_encode(['success' => true, 'name' => $name]);
        exit;
    }

    if ($action === 'login') {
        $phone    = trim($_POST['phone'] ?? $jsonBody['phone'] ?? '');
        $password = $_POST['password'] ?? $jsonBody['password'] ?? '';

        $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'رقم أو باسورد خاطئ']);
            exit;
        }

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_phone'] = $user['phone'];

        echo json_encode(['success' => true, 'name' => $user['name']]);
        exit;
    }

    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'chat') {
        if (empty($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'error' => 'غير مسجّل']);
            exit;
        }

        $userMessage = $jsonBody['message'] ?? '';
        $history     = $jsonBody['history'] ?? [];
        $fileData    = $jsonBody['file'] ?? null; // base64 file

        if (!$userMessage && !$fileData) {
            echo json_encode(['success' => false, 'error' => 'الرسالة فارغة']);
            exit;
        }

        // Build Gemini request
        $apiKey = 'AIzaSyCjqXmNKpawCSmtdJtpZvayhdrP9ZpKzvs';
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=$apiKey";

        $systemPrompt = "أنت NEXUS AI، مساعد ذكاء اصطناعي متقدم ومتخصص في:
- كتابة كود برمجي احترافي بجميع اللغات (Python, JS, C++, Rust, Go, Assembly, PHP, وغيرها)
- كتابة ملفات ضخمة تصل إلى 150,000 سطر بدقة عالية وبنية صحيحة
- الهندسة العكسية (Reverse Engineering): تحليل البرامج، PE headers، disassembly، decompilation
- الأمن السيبراني (Cybersecurity): penetration testing، network analysis، vulnerability assessment، CTF challenges
- تحليل الملفات والصور وأي نوع من البيانات
- شرح المفاهيم التقنية المعقدة بوضوح

قواعد عملك:
1. اكتب كوداً صحيحاً بالكامل، لا تقطعه أو تختصره أبداً
2. للمشاريع الكبيرة، قسّم الكود إلى ملفات منظمة مع شرح كل ملف
3. اشرح خطوات الهندسة العكسية بتفصيل كامل
4. في الأمن السيبراني، قدم معلومات تعليمية وأخلاقية للأغراض الدفاعية والبحثية
5. حلل أي ملف أو صورة يتم رفعها وأعطِ تقريراً شاملاً
6. استخدم العربية والإنجليزية حسب سياق السؤال
7. كن دقيقاً ومفصلاً في كل إجابة";

        // Build contents
        $contents = [];
        foreach ($history as $msg) {
            $role  = $msg['role'] === 'user' ? 'user' : 'model';
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }

        // Current message parts
        $currentParts = [];
        if ($fileData) {
            $currentParts[] = [
                'inline_data' => [
                    'mime_type' => $fileData['mime_type'],
                    'data'      => $fileData['data']
                ]
            ];
        }
        $currentParts[] = ['text' => $userMessage ?: 'حلل هذا الملف'];
        $contents[] = ['role' => 'user', 'parts' => $currentParts];

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => $contents,
            'generationConfig'   => ['maxOutputTokens' => 8192, 'temperature' => 0.7]
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => "خطأ في الاتصال بـ Gemini (HTTP $httpCode)"]);
            exit;
        }

        $data  = json_decode($response, true);
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'لم يتم الحصول على رد';

        echo json_encode(['success' => true, 'reply' => $reply]);
        exit;
    }
}

// ─── Session Check ─────────────────────────────────────────────────────────────
$loggedIn = !empty($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NEXUS AI</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #080810;
  --surface:  #0f0f1e;
  --card:     #12121e;
  --border:   #2a2a4a;
  --accent:   #5555ff;
  --accent2:  #8080ff;
  --text:     #e0e0ff;
  --muted:    #6666aa;
  --danger:   #ff4444;
  --success:  #22c55e;
}

body {
  font-family: 'Cairo', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  background-image:
    radial-gradient(ellipse at 20% 50%, #1a0a3a18 0%, transparent 60%),
    radial-gradient(ellipse at 80% 20%, #0a1a3a18 0%, transparent 60%);
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #0d0d1a; }
::-webkit-scrollbar-thumb { background: #2a2a4a; border-radius: 3px; }

/* ── Auth ── */
.auth-wrap {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}

.auth-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 40px;
  width: 100%; max-width: 420px;
  box-shadow: 0 0 60px #4040c022;
  animation: fadeUp .4s ease;
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

.logo-box {
  text-align: center;
  margin-bottom: 32px;
}

.logo-icon {
  width: 64px; height: 64px;
  background: linear-gradient(135deg, #4040c0, #8080ff);
  border-radius: 16px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 28px;
  box-shadow: 0 0 30px #6060ff44;
  margin-bottom: 12px;
}

.logo-title {
  font-size: 26px; font-weight: 900;
  letter-spacing: 4px; color: #fff;
}

.logo-sub {
  color: var(--muted); font-size: 13px; margin-top: 4px;
}

.tab-row {
  display: flex; gap: 8px; margin-bottom: 24px;
}

.tab-btn {
  flex: 1; padding: 10px;
  border-radius: 8px; border: none;
  font-family: 'Cairo', sans-serif;
  font-size: 14px; cursor: pointer;
  transition: all .2s;
}

.tab-btn.active {
  background: linear-gradient(135deg, #4040c0, #6060ff);
  color: #fff;
}

.tab-btn:not(.active) {
  background: #1e1e30; color: #888;
}

.field-group { display: flex; flex-direction: column; gap: 12px; }

.inp {
  padding: 12px 16px;
  background: #0d0d1a;
  border: 1px solid var(--border);
  border-radius: 8px;
  color: #fff; font-size: 14px;
  font-family: 'Cairo', sans-serif;
  outline: none; width: 100%;
  transition: border .2s;
}
.inp:focus { border-color: var(--accent); }
.inp::placeholder { color: #444; }

.tos-row {
  display: flex; align-items: center; gap: 8px;
  margin-top: 14px; font-size: 13px; color: #aaa;
  cursor: pointer;
}

.tos-link { color: #7070ff; text-decoration: underline; cursor: pointer; }

.error-box {
  margin-top: 12px;
  padding: 10px 14px;
  background: #ff444422;
  border: 1px solid var(--danger);
  border-radius: 8px;
  color: #ff8888; font-size: 13px;
}

.submit-btn {
  margin-top: 20px; width: 100%; padding: 14px;
  background: linear-gradient(135deg, #4040c0, #6060ff);
  border: none; border-radius: 10px;
  color: #fff; font-size: 15px; font-weight: 700;
  font-family: 'Cairo', sans-serif;
  cursor: pointer; transition: all .2s;
  box-shadow: 0 4px 20px #6060ff33;
}
.submit-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 25px #6060ff44; }
.submit-btn:disabled { background: #2a2a4a; box-shadow: none; cursor: not-allowed; transform: none; }

/* ── Modal ── */
.modal-overlay {
  position: fixed; inset: 0;
  background: #000000cc;
  display: flex; align-items: center; justify-content: center;
  z-index: 100; padding: 20px;
}

.modal-card {
  background: #12121e;
  border: 1px solid #3a3a6a;
  border-radius: 16px;
  padding: 32px;
  max-width: 560px; width: 100%;
  max-height: 80vh; overflow-y: auto;
  animation: fadeUp .3s ease;
}

.modal-card h2 { color: #a0a0ff; margin-bottom: 20px; font-size: 20px; }
.modal-card p  { color: #ccc; line-height: 1.9; font-size: 14px; margin-bottom: 10px; }
.modal-card ol { padding-right: 20px; color: #ccc; line-height: 1.9; font-size: 14px; }

/* ── Chat Layout ── */
.chat-layout {
  display: flex; flex-direction: column; height: 100vh;
}

.chat-header {
  height: 60px;
  background: var(--surface);
  border-bottom: 1px solid #1a1a2e;
  display: flex; align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  position: sticky; top: 0; z-index: 10;
}

.header-logo {
  display: flex; align-items: center; gap: 10px;
}

.header-icon {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, #4040c0, #8080ff);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}

.header-title {
  font-weight: 900; font-size: 16px;
  letter-spacing: 2px; color: #fff;
}

.badge {
  background: #22c55e22;
  border: 1px solid var(--success);
  color: var(--success);
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 10px;
}

.header-right {
  display: flex; align-items: center; gap: 12px;
}

.user-label { color: #888; font-size: 13px; }

.logout-btn {
  background: #1e1e30;
  border: 1px solid #3a3a5a;
  color: #aaa;
  padding: 6px 14px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 12px;
  font-family: 'Cairo', sans-serif;
  transition: all .2s;
}
.logout-btn:hover { border-color: var(--danger); color: var(--danger); }

/* ── Messages ── */
.messages {
  flex: 1; overflow-y: auto;
  padding: 20px;
  max-width: 900px; margin: 0 auto; width: 100%;
}

.msg-wrap {
  display: flex;
  margin-bottom: 16px;
}

.msg-wrap.user  { justify-content: flex-start; }
.msg-wrap.assistant { justify-content: flex-end; }

.bubble {
  max-width: 80%;
  border-radius: 16px;
  padding: 14px 18px;
  font-size: 14px;
  line-height: 1.8;
}

.bubble.user {
  background: linear-gradient(135deg, #1e1e40, #2a2a55);
  border: 1px solid #3a3a7a;
  border-radius: 16px 4px 16px 16px;
}

.bubble.assistant {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 4px 16px 16px 16px;
  box-shadow: 0 2px 20px #4040c011;
}

.bubble-label {
  color: #6060ff;
  font-size: 11px;
  margin-bottom: 6px;
  font-weight: 700;
}

/* ── Code Block ── */
.code-block {
  margin: 12px 0;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #2a2a3a;
  background: #0d0d15;
}

.code-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 14px;
  background: #1a1a2e;
  border-bottom: 1px solid #2a2a3a;
}

.code-lang {
  color: #7c7cff;
  font-size: 12px;
  font-family: 'JetBrains Mono', monospace;
  font-weight: 600;
}

.copy-btn {
  background: #ffffff11;
  border: 1px solid #444;
  color: #aaa;
  padding: 3px 10px;
  border-radius: 4px;
  font-size: 11px;
  cursor: pointer;
  transition: all .2s;
  font-family: 'Cairo', sans-serif;
}
.copy-btn:hover { background: #ffffff22; color: #fff; }
.copy-btn.copied { background: #22c55e22; border-color: #22c55e; color: #22c55e; }

pre {
  margin: 0; padding: 16px;
  overflow-x: auto;
  font-size: 13px; line-height: 1.6;
  color: #e0e0ff;
  font-family: 'JetBrains Mono', 'Courier New', monospace;
  white-space: pre-wrap; word-break: break-all;
  max-height: 500px; overflow-y: auto;
}

/* ── Typing indicator ── */
.typing {
  display: flex; gap: 6px; align-items: center;
  padding: 14px 18px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 4px 16px 16px 16px;
  width: fit-content;
}

.dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #6060ff;
  animation: pulse 1.2s ease-in-out infinite;
}
.dot:nth-child(2) { animation-delay: .2s; }
.dot:nth-child(3) { animation-delay: .4s; }

@keyframes pulse {
  0%, 100% { opacity: .3; transform: scale(.8); }
  50%       { opacity: 1;  transform: scale(1);  }
}

/* ── File chips ── */
.file-chips {
  max-width: 900px; margin: 0 auto;
  padding: 0 20px 8px;
  display: flex; flex-wrap: wrap; gap: 8px;
}

.chip {
  background: #1a1a30;
  border: 1px solid #3a3a6a;
  border-radius: 8px;
  padding: 6px 12px;
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; color: #aaa;
}

.chip-remove {
  background: none; border: none;
  color: var(--danger); cursor: pointer;
  font-size: 14px; padding: 0;
}

/* ── Input area ── */
.input-area {
  padding: 12px 20px 20px;
  background: var(--surface);
  border-top: 1px solid #1a1a2e;
}

.input-row {
  max-width: 900px; margin: 0 auto;
  display: flex; gap: 10px; align-items: flex-end;
}

.attach-btn {
  width: 44px; height: 44px; flex-shrink: 0;
  background: #1e1e30;
  border: 1px solid #3a3a5a;
  border-radius: 10px;
  color: #aaa; cursor: pointer;
  font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  transition: all .2s;
}
.attach-btn:hover { border-color: var(--accent); color: var(--accent); }

#msgInput {
  flex: 1; padding: 12px 16px;
  background: #0d0d1a;
  border: 1px solid var(--border);
  border-radius: 10px;
  color: #fff; font-size: 14px;
  font-family: 'Cairo', sans-serif;
  resize: none; outline: none;
  min-height: 44px; max-height: 200px;
  overflow-y: auto; line-height: 1.5;
  transition: border .2s;
}
#msgInput:focus { border-color: var(--accent); }
#msgInput::placeholder { color: #444; }

.send-btn {
  width: 44px; height: 44px; flex-shrink: 0;
  background: linear-gradient(135deg, #4040c0, #6060ff);
  border: none; border-radius: 10px;
  color: #fff; cursor: pointer; font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 15px #6060ff44;
  transition: all .2s;
}
.send-btn:hover { transform: scale(1.05); }
.send-btn:disabled { background: #2a2a4a; box-shadow: none; cursor: not-allowed; transform: none; }

.footer-note {
  text-align: center; color: #2a2a4a;
  font-size: 11px; margin-top: 8px;
}

/* ── Error bar ── */
.error-bar {
  max-width: 900px; margin: 0 auto 8px;
  padding: 0 20px; color: #ff8888; font-size: 13px;
}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ─────────────────── AUTH SCREEN ─────────────────── -->
<div class="auth-wrap">

  <!-- TOS Modal -->
  <div class="modal-overlay" id="tosModal" style="display:none">
    <div class="modal-card">
      <h2>⚖️ اتفاقية الاستخدام</h2>
      <p><strong style="color:#ff6b6b">تحذير مهم:</strong> هذا النظام مخصص للأغراض التعليمية والبحثية فقط.</p>
      <p>بالتسجيل في NEXUS AI، أنت توافق على ما يلي:</p>
      <ol>
        <li>لن تستخدم هذا النظام في أي نشاط غير قانوني.</li>
        <li>المعلومات الأمنية المقدمة للأغراض الدفاعية والتعليمية فقط.</li>
        <li>أنت مسؤول قانونياً عن أي استخدام غير مشروع.</li>
        <li>أي محتوى يتعلق بإيذاء الآخرين ممنوع منعاً باتاً.</li>
        <li>يحق للنظام تعليق حسابك عند المخالفة.</li>
        <li>لن تستخدم النظام للحصول على معلومات لإيذاء أشخاص أو مؤسسات.</li>
        <li>أنت تقر بأن عمرك 18 سنة أو أكثر.</li>
      </ol>
      <p style="color:#ffa500;margin-top:10px">استخدام النظام يعني موافقتك التامة على هذه الشروط.</p>
      <button class="submit-btn" onclick="agreeToS()" style="margin-top:20px">أوافق على الاتفاقية ✓</button>
      <button onclick="document.getElementById('tosModal').style.display='none'"
        style="margin-top:8px;width:100%;padding:10px;background:transparent;border:1px solid #444;border-radius:8px;color:#aaa;font-size:13px;cursor:pointer;font-family:inherit">
        إغلاق
      </button>
    </div>
  </div>

  <div class="auth-card">
    <div class="logo-box">
      <div class="logo-icon">⬡</div>
      <div class="logo-title">NEXUS AI</div>
      <div class="logo-sub">نظام الذكاء الاصطناعي المتقدم</div>
    </div>

    <div class="tab-row">
      <button class="tab-btn active" id="tabLogin"    onclick="switchTab('login')">تسجيل الدخول</button>
      <button class="tab-btn"        id="tabRegister" onclick="switchTab('register')">إنشاء حساب</button>
    </div>

    <div class="field-group">
      <input class="inp" id="nameField" type="text"     placeholder="الاسم الكامل"  style="display:none">
      <input class="inp" id="phoneField" type="tel"     placeholder="رقم الهاتف">
      <input class="inp" id="passField"  type="password" placeholder="كلمة المرور"
             onkeydown="if(event.key==='Enter') submitAuth()">
    </div>

    <div class="tos-row" id="tosRow" style="display:none">
      <input type="checkbox" id="tosCheck" onchange="handleTosCheck(this)">
      <label for="tosCheck">أوافق على
        <span class="tos-link" onclick="document.getElementById('tosModal').style.display='flex'">اتفاقية الاستخدام</span>
      </label>
    </div>

    <div class="error-box" id="authError" style="display:none"></div>

    <button class="submit-btn" id="authBtn" onclick="submitAuth()">دخول →</button>
  </div>
</div>

<?php else: ?>
<!-- ─────────────────── CHAT SCREEN ─────────────────── -->
<div class="chat-layout">

  <div class="chat-header">
    <div class="header-logo">
      <div class="header-icon">⬡</div>
      <span class="header-title">NEXUS AI</span>
      <span class="badge">ONLINE</span>
    </div>
    <div class="header-right">
      <span class="user-label">👤 <?= htmlspecialchars($userName) ?></span>
      <button class="logout-btn" onclick="logout()">خروج</button>
    </div>
  </div>

  <div class="messages" id="messages">
    <div class="msg-wrap assistant">
      <div class="bubble assistant">
        <div class="bubble-label">⬡ NEXUS AI</div>
        مرحباً <strong><?= htmlspecialchars($userName) ?></strong>! أنا NEXUS AI 🤖<br><br>
        أنا متخصص في:<br>
        💻 كتابة كود برمجي ضخم يصل إلى 150,000 سطر<br>
        🔍 الهندسة العكسية وتحليل البرامج<br>
        🛡️ الأمن السيبراني والاختبار الأخلاقي<br>
        📁 تحليل أي نوع من الملفات والصور<br><br>
        ارفع ملفاتك أو اسألني عن أي شيء تقني!
      </div>
    </div>
  </div>

  <div class="file-chips" id="fileChips"></div>
  <div class="error-bar"  id="errorBar"></div>

  <div class="input-area">
    <div class="input-row">
      <button class="attach-btn" onclick="document.getElementById('fileInput').click()" title="رفع ملف">📎</button>
      <input type="file" id="fileInput" multiple style="display:none" accept="*/*" onchange="handleFiles(this)">

      <textarea id="msgInput" rows="1"
        placeholder="اسأل NEXUS AI... (Shift+Enter لسطر جديد)"
        onkeydown="handleKey(event)"
        oninput="autoResize(this)"></textarea>

      <button class="send-btn" id="sendBtn" onclick="sendMessage()" title="إرسال">⬆</button>
    </div>
    <p class="footer-note">NEXUS AI · Powered by Gemini · للأغراض التعليمية والبحثية</p>
  </div>
</div>
<?php endif; ?>

<script>
// ─── Auth ─────────────────────────────────────────────────────────────────────
let currentMode = 'login';
let tosAgreed   = false;

function switchTab(mode) {
  currentMode = mode;
  document.getElementById('tabLogin').classList.toggle('active', mode === 'login');
  document.getElementById('tabRegister').classList.toggle('active', mode === 'register');
  document.getElementById('nameField').style.display = mode === 'register' ? 'block' : 'none';
  document.getElementById('tosRow').style.display    = mode === 'register' ? 'flex' : 'none';
  document.getElementById('authBtn').textContent = mode === 'login' ? 'دخول →' : 'إنشاء الحساب →';
  hideError();
}

function handleTosCheck(cb) {
  if (cb.checked) {
    document.getElementById('tosModal').style.display = 'flex';
    cb.checked = false;
  } else {
    tosAgreed = false;
  }
}

function agreeToS() {
  tosAgreed = true;
  document.getElementById('tosCheck').checked = true;
  document.getElementById('tosModal').style.display = 'none';
}

function showError(msg) {
  const el = document.getElementById('authError');
  el.textContent = msg;
  el.style.display = 'block';
}
function hideError() {
  document.getElementById('authError').style.display = 'none';
}

async function submitAuth() {
  hideError();
  const phone = document.getElementById('phoneField').value.trim();
  const pass  = document.getElementById('passField').value;

  if (!phone || !pass) { showError('أدخل الرقم والباسورد'); return; }

  if (currentMode === 'register') {
    const name = document.getElementById('nameField').value.trim();
    if (!name) { showError('أدخل الاسم'); return; }
    if (!tosAgreed) { showError('يجب الموافقة على الاتفاقية'); return; }

    const btn = document.getElementById('authBtn');
    btn.disabled = true; btn.textContent = 'جاري الإنشاء...';

    const fd = new FormData();
    fd.append('action', 'register');
    fd.append('name', name);
    fd.append('phone', phone);
    fd.append('password', pass);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) location.reload();
    else { showError(data.error); btn.disabled = false; btn.textContent = 'إنشاء الحساب →'; }

  } else {
    const btn = document.getElementById('authBtn');
    btn.disabled = true; btn.textContent = 'جاري التحقق...';

    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('phone', phone);
    fd.append('password', pass);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) location.reload();
    else { showError(data.error); btn.disabled = false; btn.textContent = 'دخول →'; }
  }
}

// ─── Logout ───────────────────────────────────────────────────────────────────
async function logout() {
  const fd = new FormData();
  fd.append('action', 'logout');
  await fetch('', { method: 'POST', body: fd });
  location.reload();
}

// ─── Chat ─────────────────────────────────────────────────────────────────────
let chatHistory = [];
let attachedFiles = [];
let isLoading = false;

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 200) + 'px';
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

// File handling
function handleFiles(input) {
  const MAX = 25 * 1024 * 1024;
  for (const f of input.files) {
    if (f.size > MAX) { showErrorBar(`الملف "${f.name}" يتجاوز 25 ميجا`); continue; }
    attachedFiles.push(f);
  }
  input.value = '';
  renderChips();
}

function renderChips() {
  const c = document.getElementById('fileChips');
  c.innerHTML = '';
  attachedFiles.forEach((f, i) => {
    const icon = f.type.startsWith('image/') ? '🖼️' : f.type === 'application/pdf' ? '📄' : '📁';
    const size = f.size < 1048576 ? (f.size/1024).toFixed(1)+' KB' : (f.size/1048576).toFixed(1)+' MB';
    c.innerHTML += `<div class="chip">${icon} ${f.name} (${size})
      <button class="chip-remove" onclick="removeFile(${i})">✕</button></div>`;
  });
}

function removeFile(i) {
  attachedFiles.splice(i, 1);
  renderChips();
}

function showErrorBar(msg) {
  const el = document.getElementById('errorBar');
  el.textContent = '⚠️ ' + msg;
  setTimeout(() => el.textContent = '', 4000);
}

// Render message with code blocks
function renderContent(text) {
  const codeRx = /```(\w*)\n?([\s\S]*?)```/g;
  let result = ''; let last = 0; let m;
  while ((m = codeRx.exec(text)) !== null) {
    if (m.index > last) result += escHtml(text.slice(last, m.index)).replace(/\n/g, '<br>');
    const lang  = m[1] || 'code';
    const code  = m[2];
    const lines = code.split('\n').length;
    const id    = 'cb_' + Math.random().toString(36).slice(2);
    result += `<div class="code-block">
      <div class="code-header">
        <span class="code-lang">${lang} · ${lines} سطر</span>
        <button class="copy-btn" id="${id}" onclick="copyCode(this, ${JSON.stringify(code)})">نسخ</button>
      </div>
      <pre><code>${escHtml(code)}</code></pre>
    </div>`;
    last = m.index + m[0].length;
  }
  if (last < text.length) result += escHtml(text.slice(last)).replace(/\n/g, '<br>');
  return result;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyCode(btn, code) {
  navigator.clipboard.writeText(code).then(() => {
    btn.textContent = '✓ تم النسخ';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'نسخ'; btn.classList.remove('copied'); }, 2000);
  });
}

function appendMsg(role, html) {
  const wrap = document.createElement('div');
  wrap.className = `msg-wrap ${role}`;
  const bubble = document.createElement('div');
  bubble.className = `bubble ${role}`;
  if (role === 'assistant') bubble.innerHTML = '<div class="bubble-label">⬡ NEXUS AI</div>' + html;
  else bubble.innerHTML = html;
  wrap.appendChild(bubble);
  document.getElementById('messages').appendChild(wrap);
  wrap.scrollIntoView({ behavior: 'smooth' });
  return bubble;
}

function showTyping() {
  const wrap = document.createElement('div');
  wrap.className = 'msg-wrap assistant';
  wrap.id = 'typingWrap';
  wrap.innerHTML = '<div class="typing"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>';
  document.getElementById('messages').appendChild(wrap);
  wrap.scrollIntoView({ behavior: 'smooth' });
}

function hideTyping() {
  const el = document.getElementById('typingWrap');
  if (el) el.remove();
}

async function fileToBase64(file) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = () => res(r.result.split(',')[1]);
    r.onerror = () => rej(new Error('fail'));
    r.readAsDataURL(file);
  });
}

async function sendMessage() {
  if (isLoading) return;
  const input = document.getElementById('msgInput');
  const text  = input.value.trim();
  if (!text && attachedFiles.length === 0) return;

  isLoading = true;
  document.getElementById('sendBtn').disabled = true;
  input.value = ''; input.style.height = 'auto';

  // Build display message
  let displayParts = [];
  if (attachedFiles.length > 0) {
    displayParts.push(attachedFiles.map(f => {
      const icon = f.type.startsWith('image/') ? '🖼️' : f.type === 'application/pdf' ? '📄' : '📁';
      return `${icon} ${f.name}`;
    }).join('<br>'));
  }
  if (text) displayParts.push(escHtml(text));
  appendMsg('user', displayParts.join('<br><br>'));

  // Prepare file for API (first file only for now)
  let filePart = null;
  if (attachedFiles.length > 0) {
    const f = attachedFiles[0];
    try {
      if (f.type.startsWith('image/') || f.type === 'application/pdf') {
        filePart = { mime_type: f.type, data: await fileToBase64(f) };
      } else {
        // Text file
        const txt = await f.text();
        const preview = txt.length > 40000 ? txt.slice(0, 40000) + '\n...[اقتطع]' : txt;
        // Embed as text in message
        const combined = `محتوى الملف "${f.name}":\n\`\`\`\n${preview}\n\`\`\`\n\n${text}`;
        attachedFiles = [];
        renderChips();

        showTyping();
        const payload = { action: 'chat', message: combined, history: chatHistory };
        const res = await fetch('', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        hideTyping();

        if (data.success) {
          chatHistory.push({ role: 'user', content: combined });
          chatHistory.push({ role: 'assistant', content: data.reply });
          if (chatHistory.length > 40) chatHistory = chatHistory.slice(-40);
          appendMsg('assistant', renderContent(data.reply));
        } else {
          appendMsg('assistant', '⚠️ ' + escHtml(data.error || 'خطأ'));
        }
        isLoading = false;
        document.getElementById('sendBtn').disabled = false;
        return;
      }
    } catch(e) { showErrorBar('خطأ في قراءة الملف'); }
  }

  attachedFiles = [];
  renderChips();
  showTyping();

  const payload = { action: 'chat', message: text, history: chatHistory };
  if (filePart) payload.file = filePart;

  try {
    const res  = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    hideTyping();

    if (data.success) {
      chatHistory.push({ role: 'user', content: text });
      chatHistory.push({ role: 'assistant', content: data.reply });
      if (chatHistory.length > 40) chatHistory = chatHistory.slice(-40);
      appendMsg('assistant', renderContent(data.reply));
    } else {
      appendMsg('assistant', '⚠️ ' + escHtml(data.error || 'خطأ في الاتصال'));
    }
  } catch(e) {
    hideTyping();
    appendMsg('assistant', '⚠️ خطأ في الاتصال بالخادم');
  }

  isLoading = false;
  document.getElementById('sendBtn').disabled = false;
}
</script>
</body>
</html>
