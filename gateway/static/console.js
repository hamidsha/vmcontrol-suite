(function () {
  "use strict";

  var notice = document.getElementById("notice");
  var connectionBadge = document.getElementById("connectionBadge");
  var connectionState = document.getElementById("connectionState");
  var wmks = null;
  var widgetMode = false;
  var actionBusy = false;

  function setPowerButtons(powerState, safeActionsAvailable) {
    document.getElementById("powerOn").disabled = actionBusy || powerState !== "poweredOff";
    document.getElementById("reboot").disabled = actionBusy || powerState !== "poweredOn" || !safeActionsAvailable;
    document.getElementById("shutdown").disabled = actionBusy || powerState !== "poweredOn" || !safeActionsAvailable;
  }

  function refreshPowerState() {
    return window.fetch("/console/status", {credentials: "same-origin", cache: "no-store"})
      .then(function (response) {
        if (!response.ok) { throw new Error("status"); }
        return response.json();
      })
      .then(function (data) { setPowerButtons(data.power_state || "unknown", !!data.safe_actions_available); })
      .catch(function () { setPowerButtons("unknown", false); });
  }

  function requestAction(action, confirmation) {
    if (actionBusy || !window.confirm(confirmation)) { return; }
    actionBusy = true;
    setPowerButtons("busy", false);
    notice.textContent = "درخواست در حال ارسال است…";
    notice.className = "";
    window.fetch("/console/action", {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: {"Content-Type": "application/json", "Accept": "application/json"},
      body: JSON.stringify({action: action})
    }).then(function (response) {
      if (!response.ok) { throw new Error("action"); }
      notice.textContent = "درخواست با موفقیت ارسال شد.";
      notice.className = "";
      window.setTimeout(function () {
        actionBusy = false;
        refreshPowerState();
      }, 2500);
    }).catch(function () {
      actionBusy = false;
      fail("اجرای عملیات ممکن نشد. وضعیت سرور را بررسی کنید یا دوباره تلاش کنید.");
      refreshPowerState();
    });
  }

  function fail(message) {
    notice.textContent = message;
    notice.className = "error";
    connectionBadge.className = "connection-badge error";
    connectionState.textContent = "اتصال قطع است";
  }

  function connected() {
    notice.className = "connected";
    connectionBadge.className = "connection-badge connected";
    connectionState.textContent = "متصل و امن";
  }

  function connectionURL() {
    var scheme = location.protocol === "https:" ? "wss" : "ws";
    return scheme + "://" + location.host + "/console/ws";
  }

  function invoke(method, argument) {
    if (!wmks) {
      return;
    }
    if (widgetMode) {
      if (typeof argument === "undefined") {
        wmks.wmks(method);
      } else {
        wmks.wmks(method, argument);
      }
      return;
    }
    if (typeof wmks[method] === "function") {
      if (typeof argument === "undefined") {
        wmks[method]();
      } else {
        wmks[method](argument);
      }
    }
  }

  function stateChanged(event, data) {
    if (!window.WMKS || !data) {
      return;
    }
    if (data.state === WMKS.CONST.ConnectionState.CONNECTED) {
      connected();
      return;
    }
    if (data.state === WMKS.CONST.ConnectionState.DISCONNECTED) {
      fail("ارتباط کنسول قطع شد. برای اتصال مجدد از پنل سرویس وارد شوید.");
    }
  }

  function startWidget(options) {
    widgetMode = true;
    wmks = window.jQuery("#wmksContainer")
      .wmks(options)
      .on("wmksconnecting", function () {
        notice.textContent = "در حال برقراری اتصال امن…";
        notice.className = "";
        connectionBadge.className = "connection-badge connecting";
        connectionState.textContent = "در حال اتصال";
      })
      .on("wmksconnected", connected)
      .on("wmksdisconnected", function () {
        fail("ارتباط کنسول قطع شد. برای اتصال مجدد از پنل سرویس وارد شوید.");
      })
      .on("wmkserror wmksiniterror", function () {
        fail("برقراری ارتباط با کنسول ناموفق بود. دوباره از پنل سرویس تلاش کنید.");
      });
    invoke("connect", connectionURL());
  }

  function startFactory(options) {
    wmks = WMKS.createWMKS("wmksContainer", options)
      .register(WMKS.CONST.Events.CONNECTION_STATE_CHANGE, stateChanged);
    invoke("connect", connectionURL());
  }

  function start() {
    var options = {
      changeResolution: true,
      rescale: true,
      fitToParent: true,
      useUnicodeKeyboardInput: true
    };

    try {
      if (window.WMKS && typeof WMKS.createWMKS === "function") {
        startFactory(options);
        return;
      }
      if (window.jQuery && typeof window.jQuery.fn.wmks === "function") {
        startWidget(options);
        return;
      }
      fail("فایل رسمی WebMKS روی Gateway نصب نشده یا بارگذاری نشد.");
    } catch (error) {
      fail("راه‌اندازی کنسول در مرورگر ناموفق بود.");
      if (window.console && typeof window.console.error === "function") {
        window.console.error("VMControl console initialization failed", error);
      }
    }
  }

  document.getElementById("sendCad").addEventListener("click", function () {
    invoke("sendKeyCodes", [17, 18, 46]);
  });
  document.getElementById("powerOn").addEventListener("click", function () {
    requestAction("power_on", "سرور روشن شود؟");
  });
  document.getElementById("reboot").addEventListener("click", function () {
    requestAction("reboot", "سرور از داخل سیستم‌عامل راه‌اندازی مجدد شود؟");
  });
  document.getElementById("shutdown").addEventListener("click", function () {
    requestAction("shutdown", "سرور به‌صورت امن خاموش شود؟");
  });
  document.getElementById("fullscreen").addEventListener("click", function () {
    var element = document.documentElement;
    if (element.requestFullscreen) {
      element.requestFullscreen();
    }
  });
  document.getElementById("disconnect").addEventListener("click", function () {
    invoke("disconnect");
    window.close();
  });
  window.addEventListener("load", function () {
    setPowerButtons("unknown", false);
    refreshPowerState();
    start();
  });
})();
