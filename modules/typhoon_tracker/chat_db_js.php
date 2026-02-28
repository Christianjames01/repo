<script>

// ============================================================================
// STATE
// ============================================================================
const CHAT_STORAGE_KEY = 'ligtas_chat_v5';
let isSending         = false;
let isFirstOpen       = true;
let messageCount      = 0;

// ============================================================================
// INIT
// ============================================================================
document.addEventListener('DOMContentLoaded', () => {
    injectChatStyles();
    loadChatHistory();
});

// ============================================================================
// TOGGLE CHAT WINDOW
// ============================================================================
function toggleChatBubble() {
    const win = document.getElementById('chatBubbleWindow');
    const btn = document.getElementById('chatBubbleBtn');
    const dot = document.getElementById('chatUnreadDot');

    if (!win) return;

    const isOpen = win.classList.contains('chat-open');

    if (isOpen) {
        win.classList.remove('chat-open');
        btn && btn.classList.remove('chat-btn-active');
    } else {
        win.classList.add('chat-open');
        btn && btn.classList.add('chat-btn-active');
        if (dot) dot.style.display = 'none';

        // Auto-send welcome if truly first open and no history
        if (isFirstOpen) {
            isFirstOpen = false;
            const container = document.getElementById('chatContainer');
            if (container && container.querySelectorAll('.chat-msg').length === 0) {
                showWelcomeMessage();
            }
        }

        setTimeout(() => {
            const input = document.getElementById('messageInput');
            if (input) input.focus();
            scrollToBottom();
        }, 350);
    }
}

// ============================================================================
// WELCOME MESSAGE (shown only if no history)
// ============================================================================
function showWelcomeMessage() {
    const hasWeather = typeof weatherData !== 'undefined' && weatherData;
    const hasTyphoon = typeof typhoonData !== 'undefined' && typhoonData && typhoonData.length > 0;
    const loc = (typeof userLocation !== 'undefined' && userLocation &&
                 !userLocation.includes('Detecting')) ? userLocation : 'your area';

    let welcome = `Kumusta! I'm **Ligtas AI**, your weather safety assistant for ${loc}. 🌤️\n\n`;

    if (hasTyphoon) {
        const t = typhoonData[0];
        welcome += `⚠️ **Heads up** — I'm currently tracking **Typhoon ${t.name}** at ${t.distance}km from you with ${t.windSpeed} km/h winds.\n\n`;
    } else if (hasWeather) {
        const w = parseFloat(weatherData.windSpeed);
        const h = parseFloat(weatherData.humidity);
        if (w > 39 || h > 85) {
            welcome += `📊 I can see your current weather — things look a bit active right now.\n\n`;
        } else {
            welcome += `📊 Your current weather looks fairly normal.\n\n`;
        }
    }

    welcome += `Ask me anything about:\n• 🛡️ Your current safety status\n• 🌀 Typhoon threats and warnings\n• 🎒 Emergency preparation\n• 🚗 When to evacuate`;

    appendMessage('assistant', welcome, false);
    hideQuickActions(false);
}

