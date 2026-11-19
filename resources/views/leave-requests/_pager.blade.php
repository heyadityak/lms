@if ($requests->lastPage() > 1)
    <nav aria-label="Pagination" class="pager">
        @if ($requests->previousPageUrl()) <a href="{{ $requests->previousPageUrl() }}" rel="prev">Previous</a> @endif
        <span>Page {{ $requests->currentPage() }} of {{ $requests->lastPage() }}</span>
        @if ($requests->nextPageUrl()) <a href="{{ $requests->nextPageUrl() }}" rel="next">Next</a> @endif
    </nav>
@endif
