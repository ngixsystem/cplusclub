<script setup lang="ts">
withDefaults(defineProps<{ width?: number }>(), { width: 132 });
</script>
<template>
  <span class="brand-image" :style="{ width: width + 'px' }">
    <img class="brand-on-dark" :src="'/brand/cplusclub.png'" alt="C+club" width="2048" height="690" />
    <img class="brand-on-light" :src="'/brand/cplusclub-light.png'" alt="C+club" width="2048" height="690" />
  </span>
</template>
<style>
.brand-image { display:inline-flex; align-items:center; max-width:100%; vertical-align:middle }
.brand-image img { display:block; width:100%; height:auto }
.brand-image .brand-on-light { display:none }
:root[data-theme=light] .brand-image .brand-on-dark { display:none }
:root[data-theme=light] .brand-image .brand-on-light { display:block }
</style>
