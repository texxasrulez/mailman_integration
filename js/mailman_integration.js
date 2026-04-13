(function () {
  if (typeof window.rcmail === "undefined") {
    return;
  }

  window.rcmail.addEventListener("init", function () {
    var panels = document.querySelectorAll(".mailman-panel");
    if (!panels.length) {
      return;
    }

    document.body.classList.add("mailman-loaded");

    // Template picker: intercept Send to List clicks and append subject/body from selected template
    document.querySelectorAll(".mailman-send-group").forEach(function (group) {
      var btn = group.querySelector(".mailman-send-btn");
      var picker = group.querySelector(".mailman-template-picker");
      if (!btn || !picker) {
        return;
      }

      btn.addEventListener("click", function (e) {
        var idx = picker.value;
        if (idx === "") {
          return; // no template selected, navigate normally
        }
        e.preventDefault();
        var templates = [];
        try {
          templates = JSON.parse(group.getAttribute("data-templates") || "[]");
        } catch (err) {
          window.top.location.href = btn.href;
          return;
        }
        var tpl = templates[parseInt(idx, 10)];
        var url = btn.getAttribute("href");
        if (tpl) {
          url += "&_subject=" + encodeURIComponent(tpl.subject || "");
          if (tpl.body) {
            url += "&_body=" + encodeURIComponent(tpl.body);
          }
        }
        window.top.location.href = url;
      });
    });
  });
})();
