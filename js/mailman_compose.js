(function () {
  if (typeof window.rcmail === "undefined") {
    return;
  }

  var lastPayload = { recognized: [] };

  function env(name, fallback) {
    if (typeof window.rcmail === "undefined" || !window.rcmail.env) {
      return fallback;
    }

    if (Object.prototype.hasOwnProperty.call(window.rcmail.env, name)) {
      return window.rcmail.env[name];
    }

    return fallback;
  }

  function labels() {
    return env("mailman_owner_tools_labels", {}) || {};
  }

  function showMessage(text, type) {
    if (window.rcmail && typeof window.rcmail.display_message === "function") {
      window.rcmail.display_message(text, type || "notice", 5000);
    }
  }

  function collectRecipients() {
    var values = [];
    ["_to", "_cc", "_bcc"].forEach(function (id) {
      var field = document.getElementById(id);
      if (field && field.value) {
        values.push(field.value);
      }
    });

    return values.join(",");
  }

  function renderWidget(payload) {
    var widget = document.getElementById("mailman-compose-widget");
    var body = widget ? widget.querySelector(".mailman-compose-widget__content") : null;
    if (!payload || !payload.recognized || !payload.recognized.length) {
      if (widget && body) {
        widget.classList.add("hidden");
        body.textContent = widget.getAttribute("data-empty-label") || "";
      }
      lastPayload = { recognized: [] };
      toggleOwnerTools(false);
      updateOwnerTarget();
      return;
    }

    lastPayload = payload;
    toggleOwnerTools(true);
    updateOwnerTarget();
    if (!widget || !body) {
      return;
    }

    widget.classList.remove("hidden");
    body.textContent = "";

    payload.recognized.forEach(function (item) {
      var row = document.createElement("div");
      row.className = "mailman-compose-widget__item";

      var title = document.createElement("strong");
      title.textContent = item && item.name ? String(item.name) : "";
      row.appendChild(title);

      var address = document.createElement("span");
      address.textContent = item && item.address ? String(item.address) : "";
      row.appendChild(address);

      if (item && item.archive_url) {
        var href = String(item.archive_url);
        if (/^https?:\/\//i.test(href)) {
          var link = document.createElement("a");
          link.href = href;
          link.target = "_blank";
          link.rel = "noopener";
          link.textContent = "Archives";
          row.appendChild(link);
        }
      }

      body.appendChild(row);
    });
  }

  function getToolsContainer() {
    return document.getElementById("mailman-compose-owner-tools");
  }

  function toggleOwnerTools(show) {
    var tools = getToolsContainer();
    if (!tools) {
      return;
    }

    if (!env("mailman_owner_tools_enabled", true)) {
      tools.classList.add("hidden");
      return;
    }

    if (show) {
      tools.classList.remove("hidden");
      return;
    }

    tools.classList.add("hidden");
  }

  function getPrimaryListContext() {
    var recognized = (lastPayload && lastPayload.recognized) || [];
    if (!recognized.length) {
      return null;
    }

    return recognized[0];
  }

  function updateOwnerTarget() {
    var target = document.getElementById("mailman-owner-target");
    if (!target) {
      return;
    }

    var recognized = (lastPayload && lastPayload.recognized) || [];
    if (!recognized.length) {
      target.textContent = "";
      return;
    }

    var prefix = labels().target_prefix || "Sending to:";
    var names = recognized.map(function (item) {
      return item.name || item.address || "mailing list";
    });

    target.textContent = prefix + " " + names.join(", ");
  }

  function positionOwnerToolsAfterFrom() {
    var tools = getToolsContainer();
    var fromField = document.getElementById("_from");
    if (!tools || !fromField) {
      return;
    }

    var parent = fromField.parentNode;
    if (!parent) {
      return;
    }

    tools.classList.add("mailman-compose-owner-tools--inline");

    if (tools.parentNode !== parent || tools.previousSibling !== fromField) {
      parent.insertBefore(tools, fromField.nextSibling);
    }
  }

  function replaceTemplateVars(text, listCtx) {
    if (!text) {
      return "";
    }

    var senderName = env("username", "");
    var safe = String(text);
    var listName = listCtx && listCtx.name ? String(listCtx.name) : "";
    var listAddress = listCtx && listCtx.address ? String(listCtx.address) : "";

    return safe
      .replace(/\{list_name\}/g, listName)
      .replace(/\{list_address\}/g, listAddress)
      .replace(/\{sender_name\}/g, senderName);
  }

  function getComposerBody() {
    var editor = window.tinyMCE && window.tinyMCE.activeEditor;
    if (editor && typeof editor.getContent === "function" && typeof editor.setContent === "function") {
      return {
        get: function () {
          return editor.getContent({ format: "text" });
        },
        set: function (text) {
          editor.setContent(String(text).replace(/\n/g, "<br>"));
        },
      };
    }

    var field = document.getElementById("composebody");
    if (!field) {
      return null;
    }

    return {
      get: function () {
        return field.value || "";
      },
      set: function (text) {
        field.value = text;
      },
    };
  }

  function getSubjectField() {
    return document.getElementById("compose-subject") || document.getElementById("subject") || document.querySelector("input[name='_subject']");
  }

  function setDefaultOptions() {
    var requireSubject = document.getElementById("mailman-opt-require-subject");
    var confirmSend = document.getElementById("mailman-opt-confirm-send");
    var appendFooter = document.getElementById("mailman-opt-append-footer");

    if (requireSubject) {
      requireSubject.checked = !!env("mailman_preflight_require_subject", true);
    }
    if (confirmSend) {
      confirmSend.checked = !!env("mailman_preflight_confirm_send", true);
    }
    if (appendFooter) {
      appendFooter.checked = !!env("mailman_preflight_append_unsubscribe_footer", false);
    }
  }

  function appendFooterIfNeeded() {
    var appendFooter = document.getElementById("mailman-opt-append-footer");
    if (!appendFooter || !appendFooter.checked) {
      return;
    }

    var listCtx = getPrimaryListContext();
    if (!listCtx) {
      return;
    }

    var footerTemplate = env("mailman_unsubscribe_footer_template", "");
    if (!footerTemplate) {
      return;
    }

    var composerBody = getComposerBody();
    if (!composerBody) {
      return;
    }

    var footer = replaceTemplateVars(footerTemplate, listCtx).trim();
    if (!footer) {
      return;
    }

    var current = composerBody.get();
    if (String(current).indexOf(footer) !== -1) {
      return;
    }

    composerBody.set(String(current).replace(/\s*$/, "") + "\n" + footer + "\n");
  }

  function isListCompose() {
    var recognized = (lastPayload && lastPayload.recognized) || [];
    return recognized.length > 0;
  }

  function runPreflight() {
    if (!isListCompose()) {
      return true;
    }

    var requireSubject = document.getElementById("mailman-opt-require-subject");
    var confirmSend = document.getElementById("mailman-opt-confirm-send");

    var subjectRequired = !!(requireSubject && requireSubject.checked);
    var sendConfirm = !!(confirmSend && confirmSend.checked);

    if (subjectRequired) {
      var subjectField = getSubjectField();
      if (!subjectField || !String(subjectField.value || "").trim()) {
        showMessage(labels().preflight_missing_subject || "Subject is required for list send.", "error");
        if (subjectField && typeof subjectField.focus === "function") {
          subjectField.focus();
        }
        return false;
      }
    }

    appendFooterIfNeeded();

    if (sendConfirm) {
      var listNames = ((lastPayload && lastPayload.recognized) || []).map(function (item) {
        return item.name || item.address || "mailing list";
      });
      var msg = labels().preflight_confirm_send || "Send this message to the selected list recipients?";
      msg += "\n\n" + listNames.join(", ");
      if (!window.confirm(msg)) {
        return false;
      }
    }

    return true;
  }

  function bindPreflight() {
    var form = document.getElementById("composeform") || document.querySelector("form[name='form']");
    if (!form) {
      return;
    }

    form.addEventListener("submit", function (event) {
      var actionField = form.querySelector("input[name='_action']");
      if (actionField && actionField.value && actionField.value !== "send") {
        return;
      }

      if (!runPreflight()) {
        event.preventDefault();
      }
    });
  }

  function lookup() {
    if (!window.rcmail.env.mailman_compose_lookup_enabled) {
      return;
    }

    var recipients = collectRecipients();
    var url = window.rcmail.env.mailman_compose_lookup_url;

    if (!url) {
      return;
    }

    if (!recipients) {
      renderWidget({ recognized: [] });
      return;
    }

    fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: "_emails=" + encodeURIComponent(recipients) + "&_token=" + encodeURIComponent(window.rcmail.env.request_token || ""),
      credentials: "same-origin",
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        renderWidget(payload);
        positionOwnerToolsAfterFrom();
      })
      .catch(function () {
        renderWidget({ recognized: [] });
        positionOwnerToolsAfterFrom();
      });
  }

  window.rcmail.addEventListener("init", function () {
    setDefaultOptions();
    updateOwnerTarget();
    positionOwnerToolsAfterFrom();

    bindPreflight();

    ["_to", "_cc", "_bcc"].forEach(function (id) {
      var field = document.getElementById(id);
      if (field) {
        field.addEventListener("change", lookup);
        field.addEventListener("blur", lookup);
      }
    });

    lookup();
  });
})();
