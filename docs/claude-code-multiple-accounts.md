# Несколько аккаунтов Claude Code на одном устройстве

Инструкция позволяет держать личный и рабочий аккаунты Claude Code раздельно и
запускать их параллельно. Каждый профиль получает собственные настройки,
авторизацию, историю сессий, плагины и пользовательские инструкции.

Основа решения — официальная переменная `CLAUDE_CONFIG_DIR`, которая меняет
стандартный каталог `~/.claude` на указанный путь.

## Рекомендуемая схема

- `claude` — личный профиль в стандартном `~/.claude`;
- `claude-work` — рабочий профиль в `~/.claude-work`.

Так существующий личный профиль не нужно переносить или изменять.

## Настройка в Zsh или Bash

### 1. Создать каталог рабочего профиля

```bash
mkdir -p "$HOME/.claude-work"
chmod 700 "$HOME/.claude-work"
```

Не копируйте содержимое `~/.claude` в новый каталог: Claude Code сам создаст
нужные файлы при первом запуске.

### 2. Запустить Claude Code и войти в рабочий аккаунт

```bash
CLAUDE_CONFIG_DIR="$HOME/.claude-work" claude
```

Если окно авторизации не появилось автоматически, выполните внутри Claude Code:

```text
/login
```

В браузере выберите рабочий аккаунт. Затем выполните `/status` и убедитесь, что
показан нужный аккаунт и ожидаемый способ авторизации.

### 3. Добавить постоянную команду

Добавьте в `~/.zshrc` для Zsh или в `~/.bashrc` для Bash:

```bash
alias claude-work='CLAUDE_CONFIG_DIR="$HOME/.claude-work" claude'
```

Перезагрузите настройки оболочки:

```bash
# Zsh
source "$HOME/.zshrc"

# Bash
source "$HOME/.bashrc"
```

Теперь команды разделены:

```bash
claude       # личный аккаунт
claude-work  # рабочий аккаунт
```

Их можно одновременно запускать в разных вкладках терминала.

## Настройка в Fish

```fish
mkdir -p "$HOME/.claude-work"
chmod 700 "$HOME/.claude-work"

function claude-work
    env CLAUDE_CONFIG_DIR="$HOME/.claude-work" claude $argv
end

funcsave claude-work
claude-work
```

При первом запуске выполните `/login`, затем проверьте аккаунт через `/status`.

## Настройка в PowerShell

Добавьте функцию в профиль PowerShell (`$PROFILE`):

```powershell
function Invoke-ClaudeWork {
    $env:CLAUDE_CONFIG_DIR = "$HOME\.claude-work"

    try {
        claude @args
    } finally {
        Remove-Item Env:CLAUDE_CONFIG_DIR -ErrorAction SilentlyContinue
    }
}

Set-Alias claude-work Invoke-ClaudeWork
```

Перезапустите PowerShell, выполните `claude-work`, войдите в рабочий аккаунт и
проверьте его командой `/status`.

## Третий и последующие аккаунты

Для каждого аккаунта нужен отдельный каталог и отдельная команда:

```bash
mkdir -p "$HOME/.claude-client"
chmod 700 "$HOME/.claude-client"

alias claude-client='CLAUDE_CONFIG_DIR="$HOME/.claude-client" claude'
```

После добавления alias перезагрузите shell, запустите `claude-client` и выполните
`/login`.

## Что изолируется

При использовании отдельного `CLAUDE_CONFIG_DIR` разделяются:

- данные авторизации;
- пользовательские настройки и разрешения;
- глобальный `CLAUDE.md` профиля;
- история и память сессий;
- пользовательские плагины и MCP-серверы.

Файлы самого проекта не разделяются. Например, корневой `CLAUDE.md` и проектный
`.mcp.json` будут доступны обоим профилям, если Claude Code запущен из одного
репозитория.

## Безопасность и MCP

- Не добавляйте каталоги профилей и файлы с токенами в Git.
- Не переносите OAuth-файлы между профилями вручную.
- Production-MCP лучше хранить на уровне проекта в `.mcp.json` или выделять под
  production-контекст отдельный профиль.
- Давайте MCP-серверам однозначные имена, например `wearbase_prod_db`, а не `db`.
- Перед подтверждением операций создания, удаления, оплаты или публикации всегда
  проверяйте активный профиль и целевую систему.

## Проверка и диагностика

Для каждого профиля выполните:

```bash
claude
# Внутри сессии: /status

claude-work
# Внутри сессии: /status
```

Если оба запуска показывают один аккаунт:

1. Обновите Claude Code командой `claude update`.
2. Проверьте, что alias действительно задаёт `CLAUDE_CONFIG_DIR`.
3. Выполните `/logout`, затем `/login` только внутри проблемного профиля.
4. Проверьте переменные `ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN` и
   `CLAUDE_CODE_OAUTH_TOKEN`: они могут иметь приоритет над сохранённой сессией.
5. На macOS проверьте доступ Claude Code к Keychain; на Linux и Windows проверьте
   права на файл `.credentials.json` внутри каталога профиля.

Удалять старый профиль до успешной проверки нового не следует.

## Источники

- [Исходная статья: несколько аккаунтов через `CLAUDE_CONFIG_DIR`](https://oleksiimazurenko.dev/ru/blog/multiple-claude-accounts-one-device)
- [Официальный справочник переменных окружения Claude Code](https://code.claude.com/docs/en/env-vars)
- [Официальная документация по авторизации](https://code.claude.com/docs/en/authentication)
- [Официальный справочник команд Claude Code](https://code.claude.com/docs/en/commands)
