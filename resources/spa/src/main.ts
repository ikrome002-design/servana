import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from '@/App.vue';
import { initAccountContext } from '@/host/accountHostContext';
import { createRouterForCurrentHost } from '@/router';
import { setUnauthorizedHandler } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import '@/style.css';

// Resolve the server-provided account host context BEFORE the app or the router exists,
// so no account-entry decision can be made without it (Phase UI-02; ADR-016/017). It is
// presentation context only and never affects authorization.
initAccountContext();

/*
 * ORDER IS THE POINT (Phase UI-08 Increment 7B).
 *
 * The router is built AFTER the account context is resolved, and only then, because it registers
 * exactly one account's route tree. A module-level `import { router }` would have constructed it
 * at import time — before `initAccountContext()` had run — which is why the router previously had
 * to carry all eight accounts at once and why the Super Administrator's canonical paths could not
 * be registered: `/audit`, `/dashboard`, `/account` and `/reports` collide across accounts.
 *
 * The host still decides nothing about authority. It selects which experience is mounted; every
 * protected route re-checks the server, and the server is the boundary (ADR-017).
 */
const router = createRouterForCurrentHost();

const app = createApp(App).use(createPinia()).use(router);

// On a mid-session revocation (a 401 on a protected call, Plan §79 R6) clear the
// client auth state and return to login. Loop-safe: the apiClient excludes the
// bootstrap/auth endpoints, and we only navigate when not already on login.
setUnauthorizedHandler(() => {
  useAuthStore().$reset();
  if (router.currentRoute.value.name !== 'auth.login') {
    void router.push({ name: 'auth.login' });
  }
});

app.mount('#app');
