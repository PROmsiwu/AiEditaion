import { useState, useRef, useEffect, useCallback } from "react";

// ─── Fake Auth Store ───────────────────────────────────────────────────────────
const USERS_KEY = "nexus_users";
const SESSION_KEY = "nexus_session";

function getUsers() {
  try { return JSON.parse(localStorage.getItem(USERS_KEY) || "{}"); } catch { return {}; }
}
function saveUsers(u) { localStorage.setItem(USERS_KEY, JSON.stringify(u)); }
function getSession() {
  try { return JSON.parse(localStorage.getItem(SESSION_KEY) || "null"); } catch { return null; }
}
function saveSession(s) { localStorage.setItem(SESSION_KEY, JSON.stringify(s)); }
function clearSession() { localStorage.removeItem(SESSION_KEY); }

// ─── File Helpers ──────────────────────────────────────────────────────────────
function readFileAsBase64(file) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = () => res(r.result.split(",")[1]);
    r.onerror = () => rej(new Error("Read failed"));
    r.readAsDataURL(file);
  });
}
function readFileAsText(file) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = () => res(r.result);
    r.onerror = () => rej(new Error("Read failed"));
    r.readAsText(file);
  });
}
function isImageFile(f) { return f.type.startsWith("image/"); }
function isPdfFile(f) { return f.type === "application/pdf"; }

function formatBytes(b) {
  if (b < 1024) return b + " B";
  if (b < 1048576) return (b / 1024).toFixed(1) + " KB";
  return (b / 1048576).toFixed(1) + " MB";
}

const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 MB

// ─── Gemini API ────────────────────────────────────────────────────────────────
const GEMINI_API_KEY = "AIzaSyCjqXmNKpawCSmtdJtpZvayhdrP9ZpKzvs";
const GEMINI_URL = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${GEMINI_API_KEY}`;

async function callGemini(messages, fileParts = []) {
  const systemPrompt = `أنت NEXUS AI، مساعد ذكاء اصطناعي متقدم ومتخصص في:
