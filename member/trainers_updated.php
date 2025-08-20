<?php
// ... Keep all the existing PHP code until the search input section ...

                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 space-y-4 lg:space-y-0">
                    <h2 class="text-2xl font-semibold text-gray-800">Our Trainers</h2>
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4 w-full lg:w-auto">
                        <div class="relative">
                            <input type="text" 
                                   id="searchTrainer" 
                                   placeholder="Search by name, specialization, or experience..." 
                                   class="rounded-lg border-gray-300 text-sm focus:ring-red-500 focus:border-red-500 pl-10 pr-4 py-2 min-w-[400px]">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <button id="clearSearch" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400 hover:text-gray-600 hidden">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Trainers Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if ($all_trainers && $all_trainers->num_rows > 0): ?>
                        <?php while($trainer = $all_trainers->fetch_assoc()): ?>
                            <div class="bg-gray-50 rounded-lg p-6 trainer-card h-full flex flex-col" 
                                 data-specialization="<?php echo htmlspecialchars($trainer['specialization']); ?>"
                                 data-name="<?php echo htmlspecialchars($trainer['name']); ?>"
                                 data-bio="<?php echo htmlspecialchars($trainer['bio'] ?? ''); ?>"
                                 data-experience="<?php echo htmlspecialchars($trainer['experience_years']); ?>"
                                 data-classes="<?php echo htmlspecialchars($trainer['class_names'] ?? ''); ?>">
                                <!-- Keep the existing trainer card HTML structure -->
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-span-full text-center py-12">
                            <div class="max-w-md mx-auto">
                                <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500 text-lg">No trainers available at the moment.</p>
                                <p class="text-gray-400 mt-2">Please check back later or contact the front desk.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Enhanced Trainer filtering
        const searchInput = document.getElementById('searchTrainer');
        const clearSearchBtn = document.getElementById('clearSearch');
        const trainerCards = document.querySelectorAll('.trainer-card');
        let searchTimeout;

        function filterTrainers() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            clearSearchBtn.style.display = searchTerm ? 'flex' : 'none';

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Add slight delay to prevent excessive filtering on fast typing
            searchTimeout = setTimeout(() => {
                let visibleCount = 0;

                trainerCards.forEach(card => {
                    const trainerName = card.dataset.name.toLowerCase();
                    const trainerBio = card.dataset.bio.toLowerCase();
                    const specialization = card.dataset.specialization.toLowerCase();
                    const specialties = Array.from(card.querySelectorAll('.bg-red-100')).map(el => el.textContent.toLowerCase());
                    const experience = card.dataset.experience.toLowerCase();
                    const classes = card.dataset.classes.toLowerCase();

                    // Split search term into words for better matching
                    const searchWords = searchTerm.split(/\s+/);
                    const matchesAllWords = searchWords.every(word => 
                        trainerName.includes(word) || 
                        trainerBio.includes(word) ||
                        specialization.includes(word) ||
                        specialties.some(s => s.includes(word)) ||
                        experience.includes(word) ||
                        classes.includes(word)
                    );

                    if (!searchTerm || matchesAllWords) {
                        card.style.display = 'block';
                        visibleCount++;
                        card.style.opacity = '1';
                    } else {
                        card.style.opacity = '0';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 200);
                    }
                });

                // Update or show/hide no results message
                updateNoResultsMessage(visibleCount === 0 && searchTerm);

                // Update results count
                updateResultsCount(visibleCount, searchTerm);
            }, 200);
        }

        function updateNoResultsMessage(show) {
            let noResultsMessage = document.querySelector('.no-results-message');
            
            if (show) {
                if (!noResultsMessage) {
                    const message = document.createElement('div');
                    message.className = 'no-results-message col-span-full text-center py-12 fade-in';
                    message.innerHTML = `
                        <div class="max-w-md mx-auto">
                            <i class="fas fa-search text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-lg">No trainers found matching "${searchInput.value}"</p>
                            <p class="text-gray-400 mt-2">Try different search terms or clear the search</p>
                        </div>
                    `;
                    document.querySelector('.grid').appendChild(message);
                }
            } else if (noResultsMessage) {
                noResultsMessage.remove();
            }
        }

        function updateResultsCount(count, searchTerm) {
            let resultsCount = document.querySelector('.results-count');
            if (!resultsCount) {
                resultsCount = document.createElement('div');
                resultsCount.className = 'results-count text-sm text-gray-500 mt-2';
                searchInput.parentElement.appendChild(resultsCount);
            }

            if (searchTerm) {
                resultsCount.textContent = `Found ${count} trainer${count !== 1 ? 's' : ''}`;
            } else {
                resultsCount.textContent = '';
            }
        }

        function clearSearch() {
            searchInput.value = '';
            filterTrainers();
            searchInput.focus();
        }

        // Event listeners
        searchInput.addEventListener('input', filterTrainers);
        clearSearchBtn.addEventListener('click', clearSearch);

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
            // Escape to clear search when focused
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                clearSearch();
            }
        });

        // Add styles for animations
        const style = document.createElement('style');
        style.textContent = `
            .trainer-card {
                transition: opacity 0.2s ease-in-out;
            }
            .fade-in {
                animation: fadeIn 0.3s ease-in-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);

        // ... Keep the rest of the existing JavaScript code ...
    </script>
</body>
</html> 