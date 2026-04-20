<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                {{ $thead }}
            </tr>
        </thead>
        <tbody>
            {{ $tbody }}
        </tbody>
    </table>
    
    @isset($empty)
        @if($empty)
            <div class="table-empty">
                {{ $emptyMessage ?? 'No hay datos' }}
            </div>
        @endif
    @endif
</div>