<x-layouts.app :title="$page->title" :page="$page" wide>
    <article class="flex flex-col">
        @foreach ($page->sections as $section)
            @continue (! $section->is_visible)
            @continue (! \App\Domains\Content\Support\SectionType::exists($section->type))

            {{-- Dynamic component, but only ever one of the declared section
                 types — SectionType is a closed set, so a payload cannot name
                 an arbitrary view. --}}
            <x-dynamic-component :component="'sections.'.$section->type" :section="$section" />
        @endforeach
    </article>
</x-layouts.app>
