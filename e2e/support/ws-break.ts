import { once } from "node:events";

import type { Page, WebSocketRoute } from "@playwright/test";

/** Код закрытия «разрыв без закрывающего кадра»: так клиент видит обрыв сети или падение прокси. */
const ABNORMAL_CLOSURE = 1006;

/** Управление перехваченным WS страницы админки (E2E-08). */
export interface WsBreak {
  /** Рвёт текущее соединение со стороны страницы кодом 1006. Серверная половина закрывается обычным кадром. */
  breakConnection: () => Promise<void>;
  /** Отпускает удержанные переподключения: они уходят к серверу, кадры снова идут как есть. */
  releaseReconnect: () => void;
}

/**
 * Перехват WS админки через `page.routeWebSocket` (e2e.md, E2E-08). Первое соединение сразу идёт к серверу,
 * кадры проходят в обе стороны без изменений. Следующие соединения удерживаются до `releaseReconnect()`: всё,
 * что тест сделал до этого, произошло, пока клиент не подключён, и события об этом до клиента не дошли.
 * Вызывается до открытия страницы.
 */
export async function interceptAdminWs(page: Page): Promise<WsBreak> {
  const connections: WebSocketRoute[] = [];
  // Ворота для переподключений: обещание создаётся сразу, поэтому release до первого переподключения не теряется.
  const gate = new EventTarget();
  const released = once(gate, "release");
  await page.routeWebSocket(/\/ws$/u, async (route) => {
    connections.push(route);
    if (connections.length > 1) {
      await released;
    }
    const server = route.connectToServer();
    // Обработчик закрытия отключает пересылку по умолчанию: она закрыла бы сервер тем же кодом 1006,
    // А его браузер отправить не может. Сервер закрывается обычным кадром и забывает соединение.
    route.onClose(() => {
      void server.close();
    });
  });

  return {
    breakConnection: async (): Promise<void> => {
      const current = connections.at(-1);
      if (current === undefined) {
        throw new Error("Страница ещё не открыла WS: перехват ставится до открытия админки");
      }
      await current.close({ code: ABNORMAL_CLOSURE, reason: "E2E-08: разрыв" });
    },
    releaseReconnect: (): void => {
      gate.dispatchEvent(new Event("release"));
    },
  };
}
