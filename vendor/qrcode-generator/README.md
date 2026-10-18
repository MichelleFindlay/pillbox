# qrcode-generator (PHP)

Vendored from https://github.com/kazuhikoarase/qrcode-generator (`php/qrcode.php`), MIT licensed
(see LICENSE). Used to render the QR code for setting up an authenticator app in Account >
Password & security (`src/totp.php`).

Kept as-is, rather than rewritten in Pillbox's usual style, since it implements QR code encoding
(Reed-Solomon error correction, mask pattern scoring, version/format info) exactly to the
ISO/IEC 18004 spec: a hand-rewrite risks a QR code that looks right in code review but doesn't
actually scan, which isn't practical to verify without testing against real camera/scanner apps.
