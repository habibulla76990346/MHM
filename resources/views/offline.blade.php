{{--
    The page shown when the network is gone.

    SELF-CONTAINED ON PURPOSE. It is served from the service worker cache with
    no server behind it, so it can reference nothing that has to be generated —
    no theme query, no branding lookup, no compiled asset that might be a
    different hash by the time it is shown.

    The colours are therefore literal here and ONLY here, and the
    hard-coded-colour gate excludes this one file by name for exactly that
    reason. It also uses both `prefers-color-scheme` values so it does not
    glare at somebody in the dark.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You are offline</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font: 400 16px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #ffffff;
            color: #1f2933;
        }
        main { max-width: 26rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 0.75rem; }
        p { margin: 0 0 1.5rem; opacity: 0.75; }
        button {
            min-height: 44px;
            padding-inline: 1.5rem;
            font: inherit;
            font-weight: 500;
            border-radius: 8px;
            border: 1px solid currentColor;
            background: transparent;
            color: inherit;
            cursor: pointer;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #10151c; color: #e6eaf0; }
        }
    </style>
</head>
<body>
    <main>
        <h1>You are offline</h1>
        <p>
            Aziv AI needs a connection to answer — it talks to an AI provider for every reply.
            Your conversations are safe and will be here when you are back.
        </p>
        <button type="button" onclick="location.reload()">Try again</button>
    </main>
</body>
</html>
