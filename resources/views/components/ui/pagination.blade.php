{{-- Default paginator view (registered in AppServiceProvider). Works for {{ $rows->links() }}. --}}
@if ($paginator->hasPages())
    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Pagination">
        <p class="muted">
            Showing <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $paginator->firstItem() }}</span>
            to <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $paginator->total() }}</span>
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="muted rounded-md px-2 py-1 opacity-50" aria-disabled="true">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rounded-md px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">Previous</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="muted px-1.5" aria-hidden="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="rounded-md bg-zinc-900 px-2.5 py-1 font-medium text-white dark:bg-zinc-100 dark:text-zinc-900" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="rounded-md px-2.5 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rounded-md px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">Next</a>
            @else
                <span class="muted rounded-md px-2 py-1 opacity-50" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
