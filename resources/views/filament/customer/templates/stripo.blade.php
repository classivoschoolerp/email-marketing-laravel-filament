<x-filament-panels::page>
    {{-- §2.5.3 F-2.5.3-003 embedded Stripo plugin (WP-26).

         Stripo's documented plugin protocol:
           1. the server exchanged pluginId + secretKey for a short-lived
              token (StripoDriver::authToken) — the secret key never reaches
              this page;
           2. stripo.js is loaded from Stripo's CDN and Stripo.init(...) is
              called with the two container ids, the current HTML/CSS, a
              stable emailId and a getAuthToken callback that hands over the
              token from step 1;
           3. StripoApi.compileEmail(...) returns the inlined HTML, which is
              POSTed to the normal builder autosave endpoint so the template
              is stored and revisioned like any other.

         Without credentials the page is unreachable (the driver reports
         unavailable and EditWithStripo::mount 403s). --}}

    <script type="application/json" id="stripo-config">@json($stripo)</script>

    <div wire:ignore class="rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900">
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-2 dark:border-gray-700">
            <span id="stripo-status" class="min-w-16 text-xs text-gray-500 dark:text-gray-400"></span>
            <span class="flex-1"></span>
            <button type="button" id="stripo-save"
                    class="fi-btn rounded-md px-2 py-1 text-sm text-gray-700 ring-1 ring-gray-300 dark:text-gray-200 dark:ring-gray-600">
                {{ __('templates.stripo.save') }}
            </button>
        </div>

        <div class="grid gap-0 md:grid-cols-[320px_1fr]">
            <div id="stripoSettingsContainer" style="min-height: 75vh;"></div>
            <div id="stripoPreviewContainer" style="min-height: 75vh;"></div>
        </div>
    </div>

    <script>
        (function () {
            var configEl = document.getElementById('stripo-config');
            var statusEl = document.getElementById('stripo-status');

            if (!configEl) return;

            var config = JSON.parse(configEl.textContent);
            var csrf = @json($csrfToken);

            var setStatus = function (text) {
                if (statusEl) statusEl.textContent = text;
            };

            var boot = function () {
                if (!window.Stripo) {
                    setStatus(@json(__('templates.stripo.load_failed')));
                    return;
                }

                window.Stripo.init({
                    settingsId: config.settingsId,
                    previewId: config.previewId,
                    codeEditorButtonId: 'stripo-code-editor',
                    undoButtonId: 'stripo-undo',
                    redoButtonId: 'stripo-redo',
                    locale: config.locale,
                    html: config.html,
                    css: config.css,
                    apiRequestData: config.apiRequestData,
                    userFullName: config.userFullName,
                    // Step 2 of the documented flow: hand over the token the
                    // server obtained. Never the secret key.
                    getAuthToken: function (callback) {
                        callback(config.token);
                    },
                });
            };

            var script = document.createElement('script');
            script.id = 'stripoScript';
            script.type = 'text/javascript';
            script.src = config.scriptUrl;
            script.onload = boot;
            script.onerror = function () {
                setStatus(@json(__('templates.stripo.load_failed')));
            };
            document.head.appendChild(script);

            document.getElementById('stripo-save').addEventListener('click', function () {
                if (!window.StripoApi) {
                    setStatus(@json(__('templates.stripo.load_failed')));
                    return;
                }

                setStatus(@json(__('templates.builder_page.saving')));

                // Step 3: compiled (inlined) HTML → the normal autosave route.
                window.StripoApi.compileEmail(function (error, html) {
                    if (error) {
                        setStatus(@json(__('templates.builder_page.save_failed')));
                        return;
                    }

                    var body = new FormData();
                    body.append('content_html', html);

                    fetch(config.saveUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        body: body,
                    })
                        .then(function (res) { return res.ok ? res.json() : Promise.reject(res); })
                        .then(function () { setStatus(@json(__('templates.builder_page.saved'))); })
                        .catch(function () { setStatus(@json(__('templates.builder_page.save_failed'))); });
                });
            });
        })();
    </script>
</x-filament-panels::page>
