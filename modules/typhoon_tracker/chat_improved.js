/**
 * IMPROVED CHAT SYSTEM - Fixes conversation duplicates and reliability
 * Version 2.0 - Prevents message duplication and handles conversation properly
 */

// Global conversation state
let conversationHistory = [];
let isProcessing = false;

// Initialize chat on page load
document.addEventListener('DOMContentLoaded', function() {
    loadChatHistory();
    initializeChat();
});

function initializeChat() {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return;
    
    // Load saved conversation
    if (conversationHistory.length === 0) {
        // Show welcome message only if no history
        addWelcomeMessage();
    } else {
        // Render existing conversation
        renderConversation();
    }
}

function addWelcomeMessage() {
    const welcomeMsg = {
        role: 'assistant',
        content: `👋 Hello! I'm your AI Weather Safety Assistant powered by real-time data.

I can help you with:
• **Current weather analysis** - Understanding what's happening now
• **Typhoon threats** - Active storms and safety guidance  
• **Safety assessments** - Are you at risk?
• **Weather forecasts** - What to expect
• **Emergency preparation** - How to stay safe

What would you like to know?`,
        timestamp: new Date().toISOString()
    };
    
    conversationHistory.push(welcomeMsg);
    saveChatHistory();
    renderConversation();
}

function renderConversation() {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return;
    
    // Clear container
    chatContainer.innerHTML = '';
    
    // Render all messages
    conversationHistory.forEach(msg => {
        const messageDiv = createMessageElement(msg);
        chatContainer.appendChild(messageDiv);
    });
    
    // Scroll to bottom
    scrollToBottom();
}

function createMessageElement(message) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${message.role === 'user' ? 'user-message' : 'assistant-message'}`;
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    
    // Format content (support markdown-style formatting)
    const formattedContent = formatMessageContent(message.content);
    bubble.innerHTML = formattedContent;
    
    // Add timestamp
    const time = document.createElement('div');
    time.className = 'message-time';
    time.textContent = formatTimestamp(message.timestamp);
    
    messageDiv.appendChild(bubble);
    messageDiv.appendChild(time);
    
    return messageDiv;
}

function formatMessageContent(content) {
    // Convert markdown-like formatting to HTML
    let formatted = content;
    
    // Headers
    formatted = formatted.replace(/^### (.*$)/gm, '<h4>$1</h4>');
    formatted = formatted.replace(/^## (.*$)/gm, '<h3>$1</h3>');
    formatted = formatted.replace(/^# (.*$)/gm, '<h2>$1</h2>');
    
    // Bold
    formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    
    // Bullet points
    formatted = formatted.replace(/^[•\-] (.*$)/gm, '<li>$1</li>');
    formatted = formatted.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
    
    // Numbered lists
    formatted = formatted.replace(/^\d+\. (.*$)/gm, '<li>$1</li>');
    
    // Emojis and icons at start of lines
    formatted = formatted.replace(/^(🌧️|⚠️|🌪️|💨|✅|🔴|🟠|🟡|🟢|📊|📉|💧|🔍|✓|❌)/gm, '<span class="emoji-icon">$1</span>');
    
    // Line breaks
    formatted = formatted.replace(/\n\n/g, '<br><br>');
    formatted = formatted.replace(/\n/g, '<br>');
    
    return formatted;
}

function formatTimestamp(timestamp) {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

function scrollToBottom() {
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }
}

// Main send message function
async function sendMessage() {
    const input = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    
    if (!input || !input.value.trim()) return;
    if (isProcessing) return; // Prevent duplicate sends
    
    const userMessage = input.value.trim();
    input.value = '';
    
    // Disable input while processing
    isProcessing = true;
    sendBtn.disabled = true;
    input.disabled = true;
    
    // Add user message to history
    const userMsg = {
        role: 'user',
        content: userMessage,
        timestamp: new Date().toISOString()
    };
    
    conversationHistory.push(userMsg);
    saveChatHistory();
    renderConversation();
    
    // Show typing indicator
    showTypingIndicator();
    
    try {
        // Get AI response
        const response = await getAIResponse(userMessage);
        
        // Remove typing indicator
        removeTypingIndicator();
        
        // Add assistant response to history
        const assistantMsg = {
            role: 'assistant',
            content: response,
            timestamp: new Date().toISOString()
        };
        
        conversationHistory.push(assistantMsg);
        saveChatHistory();
        renderConversation();
        
    } catch (error) {
        console.error('Chat error:', error);
        removeTypingIndicator();
        
        // Add error message
        const errorMsg = {
            role: 'assistant',
            content: `⚠️ I'm having trouble connecting right now. Please try again in a moment.

**Error details:** ${error.message}

