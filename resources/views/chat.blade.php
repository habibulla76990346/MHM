{{-- Chat fills the shell, so the layout's usual page padding and max-width
     would fight it — hence `wide` and a component that manages its own
     scrolling regions. --}}
<x-layouts.app :title="__('Chat')" wide>
    <livewire:chat />
</x-layouts.app>