- كتابة كود برمجي احترافي بجميع اللغات (Python, JS, C++, Rust, Go, Assembly, إلخ)
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
7. كن دقيقاً ومفصلاً في كل إجابة`;

  const contents = [];

  // Build conversation history
  for (const msg of messages) {
    if (msg.role === "user") {
      const parts = [];
      if (msg.fileParts && msg.fileParts.length > 0) {
        parts.push(...msg.fileParts);
      }
      parts.push({ text: msg.content });
      contents.push({ role: "user", parts });
    } else {
      contents.push({ role: "model", parts: [{ text: msg.content }] });
    }
  }

  // Add current file parts to last user message if any
  if (fileParts.length > 0 && contents.length > 0) {
    const last = contents[contents.length - 1];
    if (last.role === "user") {
      last.parts = [...fileParts, ...last.parts];
    }
  }

  const body = {
    system_instruction: { parts: [{ text: systemPrompt }] },
    contents,
    generationConfig: {
      maxOutputTokens: 8192,
      temperature: 0.7,
    }
  };

  const resp = await fetch(GEMINI_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body)
  });

  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error(err?.error?.message || `HTTP ${resp.status}`);
  }

  const data = await resp.json();
  return data.candidates?.[0]?.content?.parts?.[0]?.text || "لم يتم الحصول على رد.";
}

// ─── Code Block Renderer ───────────────────────────────────────────────────────
function renderMessage(text) {
  const parts = [];
  const codeBlockRegex = /```(\w*)\n?([\s\S]*?)```/g;
  let lastIndex = 0;
  let match;

  while ((match = codeBlockRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      parts.push({ type: "text", content: text.slice(lastIndex, match.index) });
    }
    parts.push({ type: "code", lang: match[1] || "text", content: match[2] });
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < text.length) {
    parts.push({ type: "text", content: text.slice(lastIndex) });
  }
  return parts;
}

function CodeBlock({ lang, content }) {
  const [copied, setCopied] = useState(false);
  const lines = content.split("\n").length;

  const copy = () => {
    navigator.clipboard.writeText(content).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  return (
    <div style={{
      margin: "12px 0",
      borderRadius: "8px",
      overflow: "hidden",
      border: "1px solid #2a2a3a",
      background: "#0d0d15"
    }}>
      <div style={{
        display: "flex", justifyContent: "space-between", alignItems: "center",
        padding: "6px 14px", background: "#1a1a2e",
        borderBottom: "1px solid #2a2a3a"
      }}>
        <span style={{ color: "#7c7cff", fontSize: "12px", fontFamily: "monospace", fontWeight: 600 }}>
          {lang || "code"} · {lines} سطر
        </span>
        <button onClick={copy} style={{
          background: copied ? "#22c55e22" : "#ffffff11",
          border: "1px solid " + (copied ? "#22c55e" : "#444"),
          color: copied ? "#22c55e" : "#aaa",
          padding: "3px 10px", borderRadius: "4px", fontSize: "11px",
          cursor: "pointer", transition: "all 0.2s"
        }}>
          {copied ? "✓ تم النسخ" : "نسخ"}
        </button>
      </div>
      <pre style={{
        margin: 0, padding: "16px", overflowX: "auto",
        fontSize: "13px", lineHeight: "1.6", color: "#e0e0ff",
        fontFamily: "'JetBrains Mono', 'Fira Code', 'Courier New', monospace",
        whiteSpace: "pre-wrap", wordBreak: "break-all",
        maxHeight: "500px", overflowY: "auto"
      }}>
        <code>{content}</code>
      </pre>
    </div>
  );
}

function MessageContent({ text }) {
  const parts = renderMessage(text);
  return (
    <div>
      {parts.map((p, i) =>
        p.type === "code"
          ? <CodeBlock key={i} lang={p.lang} content={p.content} />
          : <span key={i} style={{ whiteSpace: "pre-wrap" }}>{p.content}</span>
      )}
    </div>
  );
}

// ─── Auth Screen ───────────────────────────────────────────────────────────────
function AuthScreen({ onAuth }) {
  const [mode, setMode] = useState("login"); // login | register
  const [form, setForm] = useState({ name: "", phone: "", password: "" });
  const [agreed, setAgreed] = useState(false);
  const [showTos, setShowTos] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handle = (e) => setForm(f => ({ ...f, [e.target.name]: e.target.value }));

  const submit = () => {
    setError("");
    if (!form.phone || !form.password) { setError("أدخل الرقم والباسورد"); return; }
    if (mode === "register" && !form.name) { setError("أدخل الاسم"); return; }
    if (mode === "register" && !agreed) { setError("يجب الموافقة على الاتفاقية"); return; }

    setLoading(true);
    const users = getUsers();

    if (mode === "register") {
      if (users[form.phone]) { setError("الرقم مسجّل مسبقاً"); setLoading(false); return; }
      users[form.phone] = { name: form.name, phone: form.phone, password: form.password };
      saveUsers(users);
      const session = { phone: form.phone, name: form.name };
      saveSession(session);
      setTimeout(() => { setLoading(false); onAuth(session); }, 600);
    } else {
      const user = users[form.phone];
      if (!user || user.password !== form.password) {
        setError("رقم أو باسورد خاطئ"); setLoading(false); return;
      }
      const session = { phone: form.phone, name: user.name };
      saveSession(session);
      setTimeout(() => { setLoading(false); onAuth(session); }, 600);
    }
  };

  return (
    <div style={{
      minHeight: "100vh", background: "#080810",
      display: "flex", alignItems: "center", justifyContent: "center",
      fontFamily: "'Cairo', 'Segoe UI', sans-serif",
      backgroundImage: "radial-gradient(ellipse at 20% 50%, #1a0a3a22 0%, transparent 60%), radial-gradient(ellipse at 80% 20%, #0a1a3a22 0%, transparent 60%)"
    }}>
      {/* TOS Modal */}
      {showTos && (
        <div style={{
          position: "fixed", inset: 0, background: "#000000cc",
          display: "flex", alignItems: "center", justifyContent: "center",
          zIndex: 100, padding: "20px"
        }}>
          <div style={{
            background: "#12121e", border: "1px solid #3a3a6a",
            borderRadius: "16px", padding: "32px", maxWidth: "560px",
            width: "100%", maxHeight: "80vh", overflowY: "auto",
            direction: "rtl"
          }}>
            <h2 style={{ color: "#a0a0ff", margin: "0 0 20px", fontSize: "20px" }}>⚖️ اتفاقية الاستخدام</h2>
            <div style={{ color: "#ccc", lineHeight: "1.9", fontSize: "14px" }}>
              <p><strong style={{ color: "#ff6b6b" }}>تحذير مهم:</strong> هذا النظام مخصص للأغراض التعليمية والبحثية فقط.</p>
              <p>بالتسجيل في NEXUS AI، أنت توافق على ما يلي:</p>
              <ol style={{ paddingRight: "20px" }}>
                <li>لن تستخدم هذا النظام في أي نشاط غير قانوني.</li>
                <li>المعلومات الأمنية المقدمة للأغراض الدفاعية والتعليمية فقط.</li>
                <li>أنت مسؤول قانونياً عن أي استخدام غير مشروع.</li>
                <li>أي محتوى يتعلق بإيذاء الآخرين ممنوع منعاً باتاً.</li>
                <li>يحق للنظام تعليق حسابك عند المخالفة.</li>
                <li>لن تستخدم النظام للحصول على معلومات لإيذاء أشخاص أو مؤسسات.</li>
                <li>أنت تقر بأن عمرك 18 سنة أو أكثر.</li>
              </ol>
              <p style={{ color: "#ffa500" }}>استخدام النظام يعني موافقتك التامة على هذه الشروط.</p>
            </div>
            <button onClick={() => { setAgreed(true); setShowTos(false); }} style={{
              marginTop: "20px", width: "100%", padding: "12px",
              background: "linear-gradient(135deg, #4040c0, #6060ff)",
              border: "none", borderRadius: "8px", color: "#fff",
              fontSize: "15px", cursor: "pointer", fontFamily: "inherit"
            }}>أوافق على الاتفاقية ✓</button>
            <button onClick={() => setShowTos(false)} style={{
              marginTop: "8px", width: "100%", padding: "10px",
              background: "transparent", border: "1px solid #444",
              borderRadius: "8px", color: "#aaa", fontSize: "13px",
              cursor: "pointer", fontFamily: "inherit"
            }}>إغلاق</button>
          </div>
        </div>
      )}

      <div style={{
        background: "#12121e", border: "1px solid #2a2a4a",
        borderRadius: "20px", padding: "40px", width: "100%", maxWidth: "420px",
        boxShadow: "0 0 60px #4040c022",
        direction: "rtl"
      }}>
        {/* Logo */}
        <div style={{ textAlign: "center", marginBottom: "32px" }}>
          <div style={{
            width: "64px", height: "64px", margin: "0 auto 12px",
            background: "linear-gradient(135deg, #4040c0, #8080ff)",
            borderRadius: "16px", display: "flex", alignItems: "center",
            justifyContent: "center", fontSize: "28px",
            boxShadow: "0 0 30px #6060ff44"
          }}>⬡</div>
          <h1 style={{ color: "#fff", margin: 0, fontSize: "26px", fontWeight: 700, letterSpacing: "3px" }}>NEXUS AI</h1>
          <p style={{ color: "#6060aa", margin: "6px 0 0", fontSize: "13px" }}>نظام الذكاء الاصطناعي المتقدم</p>
        </div>

        {/* Tabs */}
        <div style={{ display: "flex", gap: "8px", marginBottom: "24px" }}>
          {["login", "register"].map(m => (
            <button key={m} onClick={() => { setMode(m); setError(""); }} style={{
              flex: 1, padding: "10px", borderRadius: "8px", border: "none",
              background: mode === m ? "linear-gradient(135deg, #4040c0, #6060ff)" : "#1e1e30",
              color: mode === m ? "#fff" : "#888", cursor: "pointer",
              fontSize: "14px", fontFamily: "inherit", transition: "all 0.2s"
            }}>
              {m === "login" ? "تسجيل الدخول" : "إنشاء حساب"}
            </button>
          ))}
        </div>

        {/* Fields */}
        <div style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
          {mode === "register" && (
            <input name="name" value={form.name} onChange={handle}
              placeholder="الاسم الكامل" style={inputStyle} />
          )}
          <input name="phone" value={form.phone} onChange={handle}
            placeholder="رقم الهاتف" type="tel" style={inputStyle} />
          <input name="password" value={form.password} onChange={handle}
            placeholder="كلمة المرور" type="password" style={inputStyle}
            onKeyDown={e => e.key === "Enter" && submit()} />
        </div>

        {/* TOS */}
        {mode === "register" && (
          <div style={{ marginTop: "14px", display: "flex", alignItems: "center", gap: "8px" }}>
            <input type="checkbox" id="tos" checked={agreed}
              onChange={e => e.target.checked ? setShowTos(true) : setAgreed(false)}
              style={{ width: "16px", height: "16px", cursor: "pointer" }} />
            <label htmlFor="tos" style={{ color: "#aaa", fontSize: "13px", cursor: "pointer" }}>
              أوافق على{" "}
              <span onClick={() => setShowTos(true)}
                style={{ color: "#7070ff", textDecoration: "underline", cursor: "pointer" }}>
                اتفاقية الاستخدام
              </span>
            </label>
          </div>
        )}

        {error && (
          <div style={{
            marginTop: "12px", padding: "10px 14px",
            background: "#ff444422", border: "1px solid #ff4444",
            borderRadius: "8px", color: "#ff8888", fontSize: "13px"
          }}>{error}</div>
        )}

        <button onClick={submit} disabled={loading} style={{
          marginTop: "20px", width: "100%", padding: "14px",
          background: loading ? "#2a2a4a" : "linear-gradient(135deg, #4040c0, #6060ff)",
          border: "none", borderRadius: "10px", color: "#fff",
          fontSize: "15px", cursor: loading ? "not-allowed" : "pointer",
          fontFamily: "inherit", fontWeight: 600, transition: "all 0.2s",
          boxShadow: loading ? "none" : "0 4px 20px #6060ff33"
        }}>
          {loading ? "جاري التحقق..." : mode === "login" ? "دخول →" : "إنشاء الحساب →"}
        </button>
      </div>
    </div>
  );
}

const inputStyle = {
  padding: "12px 16px", background: "#0d0d1a",
  border: "1px solid #2a2a4a", borderRadius: "8px",
  color: "#fff", fontSize: "14px", fontFamily: "inherit",
  outline: "none", direction: "rtl", width: "100%",
  boxSizing: "border-box", transition: "border 0.2s"
};

// ─── Main Chat App ─────────────────────────────────────────────────────────────
function ChatApp({ user, onLogout }) {
  const [messages, setMessages] = useState([
    {
      role: "assistant",
      content: `مرحباً ${user.name}! أنا **NEXUS AI** 🤖\n\nأنا متخصص في:\n- 💻 كتابة كود برمجي ضخم يصل إلى 150,000 سطر\n- 🔍 الهندسة العكسية وتحليل البرامج\n- 🛡️ الأمن السيبراني والاختبار الأخلاقي\n- 📁 تحليل أي نوع من الملفات والصور\n\nارفع ملفاتك أو اسألني عن أي شيء تقني!`
    }
  ]);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [files, setFiles] = useState([]);
  const [error, setError] = useState("");
  const fileRef = useRef();
  const bottomRef = useRef();
  const textRef = useRef();

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  const handleFiles = (e) => {
    const selected = Array.from(e.target.files);
    const oversize = selected.filter(f => f.size > MAX_FILE_SIZE);
    if (oversize.length) {
      setError(`الملف "${oversize[0].name}" يتجاوز 25 ميجا`);
      return;
    }
    setFiles(prev => [...prev, ...selected]);
    setError("");
  };

  const removeFile = (i) => setFiles(f => f.filter((_, idx) => idx !== i));

  const send = useCallback(async () => {
    const text = input.trim();
    if (!text && files.length === 0) return;
    setError("");
    setLoading(true);

    // Prepare file parts for Gemini
    const fileParts = [];
    const fileDescs = [];
    for (const f of files) {
      try {
        if (isImageFile(f)) {
          const b64 = await readFileAsBase64(f);
          fileParts.push({ inline_data: { mime_type: f.type, data: b64 } });
          fileDescs.push(`🖼️ صورة: ${f.name} (${formatBytes(f.size)})`);
        } else if (isPdfFile(f)) {
          const b64 = await readFileAsBase64(f);
          fileParts.push({ inline_data: { mime_type: "application/pdf", data: b64 } });
          fileDescs.push(`📄 PDF: ${f.name} (${formatBytes(f.size)})`);
        } else {
          // Try text read for code/text files
          try {
            const txt = await readFileAsText(f);
            const preview = txt.length > 50000 ? txt.slice(0, 50000) + "\n...[محذوف للاختصار]" : txt;
            fileParts.push({ text: `محتوى الملف "${f.name}":\n\`\`\`\n${preview}\n\`\`\`` });
            fileDescs.push(`📁 ملف: ${f.name} (${txt.split("\n").length} سطر، ${formatBytes(f.size)})`);
          } catch {
            const b64 = await readFileAsBase64(f);
            fileParts.push({ inline_data: { mime_type: f.type || "application/octet-stream", data: b64 } });
            fileDescs.push(`📦 ملف ثنائي: ${f.name} (${formatBytes(f.size)})`);
          }
        }
      } catch (err) {
        fileDescs.push(`⚠️ فشل قراءة: ${f.name}`);
      }
    }

    const displayContent = [fileDescs.join("\n"), text].filter(Boolean).join("\n\n");
    const userMsg = { role: "user", content: displayContent, fileParts: fileParts.length ? fileParts : undefined };

    const newMessages = [...messages, userMsg];
    setMessages(newMessages);
    setInput("");
    setFiles([]);

    try {
      // Build history for API (last 20 messages)
      const historyForApi = newMessages.slice(-20);
      const reply = await callGemini(historyForApi);
      setMessages(prev => [...prev, { role: "assistant", content: reply }]);
    } catch (e) {
      setError("خطأ في الاتصال: " + e.message);
      setMessages(prev => [...prev, { role: "assistant", content: "⚠️ حدث خطأ: " + e.message }]);
    } finally {
      setLoading(false);
    }
  }, [input, files, messages]);

  const handleKey = (e) => {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); }
  };

  return (
    <div style={{
      minHeight: "100vh", background: "#080810",
      display: "flex", flexDirection: "column",
      fontFamily: "'Cairo', 'Segoe UI', sans-serif",
    }}>
      {/* Header */}
      <div style={{
        padding: "0 20px", height: "60px",
        background: "#0f0f1e", borderBottom: "1px solid #2a2a4a",
        display: "flex", alignItems: "center", justifyContent: "space-between",
        position: "sticky", top: 0, zIndex: 10
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: "10px" }}>
          <div style={{
            width: "36px", height: "36px",
            background: "linear-gradient(135deg, #4040c0, #8080ff)",
            borderRadius: "10px", display: "flex", alignItems: "center",
            justifyContent: "center", fontSize: "18px"
          }}>⬡</div>
          <span style={{ color: "#fff", fontWeight: 700, fontSize: "16px", letterSpacing: "2px" }}>NEXUS AI</span>
          <span style={{
            background: "#22c55e22", border: "1px solid #22c55e",
            color: "#22c55e", fontSize: "10px", padding: "2px 8px",
            borderRadius: "10px"
          }}>ONLINE</span>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
          <span style={{ color: "#888", fontSize: "13px" }}>👤 {user.name}</span>
          <button onClick={onLogout} style={{
            background: "#1e1e30", border: "1px solid #3a3a5a",
            color: "#aaa", padding: "6px 14px", borderRadius: "8px",
            cursor: "pointer", fontSize: "12px", fontFamily: "inherit"
          }}>خروج</button>
        </div>
      </div>

      {/* Messages */}
      <div style={{ flex: 1, overflowY: "auto", padding: "20px", maxWidth: "900px", margin: "0 auto", width: "100%" }}>
        {messages.map((msg, i) => (
          <div key={i} style={{
            display: "flex",
            justifyContent: msg.role === "user" ? "flex-start" : "flex-end",
            marginBottom: "16px", direction: "rtl"
          }}>
            <div style={{
              maxWidth: "80%",
              background: msg.role === "user"
                ? "linear-gradient(135deg, #1e1e40, #2a2a55)"
                : "#12121e",
              border: "1px solid " + (msg.role === "user" ? "#3a3a7a" : "#2a2a4a"),
              borderRadius: msg.role === "user" ? "16px 4px 16px 16px" : "4px 16px 16px 16px",
              padding: "14px 18px",
              color: "#e0e0ff",
              fontSize: "14px",
              lineHeight: "1.8",
              boxShadow: msg.role === "assistant" ? "0 2px 20px #4040c011" : "none"
            }}>
              {msg.role === "assistant" && (
                <div style={{ color: "#6060ff", fontSize: "11px", marginBottom: "6px", fontWeight: 600 }}>
                  ⬡ NEXUS AI
                </div>
              )}
              <MessageContent text={msg.content} />
            </div>
          </div>
        ))}

        {loading && (
          <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: "16px", direction: "rtl" }}>
            <div style={{
              background: "#12121e", border: "1px solid #2a2a4a",
              borderRadius: "4px 16px 16px 16px", padding: "14px 18px",
              display: "flex", alignItems: "center", gap: "8px"
            }}>
              {[0, 1, 2].map(i => (
                <div key={i} style={{
                  width: "8px", height: "8px", borderRadius: "50%",
                  background: "#6060ff",
                  animation: `pulse 1.2s ease-in-out ${i * 0.2}s infinite`
                }} />
              ))}
            </div>
          </div>
        )}
        <div ref={bottomRef} />
      </div>

      {/* File Previews */}
      {files.length > 0 && (
        <div style={{
          maxWidth: "900px", margin: "0 auto", width: "100%",
          padding: "0 20px 8px", display: "flex", flexWrap: "wrap", gap: "8px", direction: "rtl"
        }}>
          {files.map((f, i) => (
            <div key={i} style={{
              background: "#1a1a30", border: "1px solid #3a3a6a",
              borderRadius: "8px", padding: "6px 12px",
              display: "flex", alignItems: "center", gap: "8px"
            }}>
              <span style={{ fontSize: "12px", color: "#aaa" }}>
                {isImageFile(f) ? "🖼️" : isPdfFile(f) ? "📄" : "📁"} {f.name} ({formatBytes(f.size)})
              </span>
              <button onClick={() => removeFile(i)} style={{
                background: "none", border: "none", color: "#ff4444",
                cursor: "pointer", fontSize: "14px", padding: 0
              }}>✕</button>
            </div>
          ))}
        </div>
      )}

      {error && (
        <div style={{
          maxWidth: "900px", margin: "0 auto 8px", width: "100%",
          padding: "0 20px", color: "#ff8888", fontSize: "13px"
        }}>⚠️ {error}</div>
      )}

      {/* Input */}
      <div style={{
        padding: "12px 20px 20px", background: "#0f0f1e",
        borderTop: "1px solid #1a1a2e"
      }}>
        <div style={{
          maxWidth: "900px", margin: "0 auto",
          display: "flex", gap: "10px", alignItems: "flex-end", direction: "rtl"
        }}>
          <button onClick={() => fileRef.current.click()} style={{
            width: "44px", height: "44px", flexShrink: 0,
            background: "#1e1e30", border: "1px solid #3a3a5a",
            borderRadius: "10px", color: "#aaa", cursor: "pointer",
            fontSize: "18px", display: "flex", alignItems: "center", justifyContent: "center"
          }} title="رفع ملف">📎</button>
          <input ref={fileRef} type="file" multiple hidden onChange={handleFiles}
            accept="*/*" />

          <textarea ref={textRef} value={input} onChange={e => setInput(e.target.value)}
            onKeyDown={handleKey}
            placeholder="اسأل NEXUS AI... (Shift+Enter لسطر جديد)"
            rows={1}
            style={{
              flex: 1, padding: "12px 16px",
              background: "#0d0d1a", border: "1px solid #2a2a4a",
              borderRadius: "10px", color: "#fff", fontSize: "14px",
              fontFamily: "inherit", resize: "none", outline: "none",
              direction: "rtl", lineHeight: "1.5",
              maxHeight: "200px", overflowY: "auto"
            }}
            onInput={e => {
              e.target.style.height = "auto";
              e.target.style.height = Math.min(e.target.scrollHeight, 200) + "px";
            }}
          />

          <button onClick={send} disabled={loading} style={{
            width: "44px", height: "44px", flexShrink: 0,
            background: loading ? "#2a2a4a" : "linear-gradient(135deg, #4040c0, #6060ff)",
            border: "none", borderRadius: "10px", color: "#fff",
            cursor: loading ? "not-allowed" : "pointer",
            fontSize: "18px", display: "flex", alignItems: "center", justifyContent: "center",
            boxShadow: loading ? "none" : "0 2px 15px #6060ff44"
          }}>
            {loading ? "⏳" : "⬆"}
          </button>
        </div>
        <p style={{ textAlign: "center", color: "#333", fontSize: "11px", margin: "8px 0 0" }}>
          NEXUS AI · Powered by Gemini · للأغراض التعليمية والبحثية
        </p>
      </div>

      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
        * { box-sizing: border-box; }
        body { margin: 0; }
        @keyframes pulse {
          0%, 100% { opacity: 0.3; transform: scale(0.8); }
          50% { opacity: 1; transform: scale(1); }
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #0d0d1a; }
        ::-webkit-scrollbar-thumb { background: #2a2a4a; border-radius: 3px; }
        input::placeholder, textarea::placeholder { color: #444; }
      `}</style>
    </div>
  );
}

// ─── Root ──────────────────────────────────────────────────────────────────────
export default function App() {
  const [user, setUser] = useState(() => getSession());

  const handleAuth = (session) => setUser(session);
  const handleLogout = () => { clearSession(); setUser(null); };

  if (!user) return <AuthScreen onAuth={handleAuth} />;
  return <ChatApp user={user} onLogout={handleLogout} />;
}
