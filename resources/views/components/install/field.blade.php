@props(['name', 'label', 'type' => 'text', 'value' => '', 'help' => null, 'required' => true])
{{--
    One installer field.

    16px minimum on the input, and 44px minimum height, for the same reason
    every other form in the product has them: an owner is as likely to be
    setting this up on a phone as anywhere else, and iOS zooms a form field
    below 16px.
--}}
<div style="margin-bottom: 1rem;">
    <label for="{{ $name }}" style="display: block; font-size: 0.875rem; color: var(--color-text-muted); margin-bottom: 0.375rem;">
        {{ $label }}
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ old($name, $value) }}" @required($required)
           {{ $attributes }}
           style="width: 100%; min-height: var(--tap-min); box-sizing: border-box;
                  padding: 0.5rem 0.75rem; font-size: max(16px, 1rem);
                  border: 1px solid var(--color-input-border); border-radius: var(--radius-md);
                  background: var(--color-input-bg); color: var(--color-input-text);">
    @if ($help)
        <p style="margin: 0.375rem 0 0; font-size: 0.8125rem; color: var(--color-text-muted);">{{ $help }}</p>
    @endif
</div>
