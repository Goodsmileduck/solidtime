<script setup lang="ts">
import GoogleLoginButton from '@/Components/GoogleLoginButton.vue';
</script>

<template>
    <template v-if="$page.props.googleOAuthEnabled">
        <GoogleLoginButton />
        <div class="flex items-center my-6">
            <div class="flex-grow border-t border-border-secondary"></div>
            <span class="px-3 text-xs text-text-secondary uppercase">or</span>
            <div class="flex-grow border-t border-border-secondary"></div>
        </div>
    </template>
</template>