// ============================================================================
// SEND MESSAGE
// ============================================================================
async function sendMessage() {
    const input  = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    if (!input || !input.value.trim() || isSending) return;

    const msg = input.value.trim();
    input.value = '';
    isSending = true;

    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="32" style="animation:spin 0.8s linear infinite"/></svg>`;
    }

    // Hide quick actions after first message
    hideQuickActions(true);

    // Show user message
    appendMessage('user', msg);
    saveChatHistory();

    // Show typing
    const typingId = showTyping();

    // Get current context
    const now = new Date();
    const currentDateTime = now.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true
    });

    const wData    = (typeof weatherData  !== 'undefined') ? weatherData  : null;
    const tData    = (typeof typhoonData  !== 'undefined') ? typhoonData  : [];
    const location = (typeof userLocation !== 'undefined') ? userLocation : 'Philippines';

    try {
        const res  = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message:         msg,
                weatherData:     wData,
                typhoonData:     tData,
                userLocation:    location,
                currentDateTime: currentDateTime,
            })
        });

        removeTyping(typingId);

        if (!res.ok) throw new Error(`Server error ${res.status}`);
        const data = await res.json();

        if (data.success) {
            appendMessage('assistant', data.response, data.fallback === true);
        } else {
            appendMessage('assistant', data.error || 'Sorry, something went wrong. Please try again.', true);
        }
    } catch (err) {
        removeTyping(typingId);
        const isOffline = !navigator.onLine;
        appendMessage('assistant',
            isOffline
                ? "It looks like you're offline. Please check your internet connection and try again."
                : "I'm having trouble connecting right now. Please try again in a moment. If there's an emergency, call **911** or PAGASA at **(02) 8284-0800**.",
            true
        );
    } finally {
        isSending = false;
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
        }
        const inputEl = document.getElementById('messageInput');
        if (inputEl) inputEl.focus();
        saveChatHistory();
    }
}

// ============================================================================
// QUICK QUESTION
// ============================================================================
function askQuestion(question) {
    const input = document.getElementById('messageInput');
    if (input) {
        input.value = question;
        sendMessage();
    }
}

// ============================================================================
// APPEND MESSAGE WITH MARKDOWN RENDERING
// ============================================================================
function appendMessage(role, content, isFallback = false) {
    const container = document.getElementById('chatContainer');
    if (!container) return;

    const wrapper = document.createElement('div');
    wrapper.className = `chat-msg chat-msg-${role}`;

    if (role === 'assistant') {
        // Avatar
        const avatar = document.createElement('div');
        avatar.className = 'msg-avatar';
        avatar.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>`;
        wrapper.appendChild(avatar);
    }

    const bubble = document.createElement('div');
    bubble.className = `msg-bubble msg-bubble-${role}`;

    // Render markdown
    bubble.innerHTML = renderMarkdown(content);

    // Fallback badge
    if (isFallback) {
        const badge = document.createElement('div');
        badge.className = 'msg-badge-fallback';
        badge.textContent = 'Offline mode';
        bubble.appendChild(badge);
    }

    // Timestamp
    const time = document.createElement('div');
    time.className = 'msg-time';
    time.textContent = new Date().toLocaleTimeString('en-PH', {
        timeZone: 'Asia/Manila',
        hour: '2-digit', minute: '2-digit', hour12: true
    });
    bubble.appendChild(time);

    wrapper.appendChild(bubble);
    container.appendChild(wrapper);

    // Animate in
    requestAnimationFrame(() => {
        wrapper.classList.add('chat-msg-in');
    });

    scrollToBottom();
    messageCount++;
}

