# WP Search Console Data

Integrates Google Search Console data directly into your WordPress admin area, providing quick insights into your content's performance.

**Requires PHP 8.1 or higher.**

## Features

*   Displays Clicks, Impressions, CTR, and Position data from Google Search Console.
*   Adds sortable data columns to the Posts and Pages list tables. *(Note: Sorting applies only to the currently visible page due to client-side implementation)*.
*   Shows a data summary in the Admin Bar when viewing single posts/pages from the frontend (while logged in).
*   Uses secure Service Account authentication (no user OAuth flow needed).
*   Caches API data for performance (default 72 hours).
*   Configurable options:
    *   Select the relevant Search Console property.
    *   Choose the default date range (30, 90, 180, or 365 days).
    *   Optionally hide CTR and Position columns.
    *   Optionally combine Clicks and Impressions into a single column.
*   Debug logging feature.

## Installation

1.  **Download:** Download the plugin ZIP file or clone the repository.
2.  **Upload:** Upload the `wp-search-console-data` folder to your `/wp-content/plugins/` directory.
3.  **Activate:** Activate the plugin through the 'Plugins' menu in WordPress.
4.  **Install Dependencies:** Navigate to the plugin directory via command line and run `composer install` to download the required Google API client library.

## Configuration

Before the plugin can fetch data, you need to configure a Google Service Account:

1.  **Create Service Account & Enable API:**
    *   Go to the [Google Cloud Console](https://console.cloud.google.com/).
    *   Create a new project or select an existing one.
    *   Navigate to "IAM & Admin" > "Service Accounts".
    *   Click "+ CREATE SERVICE ACCOUNT". Give it a name (e.g., "WordPress GSC Plugin") and description.
    *   Grant necessary permissions (usually none needed at the project level for just GSC API).
    *   Click "DONE".
    *   Find your newly created service account, click the three dots under "Actions", and select "Manage keys".
    *   Click "ADD KEY" > "Create new key". Choose "JSON" and click "CREATE". A JSON file will download – **keep this file secure!**
    *   Go to "APIs & Services" > "Library". Search for "Google Search Console API" and click "Enable".
2.  **Grant Access in Search Console:**
    *   Open the downloaded JSON file and copy the `client_email` address (it looks like `...@...gserviceaccount.com`).
    *   Go to your [Google Search Console](https://search.google.com/search-console/).
    *   Select the property you want to connect.
    *   Go to "Settings" > "Users and permissions".
    *   Click "ADD USER".
    *   Paste the service account's `client_email` address.
    *   Set Permission to at least "Restricted" (or "Full" if preferred, but "Restricted" is sufficient for reading data).
    *   Click "ADD".
3.  **Configure Plugin:**
    *   Go to "Settings" > "Search Console Data" in your WordPress admin.
    *   **Option A (Recommended for Security):** Define the *absolute path* to the downloaded JSON key file in your `wp-config.php`:
        ```php
        define( 'WPSCD_SERVICE_ACCOUNT_KEY_PATH', '/path/to/your/downloaded-key-file.json' );
        ```
        *(Replace `/path/to/your/downloaded-key-file.json` with the actual path on your server. Ensure this file is stored securely outside your web root if possible.)*
    *   **Option B:** If you cannot edit `wp-config.php`, open the downloaded JSON file, copy its *entire content*, and paste it into the "Service Account JSON Key" textarea in the plugin settings.
    *   Click "Save Changes".
4.  **Select Property:** If the Service Account Key is valid and has permissions, a new section "Search Console Property Selection" will appear. Select the correct property for your WordPress site from the dropdown.
5.  **Configure Options:** Adjust the "Default Date Range", "Show CTR & Position", and "Combine Clicks/Impressions" settings as needed.
6.  **Save Changes Again.**

Data should now start appearing in the Posts/Pages columns and the Admin Bar (after the cache populates).

## Debugging

If you encounter issues, enable the "Debug Logging" option in the plugin settings. This will create a log file at `/wp-content/uploads/wpscd-logs/wpscd-debug.log` (path shown in settings) containing details about API calls and cache status.

## License

GPL v2 or later. 