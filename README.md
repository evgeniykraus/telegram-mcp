# Postiz MCP — Social Media Analytics & Competitive Intelligence Server

**`postiz-mcp`** — это полнофункциональный автономный MCP-сервер (Model Context Protocol) на стеке **Laravel 13 / PostgreSQL 16 / Redis / MadelineProto (PHP MTProto)**, созданный для конкурентной разведки в Telegram, аналитики вовлеченности (ER, реакции, форварды), таймслотов и контент-стратегии для AI-агентов (Claude Desktop, Cursor, Antigravity, Claude Code, Cline, Windsurf).

---

## 📋 Содержание

- [Возможности MCP-сервера](#-возможности-mcp-сервера)
- [Стек технологий](#-стек-технологий)
- [Быстрый старт и установка](#-быстрый-старт-и-установка)
- [Авторизация в Telegram (MadelineProto)](#-авторизация-в-telegram-madelineproto)
- [Сбор данных через консоль](#-сбор-данных-через-консоль)
- [Подключение MCP-сервера к AI-клиентам](#-подключение-mcp-сервера-к-ai-клиентам)
  - [Claude Desktop](#1-claude-desktop)
  - [Cursor IDE](#2-cursor-ide)
  - [Claude Code CLI / Antigravity / Gemini](#3-claude-code-cli--antigravity)
  - [Cline / Roo Code / Windsurf](#4-cline--roo-code--windsurf)
- [Каталог MCP Tools (8 инструментов)](#-каталог-mcp-tools)
- [Каталог MCP Resources (2 ресурса)](#-каталог-mcp-resources)
- [Отладка и Тестирование](#-отладка-и-тестирование)

---

## 🚀 Возможности MCP-сервера

- **Парсинг и глубокий сбор**: сбор публикаций Telegram-каналов через MTProto без ограничений Bot API, с сохранением детальной разбивки реакций по эмодзи (`👍`, `🔥`, `❤️` и др.), пересылок, комментариев и медиа-типов.
- **Детекция рекламы**: эвристический фильтр рекламных публикаций (`erid`, маркировка, токены, промокоды).
- **Метрика `content_score`**: взвешенная оценка эффективности контента на основе ER, виральности (forwards) и общего охвата.
- **Динамика и кинетика постов**: расчет `velocity` (скорость набора просмотров в час) и `acceleration` (ускорение) по снапшотам.
- **Тепловая карта день × час**: выявление лучших временных окон для постинга на основе исторических данных.
- **Бенчмаркинг ниши**: сравнение собственных каналов с конкурентами по охватам и вовлеченности.

---

## 🛠 Стек технологий

- **PHP 8.5 / Laravel 13**
- **PostgreSQL 16** + **`pgvector`** (база данных)
- **Redis Alpine** (кэширование и очереди)
- **Фоновый Worker очередей** (сервис `queue` в `compose.yaml`)
- **`laravel/mcp`** (реализация протокола MCP для Laravel)
- **`danog/madelineproto` & `php-core/laravel-madeline-proto`** (асинхронный MTProto клиент Telegram на чистом PHP)
- **Laravel Sail** (Docker-окружение)
- **PHPUnit 12** (автоматизированное тестирование)

---

## 📦 Быстрый старт и установка

### 1. Клонирование и настройка окружения

```bash
git clone <repository_url> postiz-mcp
cd postiz-mcp

# Создание .env файла
cp .env.example .env
```

### 2. Запуск контейнеров Sail

```bash
# Запуск PostgreSQL и Redis в фоне
vendor/bin/sail up -d

# Установка зависимостей
vendor/bin/sail composer install

# Генерация ключа приложения
vendor/bin/sail artisan key:generate

# Выполнение миграций БД
vendor/bin/sail artisan migrate
```

---

## 🔑 Авторизация в Telegram (MadelineProto)

Для сбора данных публичных каналов через MTProto требуется один раз авторизовать сессию Telegram:

1. Получите `api_id` и `api_hash` на [my.telegram.org/apps](https://my.telegram.org/apps).
2. Добавьте их в ваш `.env`:
   ```env
   MP_TELEGRAM_API_ID=12345678
   MP_TELEGRAM_API_HASH=your_api_hash_here
   MP_SESSION_FILE=session.madeline
   ```
3. Запустите интерактивную команду авторизации:
   ```bash
   vendor/bin/sail artisan madeline-proto:login
   ```
4. Введите номер телефона, SMS-код подтверждения и (если включен) пароль 2FA. Сессия сохранится в `storage/app/telegram/session.madeline`.

---

## 📥 Сбор данных через консоль

Вы можете запустить сбор публикаций вручную через Artisan:

```bash
# Синхронный сбор последних 100 постов канала @durov
vendor/bin/sail artisan postiz:collect --channels=@durov --limit=100

# Сбор постов нескольких каналов в фоновую очередь (Redis)
vendor/bin/sail artisan postiz:collect --channels=@durov,@telegram --limit=200 --since-days=30 --queue

# Фоновая генерация векторных эмбеддингов (bge-m3) для постов без векторов
vendor/bin/sail artisan postiz:embed --limit=100
```

*Сбор каналов также автоматически настроен в планировщике (`routes/console.php`) на ежедневный запуск в 06:00.*

---

## 🔌 Подключение MCP-сервера к AI-клиентам

Сервер `postiz-mcp` работает через **stdio** транспорт с помощью команды `php artisan mcp:start analytics`.

### 1. Claude Desktop

Откройте файл конфигурации Claude Desktop:
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

Добавьте секцию `postiz-analytics`:

#### Вариант A (через Laravel Sail / Docker):
```json
{
  "mcpServers": {
    "postiz-analytics": {
      "command": "/usr/local/bin/docker",
      "args": [
        "compose",
        "-f",
        "/Users/evgen/PhpstormProjects/postiz-mcp/compose.yaml",
        "exec",
        "-T",
        "laravel.test",
        "php",
        "artisan",
        "mcp:start",
        "analytics"
      ]
    }
  }
}
```

#### Вариант B (локальный запуск на хосте с установленным PHP):
```json
{
  "mcpServers": {
    "postiz-analytics": {
      "command": "php",
      "args": [
        "/Users/evgen/PhpstormProjects/postiz-mcp/artisan",
        "mcp:start",
        "analytics"
      ],
      "env": {
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "5432",
        "DB_DATABASE": "laravel",
        "DB_USERNAME": "sail",
        "DB_PASSWORD": "password",
        "EMBEDDING_MODEL": "bge-m3",
        "EMBEDDING_DIMENSION": "1024"
      }
    }
  }
}
```

---

### 2. Cursor IDE

1. Перейдите в **Settings** → **Features** → **MCP**.
2. Нажмите **+ Add New MCP Server**.
3. Заполните поля:
   - **Name**: `postiz-analytics`
   - **Type**: `command`
   - **Command**: `php /Users/evgen/PhpstormProjects/postiz-mcp/artisan mcp:start analytics`

Либо создайте файл `.cursor/mcp.json` в корне вашего проекта:
```json
{
  "mcpServers": {
    "postiz-analytics": {
      "command": "php",
      "args": [
        "/Users/evgen/PhpstormProjects/postiz-mcp/artisan",
        "mcp:start",
        "analytics"
      ]
    }
  }
}
```

---

### 3. Claude Code CLI / Antigravity

Файл `.mcp.json` уже настроен в корне проекта:
```json
{
    "mcpServers": {
        "postiz-analytics": {
            "command": "vendor/bin/sail",
            "args": [
                "artisan",
                "mcp:start",
                "analytics"
            ]
        }
    }
}
```

---

### 4. Cline / Roo Code / Windsurf

В файле конфигурации расширения (например, `cline_mcp_settings.json`):
```json
{
  "mcpServers": {
    "postiz-analytics": {
      "command": "php",
      "args": [
        "/Users/evgen/PhpstormProjects/postiz-mcp/artisan",
        "mcp:start",
        "analytics"
      ]
    }
  }
}
```

---

## 🛠 Каталог MCP Tools

| Инструмент | Параметры | Описание |
|---|---|---|
| `collect_channels` | `channels` (string, req), `limit` (int), `since_days` (int) | Поставить задачу на сбор постов указанных каналов в очередь |
| `channel_report` | `channel` (string, req), `days` (int) | Получить полный аналитический срез по каналу (подписчики, средний ER, лучший пост, форварды, реакции) |
| `top_posts` | `limit` (int), `channel` (string), `since_days` (int), `format` (string), `sort_by` (enum) | Топ лучших постов ниши, отсортированных по `content_score`, `er`, `views` или `forwards` |
| `format_breakdown` | `channel` (string), `days` (int) | Статистика эффективности по форматам контента (текст, фото, видео, опрос, ссылки) |
| `best_time_to_post` | `channel` (string), `days` (int) | Тепловая карта день × час и рекомендации лучших временных окон для публикаций |
| `post_history` | `channel` (string, req), `external_id` (string, req) | История метрик поста, скорость набора просмотров в час (`velocity`) и ускорение |
| `benchmark` | `category` (string), `days` (int) | Сравнение своих каналов (`is_own = true`) с конкурентами в нише |
| `report` | `channel` (string), `days` (int) | Сводный исполнительный отчет (KPI, топ-посты, форматы, таймслоты) |
| `search_similar_posts` | `query_or_post_id` (string/int, req), `limit` (int), `channel` (string) | Семантический векторный поиск похожих публикаций через эмбеддинги `bge-m3` и `pgvector` |

---

## 📚 Каталог MCP Resources

| URI | MIME Type | Описание |
|---|---|---|
| `analytics://channels` | `application/json` | Список всех отслеживаемых каналов и их метаданные |
| `analytics://publishing/best-times` | `application/json` | Срез лучших таймслотов для публикаций за 30 дней |

---

## 🧪 Отладка и Тестирование

### Интерактивная отладка через MCP Inspector

Laravel MCP включает встроенный инспектор:
```bash
vendor/bin/sail artisan mcp:inspector analytics
```

### Запуск автоматических тестов

```bash
# Запуск полного набора PHPUnit тестов
vendor/bin/sail artisan test --compact

# Форматирование кода по стандартам проекта
vendor/bin/sail bin pint --dirty --format agent
```

---

## 📄 Лицензия

Проект распространяется под лицензией [MIT](LICENSE). Библиотека `danog/madelineproto` используется под лицензией AGPL-3.0.

