// Calendar JavaScript - Improved Version
let currentDate = new Date();
let events = [];
let currentEventData = null;

// Render the calendar grid
function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    // Update month display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
    document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;
    
    // Get first day of month and number of days
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    
    let html = '';
    
    // Day headers
    const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    dayNames.forEach(day => {
        html += `<div class="calendar-day-header">${day}</div>`;
    });
    
    // Previous month days
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = daysInPrevMonth - i;
        html += `<div class="calendar-day other-month">
            <div class="day-number">${day}</div>
        </div>`;
    }
    
    // Current month days
    const today = new Date();
    today.setHours(0, 0, 0, 0); // Reset time part for accurate comparison
    
    for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(year, month, day);
        date.setHours(0, 0, 0, 0); // Reset time part
        
        // Format date as YYYY-MM-DD without timezone conversion
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = date.getTime() === today.getTime();
        
        const dayEvents = events.filter(e => e.start === dateStr);
        
        let eventsHtml = '';
        const maxDisplay = 3;
        dayEvents.slice(0, maxDisplay).forEach(event => {
            const eventJson = JSON.stringify(event).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            eventsHtml += `<div class="day-event" style="background: ${escapeHtml(event.color)}" onclick='viewEvent(JSON.parse(decodeURIComponent("${encodeURIComponent(JSON.stringify(event))}"))); event.stopPropagation();' title="${escapeHtml(event.title)}">${escapeHtml(truncateText(event.title, 20))}</div>`;
        });
        
        if (dayEvents.length > maxDisplay) {
            eventsHtml += `<div style="font-size: 0.75rem; color: #667eea; padding: 0.25rem; font-weight: 600;">+${dayEvents.length - maxDisplay} more</div>`;
        }
        
        const clickHandler = (typeof canManage !== 'undefined' && canManage) ? `openAddEventModal("${dateStr}")` : '';
        
        html += `<div class="calendar-day ${isToday ? 'today' : ''}" ${clickHandler ? `onclick='${clickHandler}'` : ''}>
            <div class="day-number">${day}</div>
            ${eventsHtml}
        </div>`;
    }
    
    // Next month days
    const totalCells = firstDay + daysInMonth;
    const remainingCells = Math.ceil(totalCells / 7) * 7 - totalCells;
    for (let day = 1; day <= remainingCells; day++) {
        html += `<div class="calendar-day other-month">
            <div class="day-number">${day}</div>
        </div>`;
    }
    
    const calendarGrid = document.getElementById('calendarGrid');
    if (calendarGrid) {
        calendarGrid.innerHTML = html;
    }
}

// Load events from server
async function loadEvents() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    // Get the first day of the month
    const firstDay = new Date(year, month, 1);
    // Get the last day of the month
    const lastDay = new Date(year, month + 1, 0);
    
    const start = firstDay.toISOString().split('T')[0];
    const end = lastDay.toISOString().split('T')[0];
    
    try {
        const response = await fetch(`?ajax=get_events&start=${start}&end=${end}`);
        if (!response.ok) {
            throw new Error('Failed to fetch events');
        }
        events = await response.json();
        renderCalendar();
    } catch (error) {
        console.error('Error loading events:', error);
        events = [];
        renderCalendar();
    }
}

// Change month
function changeMonth(delta) {
    currentDate.setMonth(currentDate.getMonth() + delta);
    loadEvents();
}

// Go to today
function goToToday() {
    currentDate = new Date();
    loadEvents();
}

// Open add event modal
function openAddEventModal(date = null) {
    if (typeof canManage === 'undefined' || !canManage) return;
    
    const modal = document.getElementById('eventModal');
    const form = document.getElementById('eventForm');
    
    if (!modal || !form) return;
    
    document.getElementById('modalTitle').textContent = 'Add Event';
    document.getElementById('formAction').value = 'add_event';
    form.reset();
    document.getElementById('eventId').value = '';
    
    if (date) {
        document.getElementById('eventDate').value = date;
    } else {
        // Set to today's date
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('eventDate').value = today;
    }
    
    // Reset color selection
    document.querySelectorAll('.color-option').forEach(el => el.classList.remove('selected'));
    const firstColor = document.querySelector('.color-option');
    if (firstColor) {
        firstColor.classList.add('selected');
    }
    document.getElementById('eventColor').value = '#667eea';
    
    modal.classList.add('show');
}

// Select color
function selectColor(color, element) {
    document.querySelectorAll('.color-option').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');
    document.getElementById('eventColor').value = color;
}

