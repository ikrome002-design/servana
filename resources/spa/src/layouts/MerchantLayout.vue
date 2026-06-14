<template>
  <div class="flex min-h-screen flex-col bg-bg text-text">
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:ring-2 focus:ring-primary"
    >
      Skip to main content
    </a>
    <header class="border-b border-border bg-surface px-6 py-3">
      <div class="flex items-center justify-between">
        <img
          :src="'/assets/brand/Logo.png'"
          alt="Servana by Citrus"
          class="h-8 w-auto"
        >
        <span class="text-sm font-medium text-text-muted">Merchant Admin</span>
      </div>
    </header>
    <div class="flex flex-1">
      <nav
        aria-label="Merchant navigation"
        class="w-64 shrink-0 border-r border-border bg-surface p-4"
      >
        <!-- Phase 11: final navigation items -->
      </nav>
      <main
        id="main-content"
        class="flex-1 p-6"
      >
        <RouterView />
      </main>
    </div>
  </div>
</template>
