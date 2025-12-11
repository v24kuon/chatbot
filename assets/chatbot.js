(()=> {
  function init(el) {
    const dataset = el.dataset.dataset;
    const messages = el.querySelector('.chatbot-messages');
    const input = el.querySelector('input');
    const sendBtn = el.querySelector('.send');
    const toggle = el.querySelector('.chatbot-toggle');
    if (toggle) {
      toggle.addEventListener('click', () => {
        el.classList.toggle('open');
      });
    } else {
      el.classList.add('open');
    }
    const append = (text, role) => {
      const div = document.createElement('div');
      div.className = 'msg ' + role;
      div.textContent = text;
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    };
    const send = () => {
      const q = input.value.trim();
      if (!q) return;
      append(q, 'user');
      input.value = '';
      sendBtn.disabled = true;
      fetch(ChatbotConfig.api, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({dataset, question: q, page_url: window.location.href})
      })
      .then(r => r.json())
      .then(data => {
        if (data && data.code) {
          append(data.message || 'エラーが発生しました', 'bot');
        } else {
          append(data.answer || '回答を取得できませんでした', 'bot');
        }
      })
      .catch(()=> append('エラーが発生しました', 'bot'))
      .finally(()=> sendBtn.disabled = false);
    };
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', e => {
      // Avoid sending while IME composition is active (Japanese input).
      if (e.key !== 'Enter') return;
      if (e.isComposing) return;
      e.preventDefault();
      send();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.chatbot-container').forEach(init);
  });
})();
