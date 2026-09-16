# JetGrid local monitor (Windows)

Watches local development services and sends Telegram alerts when they go down
or come back.

This is **not** part of the Laravel application. It is one PHP file with no
Composer dependencies, no database and no framework, so it keeps working when
the thing it is watching does not:

- If the AWS box is down, local alerting still works.
- If this machine is off, server-side monitoring is unaffected.

Every message it sends is prefixed `[LOCAL]`, so a local alert can never be
mistaken for production.

## Setup

```powershell
cd tools\local-monitor
copy config.example.json config.json
notepad config.json     # paste bot_token + chat_id, edit the targets
php check.php --test    # confirm Telegram delivery
php check.php --verbose # run the real checks once
```

`config.json` and `state.json` are gitignored — the token never enters the repo.

## Targets

Each entry is either an HTTP check or a TCP port check:

```json
{ "name": "JetGrid", "url": "http://127.0.0.1:8000/admin/login", "expect_status": 200 }
{ "name": "MySQL",   "host": "127.0.0.1", "port": 3306 }
```

Omit `expect_status` to accept any 2xx/3xx.

## Scheduling

Either import the supplied task definition:

```powershell
# Edit the two paths inside the XML first — they are placeholders.
schtasks /Create /TN "JetGrid Local Monitor" /XML JetGridLocalMonitor.xml
```

Or register it directly without the XML:

```powershell
schtasks /Create /TN "JetGrid Local Monitor" /SC MINUTE /MO 5 /F ^
  /TR "\"C:\php\php.exe\" \"%CD%\check.php\""
```

Verify and control it:

```powershell
schtasks /Query  /TN "JetGrid Local Monitor" /V /FO LIST
schtasks /Run    /TN "JetGrid Local Monitor"
schtasks /Delete /TN "JetGrid Local Monitor" /F
```

## Alert discipline

The same rules as the server side, for the same reason — a check running every
five minutes must not produce 288 messages a day:

- Alerts fire on **transitions only**: up→down and down→up.
- A failure must be seen `failures_before_alert` times in a row (default 2)
  before it is believed, so a single blip stays silent.
- Recovery messages include how long the target was down.

State lives in `state.json` beside the script. Delete it to reset.
