@php
    $minaId = $item['id'] ?? null;
    $minaName = $item['nombre'] ?? $item['name'] ?? '';
@endphp

@if($minaId)
    <form
        method="POST"
        action="{{ route('catalogos.minas.force-destroy', $minaId) }}"
        class="js-force-delete-mine-form"
        data-mina-name="{{ $minaName }}"
        style="display:inline-block;"
    >
        @csrf
        <input type="hidden" name="confirmacion" value="">
        <button type="submit" class="btn btn-sm btn-outline" style="color:#991B1B; border-color:#EF4444;">
            Eliminar definitivo
        </button>
    </form>
@endif

@once
    @push('scripts')
        <script>
            document.addEventListener('submit', function (event) {
                const form = event.target;
                if (!form.classList || !form.classList.contains('js-force-delete-mine-form')) {
                    return;
                }

                const mineName = form.dataset.minaName || '';
                const message = [
                    'Esta accion eliminara definitivamente la mina y limpiara sus datos de habilitacion minera.',
                    'No se eliminaran trabajadores.',
                    'Para continuar escribe exactamente el nombre de la mina:'
                ].join('\n\n');
                const confirmation = window.prompt(message + '\n\n' + mineName);

                if (confirmation === null || confirmation.trim() !== mineName.trim()) {
                    event.preventDefault();
                    return;
                }

                form.querySelector('input[name="confirmacion"]').value = confirmation.trim();
            });
        </script>
    @endpush
@endonce
