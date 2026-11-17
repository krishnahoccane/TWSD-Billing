@props(['initial' => []])
{{-- Message text is rendered with x-text, never as HTML. --}}
<div x-data="toasts(@js($initial))" @toast.window="push($event.detail)"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-4"
     aria-live="polite">
    <template x-for="toast in items" :key="toast.id">
        <div x-transition
             class="surface pointer-events-auto flex w-full items-start gap-2.5 px-3 py-2.5 text-sm shadow-lg sm:w-80">
            <span class="mt-1 size-2 shrink-0 rounded-full"
                  :class="{
                      'bg-emerald-500': toast.type === 'success',
                      'bg-red-500': toast.type === 'error',
                      'bg-amber-500': toast.type === 'warning',
                      'bg-accent-500': toast.type === 'info',
                  }"></span>
            <p class="min-w-0 flex-1" x-text="toast.message"></p>
            <button type="button" class="muted rounded p-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    @click="dismiss(toast.id)" aria-label="Dismiss">
                <x-ui.icon name="x" class="size-3.5" />
            </button>
        </div>
    </template>
</div>
