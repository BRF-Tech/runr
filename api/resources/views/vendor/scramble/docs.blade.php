{{--
    The API reference (GET /api/docs): the Scalar viewer reads the OpenAPI document from
    /api/docs.json, which the API image builds (App\Support\ApiDocument); this page carries no copy.
    The viewer's script is Scalar's standalone bundle, which the API image downloads into
    public/api/vendor/scalar/ (api/Dockerfile.prod: SCALAR_VERSION, SCALAR_SHA256). A checkout
    without it (development) loads the same version from jsDelivr, checked by its integrity.
    Not id="api-reference": the bundle mounts its legacy viewer on that element by itself, which
    left the page at its loading skeleton. No AI chat, MCP generator, developer toolbar or
    telemetry: they send the document or usage to Scalar's service.
--}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $config->get('ui.title', config('app.name') . ' - API Docs') }}</title>
</head>
<body>
    <div id="app"></div>
    @if (is_file(public_path('api/vendor/scalar/api-reference.js')))
        <script src="/api/vendor/scalar/api-reference.js"></script>
    @else
        <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.75.1/dist/browser/standalone.js"
                integrity="sha384-G+2Q2i9l3PPfmYkk0b4BAIUrs7xb/DNaTL1wCYYab4c9dIKlCyiqzC0Qx3eq9Wf6"
                crossorigin="anonymous"></script>
    @endif
    <script>
        Scalar.createApiReference('#app', {
            url: '/api/docs.json',
            theme: 'deepSpace',
            darkMode: true,
            documentDownloadType: 'none',
            servers: [{ url: window.location.origin + '/api' }],
            agent: { disabled: true },
            mcp: { disabled: true },
            showDeveloperTools: 'never',
            telemetry: false,
        })
    </script>
</body>
</html>
