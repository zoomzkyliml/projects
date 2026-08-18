"use strict";

(() => {
  const API_URL = "/api.php";
  let loggedIn = false;
  let initialized = false;

  async function request(action, options = {}) {
    const url = new URL(API_URL, window.location.origin);
    url.searchParams.set("action", action);

    const response = await fetch(url.toString(), {
      method: options.method || "GET",
      body: options.body,
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const data = await response.json();

    if (!response.ok) {
      const error = new Error(data.message || `HTTP ${response.status}`);
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data;
  }

  function applyAuthState(isLoggedIn) {
    loggedIn = isLoggedIn === true;

    for (const element of [document.documentElement, document.body]) {
      if (!element) continue;
      element.classList.remove("auth-checking", "is-authenticated", "is-guest");
      element.classList.add(loggedIn ? "is-authenticated" : "is-guest");
    }

    document.querySelectorAll("[data-auth-only]").forEach((element) => {
      element.hidden = !loggedIn;
    });

    document.querySelectorAll("[data-guest-only]").forEach((element) => {
      element.hidden = loggedIn;
    });

    document.querySelectorAll("[data-auth-status]").forEach((element) => {
      element.textContent = loggedIn ? "Adminläge" : "Showoff-läge";
    });

    document.querySelectorAll("[data-auth-toggle]").forEach((element) => {
      const label = loggedIn ? "Logga ut från adminläge" : "Öppna adminläge";
      const textLabel = element.querySelector("[data-auth-toggle-label]");

      if (textLabel) {
        textLabel.textContent = label;
      } else {
        element.textContent = loggedIn ? "Logga ut" : "Adminläge";
      }

      element.setAttribute("aria-pressed", loggedIn ? "true" : "false");
      element.setAttribute("aria-label", label);
      element.setAttribute("title", label);
      element.dataset.authActive = loggedIn ? "true" : "false";
    });

    document.dispatchEvent(new CustomEvent("projects:auth-changed", {
      detail: { loggedIn },
    }));
  }

  async function checkLogin() {
    try {
      const data = await request("auth_check");
      applyAuthState(data.loggedIn === true);
    } catch (error) {
      console.error("Auth-kontrollen misslyckades:", error);
      applyAuthState(false);
    }

    return loggedIn;
  }

  async function login(password) {
    const body = new FormData();
    body.append("password", password);

    try {
      const data = await request("login", { method: "POST", body });
      applyAuthState(data.loggedIn === true);
      return loggedIn;
    } catch (error) {
      if (error.status !== 401) console.error(error);
      applyAuthState(false);
      return false;
    }
  }

  async function logout() {
    try {
      await request("logout", { method: "POST" });
      applyAuthState(false);
      return true;
    } catch (error) {
      console.error(error);
      return false;
    }
  }

  async function handleToggle() {
    if (loggedIn) {
      await logout();
      return;
    }

    const password = prompt("Ange lösenord för adminläge:");
    if (!password) return;

    if (!(await login(password))) {
      alert("Fel lösenord.");
    }
  }

  function bindControls() {
    document.querySelectorAll("[data-auth-toggle]").forEach((button) => {
      if (button.dataset.authBound === "true") return;
      button.dataset.authBound = "true";
      button.addEventListener("click", handleToggle);
    });
  }

  async function init() {
    if (initialized) return loggedIn;
    initialized = true;
    bindControls();
    const result = await checkLogin();

    document.dispatchEvent(new CustomEvent("projects:auth-ready", {
      detail: { loggedIn: result },
    }));

    return result;
  }

  window.ProjectsAuth = Object.freeze({
    init,
    checkLogin,
    login,
    logout,
    isAuthenticated: () => loggedIn,
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    void init();
  }
})();
