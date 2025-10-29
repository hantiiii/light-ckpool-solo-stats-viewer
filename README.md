# CKPool Solo Stats Viewer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://www.php.net/)

A modern, fast, and secure PHP-based web interface for viewing **ckpool** statistics. It features a lightweight "Liquid Lava" design (using Inter & JetBrains Mono fonts), dynamic multi-series charts (up to 1 year), automatic light/dark theme switching, and is built for performance using SQLite and a local `bitcoind` node.

---

## Live Demo

A live version of this stats viewer is running at:

* **Pool Overview:** [88x.pl/btcnode/](https://88x.pl/btcnode/)
* **User Example:** [1HANfVC...](https://88x.pl/btcnode/?btc_address=1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR)

---

## Prerequisites

This project is a statistics viewer **for an existing, running instance of ckpool AND a local, running `bitcoind` node**. It does not include the mining pool software itself.

* **ckpool Source & Info:** [ckolivas/ckpool on Bitbucket](https://bitbucket.org/ckolivas/ckpool-solo/src/solobtc/)
* **Bitcoin Core:** A running `bitcoind` node accessible via `bitcoin-cli` by a dedicated user (e.g., `bitcoinnode`).
* **PHP Extensions:** `php-sqlite3`, `php-curl`.

## ✨ Key Features

* **Local-First Data:** Fetches all critical data (block height, difficulty, last block reward) **primarily from your local `bitcoin-cli` node** for maximum reliability and speed.
* **Live "Prize to Win":** The block reward value is calculated "live" by combining the latest block reward + price (fetched every 3-5 min) with the latest block height (fetched every 3-5 min).
* **H.A.N.T.I. Prediction Model 🚀:** A unique, hybrid difficulty adjustment prediction that blends local `bitcoin-cli` calculations with live network hashrate trends for a robust, weighted estimate.
* **API Fallbacks:** Intelligently falls back to public APIs (Mempool.space, Coinbase, Binance, Kraken) for data if the local node or primary API fails, including a multi-stage fallback for price.
* **Highly Performant:** Two optimized background cron jobs handle all data fetching:
    * **`parser.php` (5 min):** Fetches all high-frequency data: user/pool stats, block height, block reward, and live BTC price. Stores in `stats.db`.
    * **`prediction_parser.php` (4x/day):** Fetches all low-frequency data: network hashrate, difficulty, and calculates the H.A.N.T.I. prediction. Stores in `network.db`.
* **1-Year Historical Charts:** Efficiently displays long-term charts by using an automatic daily "roll-up" (aggregation) of raw data, ensuring fast load times.
* **Modern UI:** A clean interface featuring **Inter** and **JetBrains Mono** fonts, with automatic light/dark mode.
* **Secure by Design:** Parser scripts (`parser.php`, `prediction_parser.php`, `common.php`) and the `data` directory are secured from public web access via NGINX.

---

## 🚀 Installation Guide

### 1. Clone the Repository

Clone this repository into your desired web server directory.

```bash
git clone [https://github.com/hantiiii/light-ckpool-solo-stats-viewer.git](https://github.com/hantiiii/light-ckpool-solo-stats-viewer.git) /var/www/html/btcnode
```
*(Adjust the path `/var/www/html/btcnode` if necessary)*

### 2. Configure NGINX

Add a server block to your NGINX configuration to serve the application and secure the data directory and parser scripts.

```nginx
server {
    listen 443 ssl http2;
    server_name your_domain.com;
    root /var/www/html; # Adjust if your root is different
    index index.php;

    # SSL configuration...

    # Block public access to all parsers and common files
    location = /btcnode/parser.php { deny all; }
    location = /btcnode/prediction_parser.php { deny all; }
    location = /btcnode/common.php { deny all; }

    # Block access to the data directory (databases)
    location /btcnode/data/ {
        deny all;
    }

    # Handle the application routing
    location /btcnode/ {
        try_files $uri $uri/ /btcnode/index.php?$args;
    }

    # Process PHP files
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Adjust to your PHP-FPM socket path and version
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; 
    }
}
```

After saving, test and restart NGINX:

```bash
sudo nginx -t && sudo systemctl restart nginx
```

### 3. Set Permissions

Set the ownership and permissions for the project directory.

```bash
# Replace 'www-data' with your web server's group (e.g., 'client1')
# Replace /var/www/html/btcnode with your actual project path
WEB_GROUP=www-data 
PROJECT_PATH=/var/www/html/btcnode

sudo chown -R root:${WEB_GROUP} ${PROJECT_PATH}
sudo find ${PROJECT_PATH} -type d -exec chmod 775 {} \;
sudo find ${PROJECT_PATH} -type f -exec chmod 644 {} \;

# Make parsers executable by root
sudo chmod +x ${PROJECT_PATH}/parser.php
sudo chmod +x ${PROJECT_PATH}/prediction_parser.php
```

### 4. Configure the Parsers

Configuration is split into three files.

**In `common.php` (Global Config):**

```php
// in /var/www/html/btcnode/common.php
$apiTimeout = 15;
// --- Bitcoin Core Config ---
$bitcoinCliUser = 'bitcoinnode'; // User running bitcoind
$bitcoinCliPath = '/usr/local/bin/bitcoin-cli'; // Path to bitcoin-cli
```

**In `parser.php` (Cron 1 Config):**

```php
// in /var/www/html/btcnode/parser.php
$webUser = 'web1';
$webGroup = 'client1';
$usersDir = '/var/log/ckpool/users/';
$poolDir = '/var/log/ckpool/pool/';
```

**In `prediction_parser.php` (Cron 2 Config):**

```php
// in /var/www/html/btcnode/prediction_parser.php
$webUser = 'web1';
$webGroup = 'client1';
$logFilePath = '/var/log/ckpool/ckpool.log'; // Fallback for difficulty
```

### 5. Set Up Cron Jobs

Two cron jobs are needed, running as `root` (which allows `sudo -u bitcoinnode ...` to work without password prompts).

1.  Open the root crontab editor:
    ```bash
    sudo crontab -e
    ```
2.  Add the following lines (example schedule: 3 min & 8x/day) and save the file:

    ```cron
    # (3 min) Update user stats, pool stats, block height, block reward, and price
    */3 * * * * /usr/bin/php /var/www/html/btcnode/parser.php >/dev/null 2>&1

    # (8x/day) Update network hashrate, difficulty, and prediction data
    33 0,3,6,9,12,15,18,21 * * * /usr/bin/php /var/www/html/btcnode/prediction_parser.php >/dev/null 2>&1 
    ```

### 6. Run Manually

Run both parsers manually **once** to initialize the databases and confirm `bitcoin-cli` communication.

```bash
sudo /usr/bin/php /var/www/html/btcnode/parser.php
sudo /usr/bin/php /var/www/html/btcnode/prediction_parser.php
```

Your stats page should now be live! The 1Y chart data will start populating after the first daily aggregation (runs automatically after midnight).

---

## 💖 Support

If you find this project useful and want to show your appreciation, donations are welcome!

**BTC:** `1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR`

---

## 📄 License

This project is licensed under the MIT License.