// ============================================================================
// MARKDOWN RENDERER
// ============================================================================
function renderMarkdown(text) {
    if (!text) return '';

    let html = escapeHtml(text);

    // Code blocks (before other formatting)
    html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
    html = html.replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>');

    // Bold **text** or __text__
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

    // Italic *text*
    html = html.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

    // Emergency numbers — make them visually prominent
    html = html.replace(/\b(911|143)\b/g, '<span class="emerg-num">$1</span>');
    html = html.replace(/\(02\)\s*8284-0800/g, '<span class="emerg-num">(02) 8284-0800</span>');

    // Headers
    html = html.replace(/^### (.+)$/gm, '<h4 class="md-h4">$1</h4>');
    html = html.replace(/^## (.+)$/gm, '<h3 class="md-h3">$1</h3>');
    html = html.replace(/^# (.+)$/gm, '<h2 class="md-h2">$1</h2>');

    // Bullet lists — handle • and - and *
    html = html.replace(/^[•\-\*] (.+)$/gm, '<li>$1</li>');
    // Wrap consecutive <li> in <ul>
    html = html.replace(/(<li>[\s\S]*?<\/li>)(\n<li>[\s\S]*?<\/li>)*/g, (match) => {
        return '<ul class="md-list">' + match + '</ul>';
    });

    // Numbered lists
    html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

    // Warning/alert lines — lines starting with ⚠️
    html = html.replace(/^(⚠️.+)$/gm, '<div class="alert-line alert-warn">$1</div>');
    html = html.replace(/^(🚨.+)$/gm, '<div class="alert-line alert-crit">$1</div>');
    html = html.replace(/^(✅.+|✓.+)$/gm, '<div class="alert-line alert-ok">$1</div>');

    // Line breaks
    html = html.replace(/\n\n/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');

    // Wrap in paragraph if no block elements
    if (!html.includes('<p>') && !html.includes('<ul>') && !html.includes('<div') && !html.includes('<h')) {
        html = '<p>' + html + '</p>';
    }

    return html;
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ============================================================================
// TYPING INDICATOR
// ============================================================================
function showTyping() {
    const container = document.getElementById('chatContainer');
    if (!container) return null;

    const id = 'typing-' + Date.now();
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-msg chat-msg-assistant';
    wrapper.id = id;

    const avatar = document.createElement('div');
    avatar.className = 'msg-avatar';
    avatar.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>`;

    const bubble = document.createElement('div');
    bubble.className = 'msg-bubble msg-bubble-assistant typing-bubble';
    bubble.innerHTML = `<div class="typing-dots"><span></span><span></span><span></span></div>`;

    wrapper.appendChild(avatar);
    wrapper.appendChild(bubble);
    container.appendChild(wrapper);

    requestAnimationFrame(() => wrapper.classList.add('chat-msg-in'));
    scrollToBottom();

    return id;
}

function removeTyping(id) {
    if (!id) return;
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('chat-msg-out');
        setTimeout(() => el.remove(), 250);
    }
}

// ============================================================================
// QUICK ACTIONS
// ============================================================================
function hideQuickActions(animate) {
    const qa = document.getElementById('chatQuickActions');
    if (!qa || qa.style.display === 'none') return;
    if (animate) {
        qa.style.transition = 'opacity 0.3s, max-height 0.3s';
        qa.style.opacity = '0';
        qa.style.maxHeight = '0';
        setTimeout(() => qa.style.display = 'none', 320);
    } else {
        // keep visible
    }
}

// ============================================================================
// SCROLL
// ============================================================================
function scrollToBottom() {
    const c = document.getElementById('chatContainer');
    if (c) {
        requestAnimationFrame(() => {
            c.scrollTop = c.scrollHeight;
        });
    }
}

// ============================================================================
// HISTORY
// ============================================================================
function saveChatHistory() {
    try {
        const container = document.getElementById('chatContainer');
        if (!container) return;

        const messages = [];
        container.querySelectorAll('.chat-msg').forEach(el => {
            const role   = el.classList.contains('chat-msg-user') ? 'user' : 'assistant';
            const bubble = el.querySelector('.msg-bubble');
            if (!bubble) return;

            // Extract plain text from rendered HTML
            const clone = bubble.cloneNode(true);
            clone.querySelectorAll('.msg-time, .msg-badge-fallback').forEach(e => e.remove());
            messages.push({ role, html: clone.innerHTML });
        });

        localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
    } catch (e) {
        console.warn('Chat save error:', e);
    }
}

function loadChatHistory() {
    try {
        const saved = localStorage.getItem(CHAT_STORAGE_KEY);
        const container = document.getElementById('chatContainer');
        if (!container) return;

        if (saved) {
            const messages = JSON.parse(saved);
            if (messages && messages.length > 0) {
                messages.forEach(msg => {
                    const wrapper = document.createElement('div');
                    wrapper.className = `chat-msg chat-msg-${msg.role} chat-msg-in`;

                    if (msg.role === 'assistant') {
                        const avatar = document.createElement('div');
                        avatar.className = 'msg-avatar';
                        avatar.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>`;
                        wrapper.appendChild(avatar);
                    }

                    const bubble = document.createElement('div');
                    bubble.className = `msg-bubble msg-bubble-${msg.role}`;
                    bubble.innerHTML = msg.html || '';
                    wrapper.appendChild(bubble);
                    container.appendChild(wrapper);
                });

                messageCount = messages.length;
                isFirstOpen = false;
                scrollToBottom();

                // Hide quick actions if there's existing history
                const qa = document.getElementById('chatQuickActions');
                if (qa) qa.style.display = 'none';
                return;
            }
        }
    } catch (e) {
        console.warn('Chat load error:', e);
    }
}

