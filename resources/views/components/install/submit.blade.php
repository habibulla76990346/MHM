@props(['label' => 'Continue'])
<button type="submit"
        style="display: inline-flex; align-items: center; justify-content: center; min-height: var(--tap-min);
               min-width: var(--tap-min); padding-inline: 1.25rem; border-radius: var(--radius-md);
               font-weight: 500; border: 1px solid var(--color-primary);
               background: var(--color-primary); color: var(--color-text-inverse);">
    {{ $label }}
</button>