// View event
function viewEvent(event) {
    if (!event) return;
    
    currentEventData = event;
    document.getElementById('viewEventTitle').textContent = event.title;
    
    let contentHtml = '<div>';
    
    if (event.description) {
        contentHtml += `<p style="color: #4a5568; margin-bottom: 1.5rem; line-height: 1.7;">${escapeHtml(event.description)}</p>`;
    }
    
    contentHtml += `<div>`;
    
    // Date - Fix timezone issue by parsing locally
    const [year, month, day] = event.start.split('-');
    const eventDate = new Date(year, month - 1, day);
    contentHtml += `<div>
        <i class="far fa-calendar"></i>
        <span>${eventDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
    </div>`;
    
    // Time
    if (event.startTime) {
        const startTime = formatTime(event.startTime);
        const endTimeStr = event.endTime ? ` - ${formatTime(event.endTime)}` : '';
        contentHtml += `<div>
            <i class="far fa-clock"></i>
            <span>${startTime}${endTimeStr}</span>
        </div>`;
    }
    
    // Location
    if (event.location) {
        contentHtml += `<div>
            <i class="fas fa-map-marker-alt"></i>
            <span>${escapeHtml(event.location)}</span>
        </div>`;
    }
    
    // Event Type
    contentHtml += `<div>
        <i class="fas fa-tag"></i>
        <span class="event-type-badge type-${event.type}">${capitalizeFirst(event.type)}</span>
    </div>`;
    
    contentHtml += `</div></div>`;
    
    document.getElementById('viewEventContent').innerHTML = contentHtml;
    
    const deleteIdInput = document.getElementById('deleteEventId');
    if (deleteIdInput) {
        deleteIdInput.value = event.id;
    }
    
    const editBtn = document.getElementById('editEventBtn');
    if (editBtn && (typeof canManage !== 'undefined' && canManage)) {
        editBtn.onclick = function() {
            closeModal('viewEventModal');
            editEvent(event);
        };
    }
    
    document.getElementById('viewEventModal').classList.add('show');
}

// View event from sidebar list
function viewEventFromList(event) {
    if (!event) return;
    
    // Convert PHP array format to JavaScript object format
    const eventObj = {
        id: event.id,
        title: event.title,
        start: event.event_date,
        description: event.description,
        location: event.location,
        startTime: event.start_time,
        endTime: event.end_time,
        type: event.event_type,
        color: event.color
    };
    viewEvent(eventObj);
}

// Edit event
function editEvent(event) {
    if (typeof canManage === 'undefined' || !canManage) return;
    
    document.getElementById('modalTitle').textContent = 'Edit Event';
    document.getElementById('formAction').value = 'edit_event';
    document.getElementById('eventId').value = event.id;
    document.getElementById('eventTitle').value = event.title;
    document.getElementById('eventDescription').value = event.description || '';
    document.getElementById('eventDate').value = event.start;
    document.getElementById('startTime').value = event.startTime || '';
    document.getElementById('endTime').value = event.endTime || '';
    document.getElementById('eventLocation').value = event.location || '';
    document.getElementById('eventType').value = event.type;
    document.getElementById('eventColor').value = event.color;
    
    // Set color selection
    document.querySelectorAll('.color-option').forEach(el => {
        el.classList.remove('selected');
        const elColor = rgbToHex(el.style.background);
        if (elColor.toLowerCase() === event.color.toLowerCase()) {
            el.classList.add('selected');
        }
    });
    
    document.getElementById('eventModal').classList.add('show');
}

// Close modal
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to truncate text
function truncateText(text, maxLength) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}

// Helper function to capitalize first letter
function capitalizeFirst(text) {
    if (!text) return '';
    return text.charAt(0).toUpperCase() + text.slice(1);
}

// Helper function to format time
function formatTime(timeStr) {
    if (!timeStr) return '';
    try {
        const [hours, minutes] = timeStr.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 || 12;
        return `${displayHour}:${minutes} ${ampm}`;
    } catch (e) {
        return timeStr;
    }
}

// Helper function to convert RGB to Hex
function rgbToHex(rgb) {
    if (!rgb) return '#000000';
    if (rgb.startsWith('#')) return rgb;
    
    const match = rgb.match(/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/);
    if (!match) return rgb;
    
    const r = parseInt(match[1]);
    const g = parseInt(match[2]);
    const b = parseInt(match[3]);
    
    return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

// Close modals on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('show');
        });
    }
});

// Initialize calendar on page load
document.addEventListener('DOMContentLoaded', function() {
    loadEvents();
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 300);
        });
    }, 5000);
});

// Prevent multiple form submissions
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('eventForm');
    if (form) {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('.btn-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    }
});