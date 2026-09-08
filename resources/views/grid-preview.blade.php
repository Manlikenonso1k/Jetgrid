<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JetGrid — scene preview</title>
    @vite('resources/js/grid-preview/main.jsx')
</head>
<body>
    {{-- The boot marker is replaced the moment React mounts. If it is still on
         screen, the bundle never executed — which is a different fault from the
         scene throwing, and the two are indistinguishable on a blank page. --}}
    <div id="jetgrid-preview">
        <div style="padding:24px;color:#8a97a0;background:#0a0e0a;min-height:100vh;box-sizing:border-box;font:13px/1.6 ui-monospace,Menlo,Consolas,monospace">
            Booting the scene&hellip;
            <br><br>
            If this text is still here, the JavaScript bundle did not execute.
            Open DevTools &rarr; Console and Network.
        </div>
    </div>
</body>
</html>
