@php
    $ficha = $row['ficha'];
    $personal = $row['personal'];
    $link = $row['link'];
    $correo = $row['correo'] ?? null;
    $emailSentAt = $row['email_sent_at'] ?? null;
    $missingFields = $row['missing_fields'] ?? [];
    $missingDocuments = $row['missing_documents'] ?? [];
    $rowKey = $rowKey ?? $ficha->id;
    $regularizationLinkEnabled = !empty($row['can_regularize']) && !empty($row['url']);
    $statusClass = match($row['estado_key'] ?? $ficha->estado) {
        'LINK_ENVIADO_PENDIENTE' => 'ficha-status-sent',
        'LINK_ENVIADO_VENCIDO' => 'ficha-status-expired',
        'FICHA_ENVIADA' => 'ficha-status-sent',
        'APROBADO' => 'ficha-status-approved',
        'LINK_VENCIDO', 'VENCIDO', 'RECHAZADO' => 'ficha-status-expired',
        default => 'ficha-status-pending',
    };
@endphp
<tr
    class="js-person-card"
    data-row-id="{{ $ficha->id }}"
    data-nombre="{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}"
    data-dni="{{ trim(($ficha->tipo_documento ?? '') . ' ' . ($ficha->numero_documento ?? '')) }}"
    data-puesto="{{ $personal?->puesto ?: 'Puesto pendiente' }}"
    data-contrato="{{ $ficha->macro_tipo_contrato ?: ($personal?->contrato ?: '') }}"
    data-estado-key="{{ $row['estado_key'] ?? $ficha->estado }}"
    data-estado="{{ $row['estado_label'] }}"
    data-correo="{{ $correo ?? '' }}"
    data-has-link="{{ $row['url'] ? '1' : '0' }}"
    data-can-email="{{ ($row['url'] && $correo) ? '1' : '0' }}"
    data-expires-at="{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '' }}"
    data-expires-date="{{ optional($link?->expires_at)->toDateString() ?: '' }}"
    data-celular="{{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '') }}">
    <td>
        <strong>{{ $personal?->nombre_completo ?: 'Trabajador pendiente' }}</strong>
        <div class="ficha-card-subtitle">{{ $personal?->puesto ?: 'Puesto pendiente' }}</div>
        @if($correo)
            <div class="ficha-card-subtitle">{{ $correo }}</div>
        @endif
        @if($emailSentAt)
            <div class="ficha-card-subtitle" style="color:#2563eb; margin-top:4px;">
                Correo enviado: {{ optional($emailSentAt)->format('d/m/Y H:i') }}
            </div>
        @endif
        @if(count($missingFields) > 0 || count($missingDocuments) > 0)
            <div class="ficha-card-subtitle" style="color:#b45309; margin-top:4px;">
                Celular: {{ $personal?->telefono ?: ($ficha->datos_json['telefono'] ?? '-') }}
            </div>
        @endif
    </td>
    <td>{{ $ficha->tipo_documento }} {{ $ficha->numero_documento }}</td>
    <td><span class="ficha-status {{ $statusClass }}">{{ $row['estado_label'] }}</span></td>
    <td>{{ optional($link?->expires_at)->format('d/m/Y H:i') ?: '-' }}</td>
    <td>
        @if($row['url'])
            <div class="ficha-link-box">
                <input id="temporalLink{{ $rowKey }}" class="ficha-input" type="text" value="{{ $row['url'] }}" readonly>
                <button type="button" class="btn btn-primary js-copy-ficha-link" data-target="temporalLink{{ $rowKey }}">Copiar</button>
            </div>
        @elseif(!empty($row['can_regularize']))
            <span class="ficha-card-subtitle">Link no habilitado todavia. Presiona "Habilitar link temporal".</span>
        @endif
    </td>
    <td>
        <div class="temporal-action-buttons">
            <a
                href="{{ route('personal.fichas.review', $ficha->id) }}"
                class="btn {{ $ficha->estado === 'FICHA_ENVIADA' ? 'btn-primary' : 'btn-outline' }} btn-xs temporal-icon-btn"
                title="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}"
                aria-label="{{ $ficha->estado === 'FICHA_ENVIADA' ? 'Validar / activar ficha' : 'Ver ficha' }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <path d="M14 2v6h6"/>
                    <path d="M16 13H8"/>
                    <path d="M16 17H8"/>
                    <path d="M10 9H8"/>
                </svg>
            </a>
            @if($correo && $row['url'])
                <button type="button"
                    class="btn btn-outline btn-xs js-send-email temporal-icon-btn"
                    data-send-url="{{ route('personal.fichas.send-email', $ficha->id) }}"
                    data-idle-title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                    title="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}"
                    aria-label="{{ $emailSentAt ? 'Volver a enviar correo' : 'Enviar al correo' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16v16H4z"/>
                        <path d="m22 6-10 7L2 6"/>
                    </svg>
                </button>
            @else
                <button type="button" class="btn btn-outline btn-xs temporal-icon-btn" disabled title="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" aria-label="{{ $correo ? 'No se encontró un link recuperable o aun no fue habilitado' : 'No se encontró correo' }}" style="opacity:.55; cursor:not-allowed;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16v16H4z"/>
                        <path d="m22 6-10 7L2 6"/>
                    </svg>
                </button>
            @endif
            @allowed('personal', 'eliminar')
                @if($row['url'] && $link && !$ficha->submitted_at)
                    <form method="POST" action="{{ route('personal.fichas.extend', $ficha->id) }}" class="js-temporal-action-form" data-action-name="ampliado">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-xs temporal-icon-btn" title="Ampliar 3 dias" aria-label="Ampliar 3 dias">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                        </button>
                    </form>
                @endif
                @if(!empty($row['can_regularize']))
                    <form method="POST" action="{{ route('personal.fichas.regularize-link', $ficha->id) }}" class="js-temporal-action-form" data-action-name="link temporal habilitado">
                        @csrf
                        <button
                            type="submit"
                            class="btn btn-outline btn-xs temporal-icon-btn"
                            title="{{ $regularizationLinkEnabled ? 'Link temporal ya habilitado' : 'Habilitar link temporal' }}"
                            aria-label="{{ $regularizationLinkEnabled ? 'Link temporal ya habilitado' : 'Habilitar link temporal' }}"
                            @disabled($regularizationLinkEnabled)>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="7" cy="15" r="4"/>
                                <path d="M7 13v4"/>
                                <path d="M5 15h4"/>
                                <path d="M14 7h7"/>
                                <path d="M14 12h5"/>
                            </svg>
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('personal.fichas.destroy', $ficha->id) }}" class="js-temporal-action-form" data-remove-row="true" onsubmit="return confirm('Se eliminara este registro de Temporales y links, pero el trabajador seguira en Personal.');">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-xs temporal-icon-btn" title="Quitar de Temporales y links" aria-label="Quitar de Temporales y links">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18"/>
                            <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                            <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                            <path d="M10 11v6"/>
                            <path d="M14 11v6"/>
                        </svg>
                    </button>
                </form>
            @endallowed
        </div>
    </td>
</tr>
