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

Donations are appreciated but never required.

# Usage
This tool uses the output of several plugins to extract order data from Amazon, Costco, and Walmart. The tool uses a web browswer to parse order history from Lowes and Tractor Supply. Then, the tool ingests these and allows you to categorize the transactions before exporting a CSV that can be imported into GNUCash. The tool also has the ability to match payment transactions automatically to these transactions, by operating directly against your file. 

# Requirements

This tool was built on Ubuntu with PHP 8.5 and Python 3. Your mileage may vary on other systems. The tool is intended to be hosted on a local webserver, where you can interact with it and upload a copy of your GnuCash file for processing. The tool will scan your file for suggested categories and accounts to ensure they align properly for import. 

PHP & Python3 requirements: 
```
sudo apt install python3-venv unzip php8.5-zip php8.5-fpm php8.5-sqlite php8.5-mbstring
```
A webserver, such as nginx, is also required. 
For ubuntu:

```
sudo groupadd publicweb
sudo usermod -aG publicweb $USER
sudo usermod -aG publicweb nginx
sudo usermod -aG publicweb www-data
newgrp publicweb

mkdir -p /home/$USER/public_html
sudo chown -R $USER:publicweb /home/$USER/public_html

chmod 750 /home/$USER
chmod 2770 /home/$USER/public_html
find /home/$USER/public_html -type d -exec chmod 2770 {} \;
find /home/$USER/public_html -type f -exec chmod 660 {} \;
```
```
$USER     = edits the files
nginx    = serves static files
www-data = PHP-FPM reads/executes PHP files
```
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
