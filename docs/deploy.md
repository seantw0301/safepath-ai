# Deployment

For a generic Linux host. These steps were checked locally (sub-path, judge mode, demo seeding with no API key), but **have not yet been run on a real Linux server**.

## Requirements
| Item | Version |
|---|---|
| PHP | 8.3+ with pdo_mysql, curl, gd, mbstring; FPM behind nginx or Apache |
| MySQL | 9.0.1 |
| ffmpeg | any recent |
| Python | 3.12 (`helpers/samtool` venv) |
| Font | a TrueType CJK font for the PDF (below) |

## Steps
```bash
git clone https://github.com/seantw0301/safepath-ai.git /var/www/safepath
cd /var/www/safepath
scripts/deploy.sh          # first run creates .env, then stops
# edit .env: DB_DRIVER=mysql, DB_*, META_API_KEY, JUDGE_MODE=1
scripts/deploy.sh          # second run installs, migrates and seeds the demos
```
Create the database first:
```sql
CREATE DATABASE safepath CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'safepath'@'localhost' IDENTIFIED BY '<a strong password>';
GRANT ALL ON safepath.* TO 'safepath'@'localhost';
```
Then:
- Web server: `deploy/nginx.conf.example` or `deploy/apache.conf.example`
- Worker: `deploy/safepath-worker.service`
- Permissions: the web and worker users need write access to `storage/` and `samples/cache/`
- `chmod 600 .env`, and make sure only `public/` is served

## Sub-path
Set `APP_BASE_PATH=/safepath` and use the sub-path block in the sample config.

## Judge-safe mode
| Setting | Effect |
|---|---|
| `JUDGE_MODE=1` | Demos are read-only, findings can't be edited, and the home page lists demos only |
| `LIVE_UPLOADS=0` (default) | No uploads; no Meta API spend from visitors |
| `LIVE_UPLOADS=1` + `LIVE_UPLOADS_PER_DAY=3` | Upload allowed, up to N per 24 hours; a scan is visible only to the browser session that made it |

There are no user accounts. **Do not put real family footage on a public host.**

Demos replay from `samples/cache`, so seeding and viewing them needs no API key and costs nothing. With `LIVE_UPLOADS=0` you can leave `META_API_KEY` empty.

## PDF font
`samtool pdf` embeds the font at `PDF_FONT` (default: a macOS path). On Linux:
```bash
apt-get install fonts-arphic-uming
# .env → PDF_FONT=/usr/share/fonts/truetype/arphic/uming.ttc
```
`PDF_FONT` is read from the environment, so also add `Environment=PDF_FONT=...` to the worker service. Without a font file it falls back to a non-embedded CID font, which some viewers show as garbled text. **Open a generated PDF to check.**

## Check it
```bash
BASE=https://your-host/safepath scripts/smoke.sh
```
Open a demo, the PDF and the before/after slider in a private window.
