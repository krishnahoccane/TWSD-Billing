// Alpine.js is bundled with Livewire 3 and started by @livewireScripts.
// This file only registers small, reusable Alpine behaviours.

document.addEventListener('alpine:init', () => {
    // Toast stack. Trigger from anywhere:
    //   window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: 'Saved' } }))
    // or from Livewire: $this->dispatch('toast', type: 'success', message: 'Saved');
    window.Alpine.data('toasts', (initial = []) => ({
        items: [],
        nextId: 1,
        init() {
            initial.forEach((toast) => this.push(toast));
        },
        push({ type = 'info', message = '', timeout = 6000 }) {
            if (!message) return;
            const id = this.nextId++;
            this.items.push({ id, type, message });
            if (timeout > 0) setTimeout(() => this.dismiss(id), timeout);
        },
        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    }));

    window.Alpine.data('themeToggle', () => ({
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try {
                localStorage.setItem('wcc-theme', this.dark ? 'dark' : 'light');
            } catch (e) {
                // Storage unavailable (private mode): the choice lasts for this page only.
            }
        },
    }));
});
