@props([
    'name',
    'label',
    'type' => 'text',
    'required' => false,
    'value' => null,
    'hint' => null,
    'autocomplete' => null,
    'inputmode' => null,
    'enterkeyhint' => null,
    'autofocus' => false,
])
{{--
  Mobile-first form field (Owner Addendum A).
   - inputmode surfaces the right keyboard
   - autocomplete cuts keystrokes and improves completion rates
   - enterkeyhint makes the Enter key say what it does
   - font-size >= 16px, enforced globally, so iOS never zooms on focus
   - errors render inline and are linked via aria-describedby
--}}
@php($id = $name.'-field')
<div class="flex flex-col gap-1.5">
    <label for="{{ $id }}" class="text-sm font-medium text-text">
        {{ $label }}
        @if (! $required)
            <span class="font-normal text-text-muted">({{ __('optional') }})</span>
        @endif
    </label>

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @if($required) required @endif
        @if($autofocus) autofocus @endif
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($inputmode) inputmode="{{ $inputmode }}" @endif
        @if($enterkeyhint) enterkeyhint="{{ $enterkeyhint }}" @endif
        @error($name) aria-invalid="true" @enderror
        aria-describedby="{{ $id }}-help"
        {{ $attributes->merge(['class' => 'w-full rounded-md border bg-surface px-3 text-text placeholder:text-text-muted']) }}
        style="min-height: var(--tap-min); border-color: @error($name) var(--color-danger) @else var(--color-border) @enderror;"
    >

    <p id="{{ $id }}-help" class="text-sm">
        @error($name)
            <span style="color: var(--color-danger);">{{ $message }}</span>
        @else
            <span class="text-text-muted">{{ $hint }}</span>
        @enderror
    </p>
</div>