// ============================================================================
// CLEAR CHAT
// ============================================================================
function clearChatHistory() {
    const modal = document.getElementById('clearChatModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeClearChatModal() {
    const modal = document.getElementById('clearChatModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function confirmClearChat() {
    localStorage.removeItem(CHAT_STORAGE_KEY);
    const container = document.getElementById('chatContainer');
    if (container) container.innerHTML = '';

    // Show quick actions again
    const qa = document.getElementById('chatQuickActions');
    if (qa) {
        qa.style.display = '';
        qa.style.opacity = '1';
        qa.style.maxHeight = '';
    }

    messageCount = 0;
    isFirstOpen = true;
    closeClearChatModal();
    showWelcomeMessage();

    // Toast
    showToast('Chat history cleared');
}

// ============================================================================
// TOAST
// ============================================================================
function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'ligtas-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('toast-in'));
    setTimeout(() => {
        t.classList.remove('toast-in');
        setTimeout(() => t.remove(), 400);
    }, 2500);
}

// ============================================================================
// INJECT STYLES — all chat styles in one place
// ============================================================================
function injectChatStyles() {
    if (document.getElementById('ligtas-chat-styles')) return;

    const style = document.createElement('style');
    style.id = 'ligtas-chat-styles';
    style.textContent = `
        /* ── CHAT BUBBLE BUTTON ─────────────────────────────────────────── */
        .chat-bubble-container {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 9999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        }

        .chat-bubble-button {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.875rem 1.25rem;
            cursor: pointer;
            font-size: 0.9375rem;
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(30, 64, 175, 0.45);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .chat-bubble-button:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(30, 64, 175, 0.5);
        }
        .chat-btn-active {
            background: #1e3a8a !important;
        }
        .chat-bubble-icon-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .chat-unread-dot {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 9px;
            height: 9px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid white;
        }
        .chat-bubble-label { font-size: 0.875rem; }

        /* ── CHAT WINDOW ────────────────────────────────────────────────── */
        .chat-bubble-window {
            position: absolute;
            bottom: calc(100% + 1rem);
            right: 0;
            width: 380px;
            max-height: 600px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18), 0 4px 16px rgba(0,0,0,0.08);
            display: none;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.08);
            transform-origin: bottom right;
        }
        .chat-bubble-window.chat-open {
            display: flex;
            animation: chatSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes chatSlideIn {
            from { opacity: 0; transform: scale(0.85) translateY(16px); }
            to   { opacity: 1; transform: scale(1)    translateY(0); }
        }
        @media (max-width: 480px) {
            .chat-bubble-window {
                width: calc(100vw - 2rem);
                right: -1rem;
                max-height: 75vh;
            }
            .chat-bubble-container { bottom: 1rem; right: 1rem; }
        }

        /* ── CHAT HEADER ────────────────────────────────────────────────── */
        .chat-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            padding: 1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .chat-header-info { display: flex; align-items: center; gap: 0.75rem; }
        .chat-avatar {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255,255,255,0.3);
        }
        .chat-header-name { font-size: 1rem; font-weight: 700; line-height: 1.2; }
        .chat-header-status {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.75);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-top: 2px;
        }
        .status-dot {
            width: 7px; height: 7px;
            background: #34d399;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .chat-header-actions { display: flex; gap: 0.4rem; }
        .chat-icon-btn {
            background: rgba(255,255,255,0.12);
            border: none;
            color: white;
            width: 32px; height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        .chat-icon-btn:hover { background: rgba(255,255,255,0.25); }

        /* ── MESSAGES ───────────────────────────────────────────────────── */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-height: 280px;
            max-height: 320px;
        }
        .chat-messages::-webkit-scrollbar { width: 4px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }

        /* Message rows */
        .chat-msg {
            display: flex;
            align-items: flex-end;
            gap: 0.5rem;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .chat-msg-in {
            opacity: 1;
            transform: translateY(0);
        }
        .chat-msg-out {
            opacity: 0;
            transform: scale(0.9);
            transition: opacity 0.2s, transform 0.2s;
        }
        .chat-msg-user {
            flex-direction: row-reverse;
        }

        /* Avatar */
        .msg-avatar {
            width: 28px; height: 28px;
            background: #1e40af;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Bubbles */
        .msg-bubble {
            max-width: 82%;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            font-size: 0.875rem;
            line-height: 1.55;
            word-wrap: break-word;
        }
        .msg-bubble-user {
            background: #1e40af;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .msg-bubble-assistant {
            background: white;
            color: #1e293b;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }

        /* Timestamp */
        .msg-time {
            font-size: 0.6875rem;
            color: rgba(100,116,139,0.7);
            text-align: right;
            margin-top: 0.4rem;
        }
        .msg-bubble-user .msg-time { color: rgba(255,255,255,0.55); }

        /* Fallback badge */
        .msg-badge-fallback {
            display: inline-block;
            margin-top: 0.4rem;
            font-size: 0.6875rem;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 10px;
        }

        /* Typing */
        .typing-bubble { padding: 0.875rem 1.25rem; }
        .typing-dots { display: flex; gap: 5px; align-items: center; height: 18px; }
        .typing-dots span {
            width: 7px; height: 7px;
            background: #94a3b8;
            border-radius: 50%;
            animation: typingBounce 1.2s infinite ease-in-out;
        }
        .typing-dots span:nth-child(1) { animation-delay: 0s; }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-6px); }
        }

        /* ── MARKDOWN STYLES IN BUBBLES ─────────────────────────────────── */
        .msg-bubble p { margin: 0 0 0.5em 0; }
        .msg-bubble p:last-child { margin-bottom: 0; }
        .msg-bubble strong { font-weight: 700; }
        .msg-bubble em { font-style: italic; }
        .msg-bubble h2.md-h2, .msg-bubble h3.md-h3, .msg-bubble h4.md-h4 {
            font-weight: 700; margin: 0.75em 0 0.35em;
        }
        .msg-bubble h2.md-h2 { font-size: 1rem; }
        .msg-bubble h3.md-h3 { font-size: 0.9375rem; }
        .msg-bubble h4.md-h4 { font-size: 0.875rem; }
        .msg-bubble ul.md-list {
            margin: 0.4em 0;
            padding-left: 1.4em;
            list-style: disc;
        }
        .msg-bubble ul.md-list li { margin: 0.2em 0; }
        .msg-bubble code.inline-code {
            background: rgba(0,0,0,0.06);
            padding: 2px 5px;
            border-radius: 4px;
            font-size: 0.8125rem;
            font-family: monospace;
        }
        .msg-bubble-user code.inline-code { background: rgba(255,255,255,0.2); }
        .msg-bubble .alert-line {
            margin: 0.35em 0;
            padding: 0.35em 0.75em;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 500;
        }
        .alert-warn { background: #fff7ed; color: #9a3412; border-left: 3px solid #f97316; }
        .alert-crit { background: #fff1f2; color: #9f1239; border-left: 3px solid #f43f5e; }
        .alert-ok   { background: #f0fdf4; color: #14532d; border-left: 3px solid #22c55e; }
        .emerg-num  {
            font-weight: 800;
            color: #dc2626;
            background: #fef2f2;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .msg-bubble-user .emerg-num {
            color: #fca5a5;
            background: rgba(220, 38, 38, 0.2);
        }

        /* ── QUICK ACTIONS ──────────────────────────────────────────────── */
        .chat-quick-actions {
            padding: 0.75rem 1rem 0.25rem;
            background: #f8fafc;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            border-top: 1px solid #e2e8f0;
            transition: opacity 0.3s, max-height 0.3s;
            max-height: 200px;
            overflow: hidden;
        }
        .quick-chip {
            background: white;
            border: 1px solid #e2e8f0;
            color: #374151;
            padding: 0.4rem 0.875rem;
            border-radius: 20px;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all 0.15s;
            font-weight: 500;
            white-space: nowrap;
        }
        .quick-chip:hover {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
            transform: translateY(-1px);
        }

        /* ── INPUT AREA ─────────────────────────────────────────────────── */
        .chat-input-area {
            padding: 0.875rem 1rem;
            background: white;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .chat-input-wrap {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .chat-input-wrap input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 25px;
            font-size: 0.9rem;
            outline: none;
            background: #f8fafc;
            color: #1e293b;
            transition: border-color 0.2s, background 0.2s;
        }
        .chat-input-wrap input:focus {
            border-color: #1e40af;
            background: white;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        .send-btn {
            width: 44px; height: 44px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .send-btn:hover:not(:disabled) {
            background: #1d4ed8;
            transform: scale(1.05);
        }
        .send-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .chat-footer-note {
            font-size: 0.6875rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 0.5rem;
        }

        /* ── TOAST ──────────────────────────────────────────────────────── */
        .ligtas-toast {
            position: fixed;
            bottom: 5rem;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: #1e293b;
            color: white;
            padding: 0.625rem 1.5rem;
            border-radius: 50px;
            font-size: 0.875rem;
            opacity: 0;
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 99999;
            pointer-events: none;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .toast-in {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* ── SPIN ANIMATION ─────────────────────────────────────────────── */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
    `;

    document.head.appendChild(style);
}
</script>