</main>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Sidebar Toggle Script -->
    <script>
        // Sidebar Toggle Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const body = document.body;
            
            // Check if there's a saved state in localStorage
            const sidebarState = localStorage.getItem('sidebarCollapsed');
            
            // Apply saved state on page load
            if (sidebarState === 'true') {
                body.classList.add('sidebar-collapsed');
            } else {
                body.classList.remove('sidebar-collapsed');
            }
            
            // Toggle sidebar when button is clicked
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    body.classList.toggle('sidebar-collapsed');
                    
                    // Save the state to localStorage
                    const isCollapsed = body.classList.contains('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', isCollapsed);
                });
            }
            
            // Handle responsive behavior
            function handleResize() {
                const width = window.innerWidth;
                
                // On mobile, start with collapsed sidebar
                if (width <= 768) {
                    // Don't override user preference on desktop to mobile transition
                    if (!localStorage.getItem('sidebarCollapsed')) {
                        body.classList.add('sidebar-collapsed');
                    }
                }
            }
            
            // Check on load
            handleResize();
            
            // Check on resize
            window.addEventListener('resize', handleResize);
            
            // Optional: Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                const sidebar = document.querySelector('.sidebar');
                const sidebarToggle = document.getElementById('sidebarToggle');
                
                // Only on mobile
                if (window.innerWidth <= 768) {
                    // If sidebar is open and click is outside sidebar and toggle button
                    if (!body.classList.contains('sidebar-collapsed') && 
                        sidebar && !sidebar.contains(event.target) && 
                        sidebarToggle && !sidebarToggle.contains(event.target)) {
                        body.classList.add('sidebar-collapsed');
                        localStorage.setItem('sidebarCollapsed', true);
                    }
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
    const notificationBell = document.getElementById('notificationBell');
    const notificationPanel = document.getElementById('notificationPanel');
    
    if (notificationBell && notificationPanel) {
        notificationBell.addEventListener('click', function(e) {
            e.stopPropagation();
            if (notificationPanel.style.display === 'none') {
                notificationPanel.style.display = 'block';
            } else {
                notificationPanel.style.display = 'none';
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationPanel.contains(e.target) && e.target !== notificationBell) {
                notificationPanel.style.display = 'none';
            }
        });
    }
});
    </script>
    
    <!-- Extra JS for specific pages -->
    <?php if (isset($extra_js)): ?>
        <?php echo $extra_js; ?>
    <?php endif; ?>

    
</body>
</html>