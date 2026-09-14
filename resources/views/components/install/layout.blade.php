{{--
    The installer's own shell.

    IT CANNOT USE THE APPLICATION LAYOUT. That layout reads the theme, which
    reads the database — and the database is the thing this wizard is being
    used to configure. A layout that queries a connection that does not exist
    yet turns step one into a stack trace.

    So the styling here is self-contained and deliberately plain, and the only
    colours it uses are the design tokens that ship in the compiled stylesheet
    (Rule 1). No hex, no Tailwind palette class.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Install' }} — Aziv AI</title>
    @vite(['resources/css/app.css'])
</head>
<body style="background: var(--color-background); color: var(--color-text); margin: 0;">
    <main style="max-width: 44rem; margin: 0 auto; padding: 2rem 1rem 4rem;">
        <header style="margin-bottom: 1.5rem;">
            <p style="font-size: 0.875rem; color: var(--color-text-muted); margin: 0 0 0.25rem;">Aziv AI setup</p>
            <h1 style="font-size: var(--text-fluid-xl); font-weight: 600; color: var(--color-heading); margin: 0;">
                {{ $title ?? 'Install' }}
            </h1>
        </header>

        {{-- Where you are, in words rather than a bar nobody can read. --}}
        <ol style="display: flex; flex-wrap: wrap; gap: 0.5rem; list-style: none; padding: 0; margin: 0 0 1.5rem;">
            @foreach (['requirements' => 'Server', 'database' => 'Database', 'application' => 'Site', 'run' => 'Install', 'administrator' => 'Your account'] as $key => $label)
                @php $current = request()->routeIs('install.'.$key.'*'); @endphp
                <li style="font-size: 0.8125rem; padding: 0.25rem 0.625rem; border-radius: var(--radius-md);
                           border: 1px solid var(--color-border);
                           {{ $current ? 'background: var(--color-primary); color: var(--color-text-inverse); border-color: var(--color-primary);' : 'color: var(--color-text-muted);' }}">
                    {{ $loop->iteration }}. {{ $label }}
                </li>
            @endforeach
        </ol>

        @if ($errors->any())
            <div role="alert" style="border: 1px solid var(--color-danger); background: var(--color-danger-bg);
                        color: var(--color-danger); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
                @foreach ($errors->all() as $message)
                    <p style="margin: 0 0 0.25rem;">{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <div style="border: 1px solid var(--color-border); background: var(--color-surface);
                    border-radius: var(--radius-lg); padding: 1.5rem;">
            {{ $slot }}
        </div>

        <p style="margin-top: 1.5rem; font-size: 0.8125rem; color: var(--color-text-muted);">
            Prefer the command line? <code>php artisan migrate --force</code> and
            <code>php artisan aziv:admin:create</code> do the same thing, and are the documented route
            wherever you have shell access.
        </p>
    </main>
</body>
</html>
