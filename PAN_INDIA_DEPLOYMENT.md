# PAN India deployment

## Copy these files directly

- `pan_india.php`
- `telegram_login.php`
- `api/pan_india.php`
- `cli/telegram_worker.php`
- `includes/telegram_worker_client.php`
- `config/telegram.php.example`

Preserve the same relative paths in the main project.

## Merge these existing shared files

Do not blindly overwrite these if the main project has newer changes:

- In `includes/header.php`, add the PAN India navigation item:

  ```php
  ['label' => 'PAN India', 'href' => 'pan_india.php'],
  ```

- From `assets/style.css`, merge the section beginning with:

  ```css
  /* PAN India search */
  ```

## Server-only configuration

Create `config/telegram.php` on the target server using
`config/telegram.php.example` as the template. Never upload the real secret to
Git or expose it through the web.

The API hash previously used during laptop testing was exposed and should be
rotated before production deployment.

Do not copy `.runtime`, `Gas/telegram_php`, `worker.session`, log files, or the
laptop's downloaded MadelineProto PHAR into source control.

## First production authorization

Run the Telegram worker once on the target server:

```bash
php cli/telegram_worker.php
```

Scan the QR code from Telegram mobile. Keep the generated
`Gas/telegram_php/worker.session` private and persistent. QR authorization is
normally required only once for that server unless Telegram revokes the
session or the session files are lost.

For production, run the worker continuously under a service manager such as
systemd/Supervisor. It must listen only on `127.0.0.1:8091`; never expose port
8091 publicly.

## Laptop launcher

`run_crm_server.bat` and `run_crm_server.ps1` are only for this Windows laptop.
They start the hidden Telegram worker and the visible CRM development server
from one command:

```powershell
.\run_crm_server.bat
```

The target server still needs PHP 8.2 or newer with curl, fileinfo, gd, gmp,
mbstring, mysqli/PDO MySQL, OpenSSL, and sockets.
