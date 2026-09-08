<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Refused — JetGrid</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            background: #fff; color: #111827;
            font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            padding: 1.5rem;
        }
        .card { max-width: 34rem; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1.5rem 1.75rem; border-left: 4px solid #17800f; }
        h1 { font-size: 1.05rem; margin: 0 0 .5rem; }
        p { margin: 0 0 .75rem; }
        .detail { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: #6b7280; background: #f9fafb; border-radius: .4rem; padding: .6rem .7rem; word-break: break-word; }
        a { color: #17800f; }
    </style>
</head>
<body>
    <div class="card">
        <h1>JetGrid refused this operation</h1>
        <p>{{ $reason }}</p>
        <p class="detail">{{ $detail }}</p>
        <p><a href="{{ url('/admin') }}">Back to the panel</a></p>
    </div>
</body>
</html>
