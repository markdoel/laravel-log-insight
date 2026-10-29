<div>
    @if ($paginator->hasPages())
        <nav class="li-pagination" role="navigation" aria-label="Pagination">
            @if ($paginator->onFirstPage())
                <span class="li-button" aria-disabled="true">Previous</span>
            @else
                <button type="button" class="li-button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="prev">Previous</button>
            @endif

            <span class="li-muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <button type="button" class="li-button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="next">Next</button>
            @else
                <span class="li-button" aria-disabled="true">Next</span>
            @endif
        </nav>
    @endif
</div>
