/* Email marketing platform — form embed loader (spec F-2.7-011).
   Self-contained: no framework, no globals beyond window.EMPForms, safe to
   include more than once on the same page. Display rules (F-2.7-005) and
   frequency rules (F-2.7-006) are evaluated here because they are properties
   of the VISITOR's browsing session, which the server never sees. */
(function () {
    "use strict";

    var CONFIG = {!! $payload !!};

    window.EMPForms = window.EMPForms || { loaded: {} };
    if (window.EMPForms.loaded[CONFIG.uid]) { return; }
    window.EMPForms.loaded[CONFIG.uid] = true;

    var STORE = "emp_form_" + CONFIG.uid;
    var VISITOR = "emp_visitor";
    var DAY = 86400000;

    function readJson(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) { return fallback; }
    }

    function writeJson(key, value) {
        try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ }
    }

    /* ---- visitor profile: page views + new vs returning (F-2.7-005) ---- */
    var visitor = readJson(VISITOR, null);
    var isNewVisitor = !visitor;
    if (!visitor) { visitor = { firstSeen: Date.now(), views: 0 }; }
    visitor.views = (visitor.views || 0) + 1;
    writeJson(VISITOR, visitor);

    var state = readJson(STORE, { shown: 0, closedAt: 0, submittedAt: 0 });

    /* ---- frequency rules (F-2.7-006) ---------------------------------- */
    function frequencyAllows() {
        var f = CONFIG.frequency;
        if (f.hide_after_submit && state.submittedAt) {
            if (f.hide_after_submit_days === 0) { return false; }
            if (Date.now() - state.submittedAt < f.hide_after_submit_days * DAY) { return false; }
        }
        if (state.closedAt && f.hide_days_after_close > 0) {
            if (Date.now() - state.closedAt < f.hide_days_after_close * DAY) { return false; }
        }
        if (f.max_shows_per_visitor > 0 && (state.shown || 0) >= f.max_shows_per_visitor) { return false; }
        return true;
    }

    /* ---- display rules (F-2.7-005) ------------------------------------ */
    function deviceBucket() {
        var ua = (navigator.userAgent || "").toLowerCase();
        if (/ipad|tablet/.test(ua) || (/android/.test(ua) && !/mobi/.test(ua))) { return "tablet"; }
        if (/mobi|iphone|ipod|android/.test(ua)) { return "mobile"; }
        return "desktop";
    }

    function pageAllows() {
        var d = CONFIG.display;
        if (d.pages_mode === "all" || !d.pages.length) { return true; }
        var here = window.location.pathname + window.location.search;
        var full = window.location.href;
        for (var i = 0; i < d.pages.length; i++) {
            var want = String(d.pages[i]);
            if (d.pages_mode === "exact") {
                if (here === want || full === want || window.location.pathname === want) { return true; }
            } else if (full.indexOf(want) !== -1 || here.indexOf(want) !== -1) { return true; }
        }
        return false;
    }

    function targetingAllows() {
        var d = CONFIG.display;
        if (!pageAllows()) { return false; }
        if (d.devices.length && d.devices.indexOf(deviceBucket()) === -1) { return false; }
        if (d.visitors === "new" && !isNewVisitor) { return false; }
        if (d.visitors === "returning" && isNewVisitor) { return false; }
        if (d.min_page_views > 0 && visitor.views < d.min_page_views) { return false; }
        return true;
    }

    if (!targetingAllows() || !frequencyAllows()) { return; }

    /* ---- multi-step paging (F-2.7-004) --------------------------------- */
    @include('public.forms.steps')

    /* ---- DOM ----------------------------------------------------------- */
    var host = null;
    var shown = false;

    function styleTag() {
        var style = document.createElement("style");
        style.setAttribute("data-emp-form", CONFIG.uid);
        style.appendChild(document.createTextNode(CONFIG.css));
        document.head.appendChild(style);
    }

    function overlayStyles(node) {
        var s = node.style;
        s.position = "fixed";
        s.zIndex = "2147483000";
        switch (CONFIG.type) {
            case "slide_in":
                s.right = "20px"; s.bottom = "20px"; s.maxWidth = "92vw";
                break;
            case "floating_bar":
                s.left = "0"; s.right = "0"; s.bottom = "0"; s.maxWidth = "100%"; s.width = "100%";
                break;
            case "full_screen":
                s.top = "0"; s.left = "0"; s.right = "0"; s.bottom = "0";
                s.display = "flex"; s.alignItems = "center"; s.justifyContent = "center";
                s.background = CONFIG.styles.overlay_color; s.padding = "24px";
                break;
            default: /* popup + exit_intent */
                s.top = "0"; s.left = "0"; s.right = "0"; s.bottom = "0";
                s.display = "flex"; s.alignItems = "center"; s.justifyContent = "center";
                s.background = "rgba(15,23,42,.55)"; s.padding = "24px";
        }
    }

    function build() {
        var wrapper = document.createElement("div");
        var form = document.createElement("form");
        form.className = "emp-form emp-form-" + CONFIG.scope;
        form.setAttribute("method", "post");
        form.setAttribute("action", CONFIG.submitUrl);
        form.setAttribute("accept-charset", "UTF-8");
        form.innerHTML = CONFIG.body;
        form.style.position = "relative";

        if (CONFIG.type !== "embedded") {
            var close = document.createElement("button");
            close.type = "button";
            close.className = "emp-form-" + CONFIG.scope + "-close";
            close.setAttribute("aria-label", CONFIG.strings.close);
            close.innerHTML = "&times;";
            close.onclick = function () { dismiss(); };
            form.appendChild(close);
        }

        var sourceInput = form.querySelector('input[name="emp_source"]');
        if (sourceInput) { sourceInput.value = document.referrer || "direct"; }
        var pageInput = form.querySelector('input[name="emp_page_url"]');
        if (pageInput) { pageInput.value = window.location.href; }

        form.addEventListener("submit", onSubmit);

        empFormSteps(form, { stepUrl: CONFIG.stepUrl, stepOf: CONFIG.strings.stepOf });

        if (CONFIG.type === "embedded") {
            wrapper = form;
        } else {
            overlayStyles(wrapper);
            wrapper.appendChild(form);
            wrapper.addEventListener("click", function (event) {
                if (event.target === wrapper) { dismiss(); }
            });
        }
        return wrapper;
    }

    function mount() {
        if (shown) { return; }
        shown = true;
        styleTag();
        host = build();

        var slot = document.querySelector('[data-emp-form="' + CONFIG.uid + '"]');
        if (CONFIG.type === "embedded" && slot) {
            slot.appendChild(host);
        } else if (CONFIG.type === "embedded" && CONFIG.anchor && CONFIG.anchor.parentNode) {
            CONFIG.anchor.parentNode.insertBefore(host, CONFIG.anchor);
        } else {
            document.body.appendChild(host);
        }

        state.shown = (state.shown || 0) + 1;
        writeJson(STORE, state);
        beacon();
    }

    function dismiss() {
        if (host && host.parentNode) { host.parentNode.removeChild(host); }
        state.closedAt = Date.now();
        writeJson(STORE, state);
    }

    function beacon() {
        try {
            var body = new FormData();
            body.append("source", document.referrer || "direct");
            body.append("page_url", window.location.href);
            /* F-2.7-013: credentials are omitted, so the assignment cookie
               never reaches us — the arm has to travel in the beacon. */
            if (CONFIG.variant) { body.append("emp_variant", CONFIG.variant); }
            fetch(CONFIG.viewUrl, { method: "POST", body: body, credentials: "omit", mode: "cors", keepalive: true })
                .catch(function () {});
        } catch (e) { /* analytics must never break the page */ }
    }

    /* ---- submit (F-2.7-008 success handling) --------------------------- */
    function onSubmit(event) {
        event.preventDefault();
        var form = event.currentTarget;
        var errors = form.querySelector(".emp-form__errors");
        if (errors) { errors.textContent = ""; }

        fetch(CONFIG.submitUrl, {
            method: "POST",
            body: new FormData(form),
            headers: { "Accept": "application/json" },
            credentials: "omit",
            mode: "cors"
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
            if (!result.ok || !result.data.ok) {
                if (errors) {
                    var messages = [];
                    var bag = result.data.errors || {};
                    for (var key in bag) {
                        if (Object.prototype.hasOwnProperty.call(bag, key)) {
                            messages.push([].concat(bag[key]).join(" "));
                        }
                    }
                    errors.textContent = messages.join(" ") || (result.data.message || CONFIG.strings.error);
                }
                return;
            }

            state.submittedAt = Date.now();
            writeJson(STORE, state);

            if (result.data.mode === "redirect" && result.data.redirect) {
                window.location.href = result.data.redirect;
                return;
            }

            if (result.data.mode === "download" && result.data.download) {
                window.open(result.data.download, "_blank", "noopener");
            }

            form.innerHTML = '<div class="emp-form__success" style="padding:8px 0;font-size:15px"></div>';
            form.querySelector(".emp-form__success").textContent = result.data.message || CONFIG.strings.success;
            if (CONFIG.type !== "embedded") {
                window.setTimeout(function () { if (host && host.parentNode) { host.parentNode.removeChild(host); } }, 4000);
            }
        }).catch(function () {
            if (errors) { errors.textContent = CONFIG.strings.error; }
        });
    }

    /* ---- triggers ------------------------------------------------------ */
    function arm() {
        var d = CONFIG.display;
        var armed = false;

        if (d.exit_intent) {
            armed = true;
            document.addEventListener("mouseout", function handler(event) {
                if (event.clientY <= 0 && !event.relatedTarget) {
                    document.removeEventListener("mouseout", handler);
                    mount();
                }
            });
        }

        if (d.scroll_percent > 0) {
            armed = true;
            var onScroll = function () {
                var doc = document.documentElement;
                var height = Math.max(1, (doc.scrollHeight || 1) - window.innerHeight);
                var percent = ((window.pageYOffset || doc.scrollTop || 0) / height) * 100;
                if (percent >= d.scroll_percent) {
                    window.removeEventListener("scroll", onScroll);
                    mount();
                }
            };
            window.addEventListener("scroll", onScroll, { passive: true });
        }

        if (d.delay_seconds > 0) {
            armed = true;
            window.setTimeout(mount, d.delay_seconds * 1000);
        }

        if (!armed) { mount(); }
    }

    CONFIG.anchor = document.currentScript;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", arm);
    } else {
        arm();
    }
})();
