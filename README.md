# ?? CKPool Solo Mining Stats & H.A.N.T.I. Prediction

A lightweight, self-hosted statistics dashboard for CKPool (Solo Bitcoin Mining). Features a custom difficulty prediction algorithm based on local node data.
Live version - https://88x.pl/btcnode/

## ? Key Features

* **Real-time Dashboard:** Visualizes Hashrate (5m, 1h, 24h), Shares, and Worker status.
* **H.A.N.T.I. v7 "Solaris" Algorithm:**
    * **Local-First:** Calculates network difficulty prediction using your own Bitcoin Node.
    * **Sliding Window:** Uses a 3-day (432 blocks) moving average to determine real-time network hashrate, eliminating "new epoch" noise.
    * **WEC (Weighted Economic Correction):** Adjusts prediction based on BTC price trends (Kraken API).
    * **Bunker Mode:** Fully operational even without external APIs (fallback mechanisms included).
* **Data Warehousing:**
    * Optimized SQLite database with automatic data roll-up (Raw -> Hourly -> Daily).
    * Keeps DB size small (~1MB) while retaining 2 years of history.
* **Interactive Charts:** Zoomable charts for 24h, 7d, 30d, and 1y periods.
* **Network History:** Hidden "Easter Egg" chart showing 2-year network difficulty & hashrate trends.

## ?? Architecture

* **Backend:** PHP (CLI scripts for parsing logs & calculating predictions).
* **Frontend:** HTML5 / JS (Chart.js) / CSS (Dark/Light mode).
* **Database:** SQLite 3 (Zero-config, serverless).
* **Integration:** Connects directly to `bitcoind` (RPC) and parses CKPool logs.

## ? Installation

### Prerequisites
* Bitcoin Core (`bitcoind`) running locally with RPC enabled.
* CKPool configured and logging to a specific directory.
* PHP 7.4+ with SQLite and PDO extensions.

### Setup
1.  **Clone the repository:**
    ```bash
    git clone [https://github.com/YOUR_USERNAME/btcnode.git](https://github.com/YOUR_USERNAME/btcnode.git)
    cd btcnode
    ```

2.  **Configure `common.php`:**
    Edit the file to match your environment:
    ```php
    $bitcoinCliPath = '/usr/bin/bitcoin-cli';
    $bitcoinCliUser = 'bitcoin'; // User running bitcoind
    ```

3.  **Configure paths in `parser.php` & `prediction_parser.php`:**
    Set the log directories:
    ```php
    $usersDir = '/var/log/ckpool/users/';
    $poolDir = '/var/log/ckpool/pool/';
    ```

4.  **Set Permissions:**
    Ensure the web user (e.g., `www-data` or `web1`) has write access to the `./data` directory.

5.  **Setup Cron Jobs:**
    Add the following to your crontab (`crontab -e`):
    ```bash
    # Main Stats Parser (Every 3 minutes)
    */3 * * * * /usr/bin/php /path/to/btcnode/parser.php >/dev/null 2>&1

    # H.A.N.T.I. Prediction Parser (Every 3 hours)
    0 */3 * * * /usr/bin/php /path/to/btcnode/prediction_parser.php >/dev/null 2>&1
    ```

## ? H.A.N.T.I. Logic (Hybrid Network Trend Intelligence)

The **v7 "Solaris"** iteration calculates network difficulty adjustments by:
1.  Fetching block times for the last **432 blocks** (approx. 3 days) from the local node.
2.  Calculating the *Realized Hashrate* based on this window.
3.  Projecting the remaining time of the current epoch using this realized hashrate.
4.  Applying a weighted correction based on BTC price action (Price down = Hashrate pressure down).
5.  Comparing the result with `Mempool.space` API as a sanity check.

## ? License
MIT License. Free to use for any Solo Miner!