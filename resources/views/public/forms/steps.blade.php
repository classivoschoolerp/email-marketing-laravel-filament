/* Multi-step paging for a §2.7 form (spec F-2.7-004).

   ONE implementation, included verbatim by the embed loader and by the hosted
   page, so the two delivery shapes can never page a form differently.

   The server renders every step visible; this script is what turns that single
   long form into a paged one. Nothing here is required for the form to WORK —
   with scripting off the visitor fills every step in one go and posts once,
   which the server validates in full. That is the documented degrade.

   Two details that are not obvious:

   1. `required` is moved OFF the inputs of a hidden step and back on when the
      step is shown. A `required` control inside a `display:none` container
      makes browsers block submission on a field the visitor cannot see or
      focus ("An invalid form control is not focusable"), which would make the
      last step unsubmittable. The server still validates every field.
   2. A failed per-step request ADVANCES rather than trapping the visitor. The
      call is a courtesy; the authoritative check is the final submit, and a
      flaky network must not turn a form into a dead end. */
function empFormSteps(form, options) {
    "use strict";

    var STEP_FIELD = @json(\App\Services\Forms\FormRenderer::STEP_FIELD);
    var steps = form.querySelectorAll("[data-emp-step]");

    if (!steps || steps.length < 2) { return; }

    var submit = form.querySelector(".emp-form__submit");
    var errors = form.querySelector(".emp-form__errors");
    var bar = form.querySelector("[data-emp-progress-bar]");
    var label = form.querySelector("[data-emp-progress-label]");
    var current = 0;
    var busy = false;

    function setErrors(text) {
        if (errors) { errors.textContent = text || ""; }
    }

    function messages(bag) {
        var out = [];
        for (var key in bag) {
            if (Object.prototype.hasOwnProperty.call(bag, key)) {
                out.push([].concat(bag[key]).join(" "));
            }
        }
        return out.join(" ");
    }

    function toggleRequired(step, on) {
        var nodes = step.querySelectorAll("[required], [data-emp-required]");
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (on) {
                if (node.getAttribute("data-emp-required") !== null) {
                    node.setAttribute("required", "required");
                    node.removeAttribute("data-emp-required");
                }
            } else if (node.hasAttribute("required")) {
                node.removeAttribute("required");
                node.setAttribute("data-emp-required", "1");
            }
        }
    }

    function show(index) {
        current = index;
        for (var i = 0; i < steps.length; i++) {
            steps[i].style.display = i === index ? "" : "none";
            toggleRequired(steps[i], i === index);
        }
        if (submit) { submit.style.display = index === steps.length - 1 ? "" : "none"; }
        if (bar) { bar.style.width = Math.round(((index + 1) / steps.length) * 100) + "%"; }
        if (label && options.stepOf) {
            label.textContent = String(options.stepOf)
                .replace("{current}", String(index + 1))
                .replace("{total}", String(steps.length));
        }
        setErrors("");
        if (index > 0) {
            var focusable = steps[index].querySelector("input, select, textarea");
            if (focusable) { try { focusable.focus(); } catch (e) { /* not focusable */ } }
        }
    }

    function advance() {
        if (current < steps.length - 1) { show(current + 1); }
    }

    function nativelyValid() {
        var inputs = steps[current].querySelectorAll("input, select, textarea");
        for (var i = 0; i < inputs.length; i++) {
            if (typeof inputs[i].checkValidity === "function" && !inputs[i].checkValidity()) {
                if (typeof inputs[i].reportValidity === "function") { inputs[i].reportValidity(); }
                return false;
            }
        }
        return true;
    }

    function next() {
        if (busy || !nativelyValid()) { return; }

        if (!options.stepUrl || typeof window.fetch !== "function") { advance(); return; }

        busy = true;
        var body = new FormData(form);
        body.append(STEP_FIELD, String(current + 1));

        window.fetch(options.stepUrl, {
            method: "POST",
            body: body,
            headers: { "Accept": "application/json" },
            credentials: "omit",
            mode: "cors"
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
            busy = false;
            if (result.ok && result.data && result.data.ok) { advance(); return; }
            setErrors(messages((result.data && result.data.errors) || {}));
        }).catch(function () {
            busy = false;
            advance();
        });
    }

    function back() {
        if (current > 0) { show(current - 1); }
    }

    var navs = form.querySelectorAll("[data-emp-nav]");
    for (var n = 0; n < navs.length; n++) { navs[n].removeAttribute("hidden"); }

    var nextButtons = form.querySelectorAll("[data-emp-next]");
    for (var a = 0; a < nextButtons.length; a++) { nextButtons[a].addEventListener("click", next); }

    var backButtons = form.querySelectorAll("[data-emp-back]");
    for (var b = 0; b < backButtons.length; b++) { backButtons[b].addEventListener("click", back); }

    show(0);
}
