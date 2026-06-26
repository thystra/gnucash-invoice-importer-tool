# GnuCash Vendor Bill Tool

Local web tool for staging vendor orders as GnuCash vendor bill CSVs, reviewing line-item categories, exporting payment/stored-value rows, and auditing/matching imported payments.


## License

GnuCash Vendor Import Tool is licensed under the GNU General Public License v3.0 or later.

SPDX license identifier: `GPL-3.0-or-later`

See `LICENSE` for the full license text.

## Support

GnuCash Vendor Import Tool is free and open source software.

If this project saves you time, helps clean up your books, or supports your small business/farm accounting workflow, optional donations help fund continued maintenance and new vendor modules.

Support development: https://ko-fi.com/thewolfandtheraven

Tips are appreciated but never required.

# Usage
This tool uses the output of several plugins to extract order data from Amazon, Costco, and Walmart. The tool uses a web browswer to parse order history from Lowes and Tractor Supply. Then, the tool ingests these and allows you to categorize the transactions before exporting a CSV that can be imported into GNUCash. The tool also has the ability to match payment transactions automatically to these transactions, by operating directly against your file. 

# Requirements

This tool was built on Ubuntu with PHP 8.5 and Python 3. Your mileage may vary on other systems. The tool is intended to be hosted on a local webserver, where you can interact with it and upload a copy of your GnuCash file for processing. The tool will scan your file for suggested categories and accounts to ensure they align properly for import. 

A webserver, such as nginx, is also required. 
For ubuntu:

## Local web directory setup on Ubuntu

This tool is intended to run as a local web application. The safest simple setup is:

* your normal user owns and edits the files;
* a shared `publicweb` group lets nginx/PHP-FPM read the project;
* only runtime/config directories are group-writable;
* directories use the **setgid** bit so new files inherit the `publicweb` group;
* do **not** use the sticky bit on the project/config directories.

Install base packages:

```bash
sudo apt update
sudo apt install -y git unzip acl python3-venv php8.5-fpm php8.5-zip php8.5-sqlite3 php8.5-mbstring
```

Create a shared web group and add the likely web/PHP users:

```bash
sudo groupadd -f publicweb
sudo usermod -aG publicweb "$USER"

for acct in www-data nginx; do
  if id "$acct" >/dev/null 2>&1; then
    sudo usermod -aG publicweb "$acct"
  fi
done
```

Create the project directory:

```bash
WEB_GROUP="publicweb"
APP_PARENT="$HOME/public_html"
APP_ROOT="$APP_PARENT/gnucash-invoice-importer-tool"
REPO_URL="https://github.com/thystra/gnucash-invoice-importer-tool.git"

mkdir -p "$APP_PARENT"
sudo chown "$USER:$WEB_GROUP" "$APP_PARENT"
sudo chmod 2770 "$APP_PARENT"

# Allow the web group to traverse your home directory without listing it.
sudo setfacl -m "g:${WEB_GROUP}:--x" "$HOME"

git clone "$REPO_URL" "$APP_ROOT"

sudo chown -R "$USER:$WEB_GROUP" "$APP_ROOT"
sudo find "$APP_ROOT" -type d -exec chmod 2750 {} +
sudo find "$APP_ROOT" -type f -exec chmod 640 {} +
```

Create writable runtime/config locations:

```bash
sudo install -d -o "$USER" -g "$WEB_GROUP" -m 2770 \
  "$APP_ROOT/config" \
  "$APP_ROOT/data" \
  "$APP_ROOT/exports" \
  "$APP_ROOT/raw_html"

cd "$APP_ROOT"

cp -n config/user_defaults.example.php config/user_defaults.php
sudo chown "$USER:$WEB_GROUP" config/user_defaults.php
sudo chmod 660 config/user_defaults.php
sudo chmod 2770 config data exports raw_html
```

Restart services so group membership changes are picked up:

```bash
sudo systemctl restart php8.5-fpm
sudo systemctl restart nginx
```

If your shell needs to pick up the new `publicweb` group immediately, either log out and back in, or run:

```bash
newgrp publicweb
```

Permission notes:

```text
2750 on source directories = owner can edit, group can read/traverse, setgid preserves group
640 on source files        = owner can edit, group can read
2770 on runtime dirs       = owner/group can write, setgid preserves group
660 on user_defaults.php   = owner/group can edit/write
```

Do not set the sticky bit on `config/` or `config/user_defaults.php`. Sticky directories can prevent PHP-FPM from atomically replacing `config/user_defaults.php` during Config-page saves.

## Vendor defaults and settings. 

Defaults are stored in ```config/user_defaults.php```. Copy the example to make a local copy. 
```cp -n config/user_defaults.example.php config/user_defaults.php``` to make a local copy.

These values replace the hard-coded DEFAULT_... variables used by imports, exports, payment hints, and account suggestions. 
They are stored in config/user_defaults.php, which is ignored by Git and survives hard resets. The constants in index.php remain fallback values only.

## Ingesting data

To scrape Lowe's or TSC transactions, you will need the Chromium browser snap package. 


To access Amazon orders, you will need this extension: https://chromewebstore.google.com/detail/mgkilgclilajckgnedgjgnfdokkgnibi?utm_source=item-share-cb
To access transaction data, the author requests you donate to their charity of choice, currently $15/year. Doing this will allow you to get and match payments from credit cards, gift cards, Amazon Visa points, and other Amazon point programs. 
You will get two or three files: 1) orders 2) items and 3) transactions. Upload them to the tool one after another to process. 


To Access Costco orders, use this extension: https://chromewebstore.google.com/detail/nnalnbomehfogoleegpfegaeoofheemn?utm_source=item-share-cb
The tool accepts the json output. Upload to the tool. 


To Access Walmart orders, use this extension: https://chromewebstore.google.com/detail/bndkihecbbkoligeekekdgommmdllfpe?utm_source=item-share-cb
Open the .XLSX file and save it as a CSV, then import it into the tool.  


Browser: Any Chrome/Chromium based browser should work with these. I had better luck with Brave than Vivaldi on Amazon. 
