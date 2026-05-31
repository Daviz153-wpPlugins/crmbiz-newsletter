import { createApp } from 'vue'
import History from './History.vue'
import '../../css/app.css'

const el = document.getElementById('crmbiz-history-app')
if (el) {
  const app = createApp(History)
  app.config.errorHandler = (err, _instance, info) => {
    console.error('[CRMBiz Newsletter] History 오류:', err, info)
    el.innerHTML = `
      <div style="padding:48px;text-align:center;font-family:-apple-system,sans-serif">
        <p style="font-size:14px;color:#374151;margin:0 0 6px;font-weight:600">발송 이력을 불러오는 중 오류가 발생했습니다.</p>
        <p style="font-size:12px;color:#9ca3af;margin:0">F12 콘솔에서 상세 오류를 확인하세요.</p>
      </div>`
  }
  app.mount(el)
}
