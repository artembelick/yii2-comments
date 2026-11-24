// @ts-check
// Отчёт Allure 3, его собирает make report. Истории прогонов нет (historyPath не задан): каждый прогон на main — свежий отчёт.
import { defineConfig } from "allure";

export default defineConfig({
  name: "Система комментариев",
  plugins: {
    awesome: {
      options: {
        reportName: "Система комментариев",
        reportLanguage: "ru",
        // Группы задаёт tools/report: набор по префиксу ID (unit, smoke, regression, e2e), внутри — ID.
        groupBy: ["parentSuite", "suite", "subSuite"],
        defaultSortBy: "name,asc",
      },
    },
  },
});
