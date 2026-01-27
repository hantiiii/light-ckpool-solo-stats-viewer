# H.A.N.T.I. v7 Solaris - CKPool Node Dashboard

Professional, lightweight, and robust statistics dashboard for Solo Mining CKPool nodes.
This project provides real-time visualization of miner performance, network statistics, and difficulty predictions without bloat.

## 🟢 Live Version
**Check the live node stats here:** [https://88x.pl/btcnode/](https://88x.pl/btcnode/)

---

## 🚀 Key Features

* **Real-time Monitoring:** Reads directly from CKPool logs and status files.
* **High-Resolution Charts:**
    * **24h:** Raw 5-minute data granularity.
    * **7d / 30d:** Precise hourly averages (1h resolution).
    * **1y:** Daily historical view.
* **H.A.N.T.I. Prediction v7:** Proprietary algorithm for estimating Bitcoin mining difficulty adjustments.
* **Network Intelligence:** Real-time Bitcoin price, block rewards (including fees), and network hashrate analysis.
* **Optimized Core (v80+):**
    * **"The Tank" Parser:** Memory-safe log processing capable of handling large datasets.
    * **SQLite Storage:** Efficient, serverless database with transaction safety (WAL disabled for stability).
    * **Zero-Dependency Frontend:** Clean HTML/CSS/JS (Chart.js) without heavy frameworks.
* **Responsive Design:** Fully adaptive Dark Mode interface with centered KPIs.

## 🛠️ Technical Stack

* **Backend:** PHP 7.4+ (CLI & FPM)
* **Database:** SQLite3 (optimized for write-heavy logging)
* **Frontend:** Vanilla JS + Chart.js 4.4
* **System:** Designed for Linux environments (Debian/Ubuntu) running ckpool-dr.

## ⚙️ Installation / Update

1.  Clone the repository to your web directory.
2.  Ensure PHP has write permissions to the `/data` folder:
    ```bash
    chown -R www-data:www-data /path/to/btcnode/data
    chmod -R 775 /path/to/btcnode/data
    ```
3.  Set up the cron job for the parser (e.g., every minute):
    ```cron
    * * * * * /usr/bin/php /path/to/btcnode/parser.php >/dev/null 2>&1
    * * * * * /usr/bin/php /path/to/btcnode/prediction_parser.php >/dev/null 2>&1
    ```

## ☕ Support development

If you find this dashboard useful for your solo mining operation, consider supporting the development.

**BTC Donation Address:**
`1HANfVCfy9CFp5JAjNBhKWPWbavjXxdCRR`

---
*Powered by H.A.N.T.I. v7 Solaris Engine*