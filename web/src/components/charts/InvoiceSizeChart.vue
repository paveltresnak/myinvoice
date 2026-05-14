<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import {
  Chart, BarController, BarElement, CategoryScale, LinearScale, Tooltip,
} from 'chart.js'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps<{
  buckets: Array<{ key: string; label: string; count: number; total_czk: number }>
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

const palette = ['#A99CD8', '#6753AE', '#3B2D83', '#15131D']

function formatCzk(n: number): string {
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M Kč'
  if (n >= 1_000) return (n / 1_000).toFixed(0) + 'k Kč'
  return n.toFixed(0) + ' Kč'
}

function build() {
  if (!canvas.value) return
  if (chart) chart.destroy()

  chart = new Chart(canvas.value, {
    type: 'bar',
    data: {
      labels: props.buckets.map(b => b.label),
      datasets: [{
        data: props.buckets.map(b => b.count),
        backgroundColor: props.buckets.map((_, i) => palette[i] ?? '#5C45A0'),
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#15131D',
          callbacks: {
            label: (ctx) => {
              const b = props.buckets[ctx.dataIndex]
              return ` ${b.count} faktur · ${formatCzk(b.total_czk)}`
            },
          },
        },
      },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0, color: '#7A748C', font: { size: 11 } }, grid: { color: '#E7E3EE' } },
        x: { ticks: { color: '#7A748C', font: { size: 11 } }, grid: { display: false } },
      },
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.buckets, build, { deep: true })
</script>

<template>
  <div class="relative h-56"><canvas ref="canvas"></canvas></div>
</template>
