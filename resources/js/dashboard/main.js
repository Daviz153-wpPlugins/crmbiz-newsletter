import { createApp } from 'vue'
import Dashboard from './Dashboard.vue'
import '../../css/app.css'

const el = document.getElementById('crmbiz-dashboard-app')
if (el) createApp(Dashboard).mount(el)
