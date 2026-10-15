<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $config->get('ui.title', config('app.name') . ' - API Docs') }}</title>
</head>
<body>
    <script
        id="api-reference"
        data-url="/api/docs.json"
    ></script>
    <script>
        document.getElementById('api-reference').dataset.configuration = JSON.stringify({
            theme: 'deepSpace',
            darkMode: true,
            documentDownloadType: 'none',
            servers: [{ url: window.location.origin + '/api' }],
        })
    </script>
    <script src="/api/vendor/scalar/api-reference.js"></script>
</body>
</html>
