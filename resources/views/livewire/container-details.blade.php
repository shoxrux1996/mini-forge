<div style="font-size: .875rem">
    @if ($error !== null)
        <p style="color: var(--danger-600)">{{ $error }}</p>
    @endif

    <dl style="display: grid; grid-template-columns: minmax(7rem, auto) 1fr; gap: .375rem 1rem; margin: 0">
        @foreach ($details as $label => $value)
            <dt style="color: var(--gray-500)">{{ $label }}</dt>
            <dd style="margin: 0; word-break: break-word">{{ $value }}</dd>
        @endforeach
    </dl>
</div>
