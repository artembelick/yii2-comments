// @ts-check

/** @typedef {"online" | "reconnecting" | "offline"} WsStatus */

/**
 * Индикатор связи с WS-сервером в шапке списка: текст для человека и data-state для стилей.
 *
 * @param {{ element: HTMLElement, messages: Record<WsStatus, string> }} dependencies
 * @returns {{ set(state: WsStatus): void }}
 */
export function createStatusIndicator({ element, messages }) {
  return {
    set(state) {
      element.textContent = messages[state];
      element.dataset["state"] = state;
    },
  };
}
