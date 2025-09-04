// Global Dark Mode System for Member Pages
class DarkModeManager {
    constructor() {
        this.body = document.body;
        this.isDarkMode = false;
        this.init();
    }

    init() {
        this.loadPreference();
        this.applyTheme();
        this.setupEventListeners();
    }

    loadPreference() {
        // Check localStorage first
        const savedDarkMode = localStorage.getItem('darkMode');
        
        if (savedDarkMode === 'true') {
            this.isDarkMode = true;
        } else if (savedDarkMode === 'false') {
            this.isDarkMode = false;
        } else {
            // Default to light mode if no preference saved
            this.isDarkMode = false;
        }
    }

    applyTheme() {
        if (this.isDarkMode) {
            this.body.classList.add('dark-mode');
        } else {
            this.body.classList.remove('dark-mode');
        }
    }

    toggle() {
        this.isDarkMode = !this.isDarkMode;
        this.applyTheme();
        localStorage.setItem('darkMode', this.isDarkMode.toString());
        
        // Update any toggle switches on the page
        const toggles = document.querySelectorAll('input[name="dark_mode"]');
        toggles.forEach(toggle => {
            toggle.checked = this.isDarkMode;
        });
        
        // Update status text if it exists
        const statusText = document.getElementById('darkModeStatus');
        if (statusText) {
            statusText.textContent = this.isDarkMode ? 'Dark mode active' : 'Light mode active';
        }
        
        console.log(`Dark mode ${this.isDarkMode ? 'enabled' : 'disabled'}`);
    }

    setupEventListeners() {
        // Listen for dark mode toggle changes
        document.addEventListener('change', (e) => {
            if (e.target.name === 'dark_mode') {
                this.toggle();
            }
        });

        // Listen for messages from other pages
        window.addEventListener('message', (e) => {
            if (e.data.type === 'darkModeToggle') {
                this.toggle();
            }
        });
    }

    // Public method to check current state
    getCurrentMode() {
        return this.isDarkMode;
    }
}

// Initialize dark mode when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.darkModeManager = new DarkModeManager();
});

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DarkModeManager;
}
