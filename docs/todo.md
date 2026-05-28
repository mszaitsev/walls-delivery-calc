# TODO

## Russian Post pickup import

- Проверить и доработать автоматическое скачивание ZIP через API "Отправка" на боевом Linux/VDS сервере.
- Сравнить стабильность:
  - direct cURL backend
  - WordPress HTTP API backend
- Проверить:
  - timeout behavior
  - SSL behavior
  - Action Scheduler interaction
  - background download stability
  - PHP ZipArchive/zip extension behavior on LocalWP and production Linux/VDS
- При необходимости:
  - доработать retry/backoff;
  - добавить CLI import mode;
  - добавить chunked streamed download.
  - добавить импорт из уже распакованного JSON/TXT как fallback для окружений без стабильного ZipArchive.