You can still check the weather data displayed on the page, or try asking your question again.`,
            timestamp: new Date().toISOString()
        };
        
        conversationHistory.push(errorMsg);
        saveChatHistory();
        renderConversation();
    } finally {
        // Re-enable input
        isProcessing = false;
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
    }
}

async function getAIResponse(message) {
    // Gather current weather data
    const weatherData = getCurrentWeatherData();
    const typhoonData = getCurrentTyphoonData();
    const forecastData = getCurrentForecastData();
    const userLocation = document.getElementById('userLocation')?.textContent || 'Philippines';
    const currentDateTime = new Date().toLocaleString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Prepare conversation history (last 10 messages max to avoid token limits)
    const recentHistory = conversationHistory.slice(-10).map(msg => ({
        role: msg.role,
        content: msg.content
    }));
    
    // Make API call
    const response = await fetch('typhoon_tracker_improved.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            message: message,
            weatherData: weatherData,
            typhoonData: typhoonData,
            forecastData: forecastData,
            userLocation: userLocation,
            currentDateTime: currentDateTime,
            conversationHistory: recentHistory
        })
    });
    
    if (!response.ok) {
        throw new Error(`Server error: ${response.status}`);
    }
    
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.error || 'Unknown error');
    }
    
    return data.response;
}

function getCurrentWeatherData() {
    // Extract current weather from the page
    const windSpeed = document.querySelector('#windSpeed .value-number')?.textContent;
    const temperature = document.querySelector('#temperature .value-number')?.textContent;
    const pressure = document.querySelector('#pressure .value-number')?.textContent;
    const humidity = document.querySelector('#humidity .value-number')?.textContent;
    
    if (!windSpeed || windSpeed === '--') return null;
    
    return {
        windSpeed: windSpeed,
        temperature: temperature,
        pressure: pressure,
        humidity: humidity
    };
}

function getCurrentTyphoonData() {
    // This should be populated from your typhoon tracking system
    // Return empty array if no typhoons, or array of typhoon objects
    if (window.activeTyphoons && window.activeTyphoons.length > 0) {
        return window.activeTyphoons;
    }
    return [];
}

function getCurrentForecastData() {
    // This should return forecast data if available
    if (window.forecastData) {
        return window.forecastData;
    }
    return null;
}

function showTypingIndicator() {
    const chatContainer = document.getElementById('chatContainer');
    if (!chatContainer) return;
    
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message assistant-message typing-indicator';
    typingDiv.id = 'typingIndicator';
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    bubble.innerHTML = `
        <div class="typing-dots">
            <span></span>
            <span></span>
            <span></span>
        </div>
    `;
    
    typingDiv.appendChild(bubble);
    chatContainer.appendChild(typingDiv);
    scrollToBottom();
}

function removeTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.remove();
    }
}

// Quick question function
function askQuestion(question) {
    const input = document.getElementById('messageInput');
    if (input) {
        input.value = question;
        sendMessage();
    }
}

// Chat history management
function saveChatHistory() {
    try {
        localStorage.setItem('typhoonChatHistory', JSON.stringify(conversationHistory));
    } catch (error) {
        console.error('Failed to save chat history:', error);
    }
}

function loadChatHistory() {
    try {
        const saved = localStorage.getItem('typhoonChatHistory');
        if (saved) {
            conversationHistory = JSON.parse(saved);
        }
    } catch (error) {
        console.error('Failed to load chat history:', error);
        conversationHistory = [];
    }
}

function clearChatHistory() {
    // Show confirmation modal
    const modal = document.getElementById('clearChatModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function confirmClearChat() {
    // Clear history
    conversationHistory = [];
    localStorage.removeItem('typhoonChatHistory');
    
    // Re-initialize with welcome message
    addWelcomeMessage();
    
    // Close modal
    closeClearChatModal();
}

function closeClearChatModal() {
    const modal = document.getElementById('clearChatModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Chat bubble toggle
function toggleChatBubble() {
    const container = document.getElementById('chatBubbleContainer');
    const window = document.getElementById('chatBubbleWindow');
    
    if (!container || !window) return;
    
    const isOpen = container.classList.contains('open');
    
    if (isOpen) {
        container.classList.remove('open');
    } else {
        container.classList.add('open');
        // Focus input when opening
        setTimeout(() => {
            const input = document.getElementById('messageInput');
            if (input) input.focus();
        }, 300);
    }
}

// Handle Enter key in input
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('messageInput');
    if (input) {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }
});

// Expose functions globally
window.sendMessage = sendMessage;
window.askQuestion = askQuestion;
window.clearChatHistory = clearChatHistory;
window.confirmClearChat = confirmClearChat;
window.closeClearChatModal = closeClearChatModal;
window.toggleChatBubble = toggleChatBubble;