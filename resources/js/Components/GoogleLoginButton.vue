<script setup lang="ts">
const label = 'Continue with Google';
</script>

<template>
    <a
        :href="route('auth.google.redirect')"
        class="flex items-center justify-center gap-3 w-full px-4 py-2 border border-border-secondary rounded-lg bg-white text-gray-700 text-sm font-medium hover:bg-gray-50 transition">
        <svg class="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
            <path
                fill="#EA4335"
                d="M12 10.2v3.9h5.5c-.24 1.4-1.7 4.1-5.5 4.1-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.9 3.1 14.7 2 12 2 6.9 2 2.8 6.1 2.8 12S6.9 22 12 22c6 0 9.3-4.2 9.3-9.2 0-.6-.1-1-.2-1.6H12z" />
        </svg>
        <span>{{ label }}</span>
    </a>
</template>
