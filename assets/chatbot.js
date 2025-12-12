/**
 * Gemini Chatbot - ES2025 Vanilla JavaScript
 */

class GeminiChatbotUI {
  /** @type {HTMLElement} */
  #container;
  /** @type {HTMLElement} */
  #log;
  /** @type {HTMLInputElement} */
  #input;
  /** @type {HTMLButtonElement} */
  #button;
  /** @type {AbortController | null} */
  #abortController = null;

  /**
   * @param {HTMLElement} container
   */
  constructor(container) {
    this.#container = container;
    this.#log = container.querySelector('.chat-log');
    this.#input = container.querySelector('.chat-question');
    this.#button = container.querySelector('.chat-send');

    this.#bindEvents();
  }

  #bindEvents() {
    this.#button.addEventListener('click', () => this.#sendQuestion());

    this.#input.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        this.#sendQuestion();
      }
    });
  }

  /**
   * メッセージを吹き出し形式で追加
   * @param {'bot' | 'user'} type
   * @param {string} text
   */
  #appendMessage(type, text) {
    const avatar = type === 'bot' ? '🤖' : '👤';
    const safeText = this.#escapeHtml(text);

    const row = document.createElement('div');
    row.className = `chat-row ${type}`;
    row.innerHTML = `
      <div class="chat-avatar">${avatar}</div>
      <div class="chat-bubble">${safeText}</div>
    `;

    this.#log.append(row);
    this.#scrollToBottom();
  }

  /**
   * HTMLエスケープ
   * @param {string} text
   * @returns {string}
   */
  #escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * タイピングインジケーターを表示
   */
  #showTypingIndicator() {
    this.#hideTypingIndicator();

    const row = document.createElement('div');
    row.className = 'chat-row typing';
    row.innerHTML = `
      <div class="chat-avatar">🤖</div>
      <div class="typing-indicator">
        <span></span>
        <span></span>
        <span></span>
      </div>
    `;

    this.#log.append(row);
    this.#scrollToBottom();
  }

  /**
   * タイピングインジケーターを非表示
   */
  #hideTypingIndicator() {
    this.#log.querySelector('.chat-row.typing')?.remove();
  }

  /**
   * チャットログを最下部にスクロール
   */
  #scrollToBottom() {
    this.#log.scrollTo({
      top: this.#log.scrollHeight,
      behavior: 'smooth',
    });
  }

  /**
   * UI の有効/無効を切り替え
   * @param {boolean} busy
   */
  #setBusy(busy) {
    this.#input.disabled = busy;
    this.#button.disabled = busy;
  }

  /**
   * 質問を送信
   */
  async #sendQuestion() {
    const question = this.#input.value.trim();
    if (!question) return;

    // ユーザーメッセージを表示 & 入力欄クリア
    this.#appendMessage('user', question);
    this.#input.value = '';

    // UI無効化 & ローディング表示
    this.#setBusy(true);
    this.#showTypingIndicator();

    try {
      const answer = await this.#fetchAnswer(question);
      this.#hideTypingIndicator();
      this.#appendMessage('bot', answer);
    } catch (error) {
      this.#hideTypingIndicator();
      const message = error instanceof Error ? error.message : '通信に失敗しました';
      this.#appendMessage('bot', message);
    } finally {
      this.#setBusy(false);
      this.#input.focus();
    }
  }

  /**
   * APIから回答を取得
   * @param {string} question
   * @returns {Promise<string>}
   */
  async #fetchAnswer(question) {
    // 前のリクエストがあればキャンセル
    this.#abortController?.abort();
    this.#abortController = new AbortController();

    const formData = new FormData();
    formData.append('action', 'chatbot_ask');
    formData.append('nonce', window.GeminiChatbot?.nonce ?? '');
    formData.append('q', question);

    const response = await fetch(window.GeminiChatbot?.ajaxUrl ?? '', {
      method: 'POST',
      body: formData,
      signal: this.#abortController.signal,
    });

    if (!response.ok) {
      throw new Error('通信に失敗しました');
    }

    const result = await response.json();

    if (result?.success) {
      return result.data?.answer ?? '回答なし';
    }

    throw new Error(result?.data?.message ?? 'エラーが発生しました');
  }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.gemini-chatbot').forEach((container) => {
    new GeminiChatbotUI(container);
  });
});
