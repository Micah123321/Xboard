<script setup lang="ts">
defineProps<{ page: number; total: number }>()
defineEmits<{ change: [page: number] }>()
</script>
<template><el-pagination :current-page="page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="$emit('change', $event)" /></template>
