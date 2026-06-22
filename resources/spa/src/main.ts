import { createPinia } from 'pinia';
import { createApp } from 'vue';

import App from '@/App.vue';
import { router } from '@/router';
import { setUnauthorizedHandler } from '@/services/apiClient';
import { useAuthStore } from '@/stores/authStore';
import '@/style.css';

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
