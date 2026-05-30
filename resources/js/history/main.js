import { createApp } from 'vue'
import History from './History.vue'
import '../../css/app.css'

const el = document.getElementById('crmbiz-history-app')
if (el) createApp(History).mount(el)
