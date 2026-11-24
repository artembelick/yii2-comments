/** Переменная окружения стенда. Нет переменной — тесты не стартуют, а не идут на чужой адрес (ДТ-5). */
export function requireEnv(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === "") {
    throw new Error(`Переменная окружения ${name} не задана. Список переменных — в .env.example.`);
  }

  return value;
}
