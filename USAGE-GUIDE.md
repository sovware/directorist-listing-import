# Listing Importer — Usage Guide
**Version 1.0.0** | Google Business and RSS/Feed Importer for Directorist

---

## Table of Contents
1. [What Listing Importer Does](#what-listing-importer-does)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Google Business Import](#google-business-import)
5. [Finding Importable Sources](#finding-importable-sources)
6. [Adding Your First Feed](#adding-your-first-feed)
7. [Settings Explained](#settings-explained)
8. [Reading the Import Logs](#reading-the-import-logs)
9. [How Scheduling Works](#how-scheduling-works)
10. [What Gets Imported](#what-gets-imported)
11. [Moderating Imported Listings](#moderating-imported-listings)
12. [Troubleshooting](#troubleshooting)
13. [FAQ](#faq)
14. [Developer Notes](#developer-notes)

---

## What Listing Importer Does

Listing Importer brings external listing data into your Directorist directory through two legal, user-friendly routes:

- **Google Business Import**: search Google Places and choose which businesses to import.
- **RSS / Feed Import**: auto-sync listings from RSS-enabled classifieds and feed sources.

For RSS/feed automation, instead of manually checking feed sources, you:
1. Paste a normal source page URL or direct RSS/Atom feed URL
2. Choose a Directorist category
3. Set a sync schedule

Listing Importer tries to find a legal feed for the source, validates it, previews a few detected items, deduplicates by source URL, and creates Directorist listings on autopilot.

### When to use Directorist core CSV import instead

If your listings are in Excel, Google Sheets, Numbers, or a CSV file, use Directorist's built-in CSV importer instead of this plugin.

Typical CSV/spreadsheet use cases:
- You collected business names, phone numbers, addresses, websites, and categories in a spreadsheet
- You are migrating listings from another directory plugin
- You need column mapping for custom Directorist fields
- You want to upload your own controlled listing dataset once

In WordPress admin, go to **Directorist → Tools → Import/Export** and use the CSV importer. Use this plugin when the source is an RSS feed that should be checked repeatedly over time.

---

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| Directorist | Any current version |
| Hosting | Must support outgoing HTTP requests (WP HTTP API) |

---

## Installation

### Option A — Upload via WordPress Admin
1. Download `directorist-listing-import.zip`
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**
4. Click **Activate Plugin**

### Option B — Manual FTP
1. Unzip `directorist-listing-import.zip`
2. Upload the `directorist-listing-import/` folder to `/wp-content/plugins/`
3. Go to **WordPress Admin → Plugins** and activate **Listing Importer**

### Verify Installation
After activation, you should see **Listing Importer** in the left sidebar under the Directorist menu.

> **Note:** If you see a red error banner saying "Directorist plugin is required," install and activate Directorist first, then activate Listing Importer.

## Google Business Import

Go to **Directorist → Listing Importer → Google Business Import**.

1. Open the **Settings** sub-tab and add your Google Places API key.
2. Open the **Import** sub-tab.
3. Enter a keyword such as "Restaurant", "Dentist", or "Hotel".
4. Enter a location such as "Dhaka", "Toronto", or "New York".
5. Choose the Directorist category, location, listing status, and import options.
6. Click **Import Businesses**, review the preview list, and import the selected businesses.

Use the **Import History** sub-tab to review past Google import runs.

### Managing Google API costs

Google may charge for Places API usage when you search and import businesses. To stay in control of spending, set a monthly budget and email alerts in **Google Cloud Console → Billing → Budgets & alerts**.

---

## Finding Importable Sources

You do not need to manually hunt for RSS every time. Paste a source page URL and the plugin will:

1. Check whether the URL is already RSS/Atom
2. Look for official RSS/Atom links declared by the page
3. Apply safe source-specific feed rules where available
4. Stop with a clear message when a site does not provide an official feed

The plugin does **not** scrape normal website pages. This keeps imports safer and respects source website terms.

### Craigslist
Paste the normal Craigslist search or category URL. The plugin will try to use the RSS version automatically.

Example source URL:

```
https://newyork.craigslist.org/search/apa?min_price=1000&max_price=2000
```

RSS equivalent:

```
https://newyork.craigslist.org/search/apa?min_price=1000&max_price=2000&format=rss
```

**Step-by-step:**
1. Go to [craigslist.org](https://www.craigslist.org) and navigate to your target city
2. Click a category (e.g., "Apartments / Housing")
3. Apply any filters you want (price range, bedrooms, etc.)
4. Copy the URL from your browser
5. Paste it into **Source URL**

**Common Craigslist category codes:**

| Code | Category |
|---|---|
| `apa` | Apartments / Housing |
| `rea` | Real Estate (by owner) |
| `cto` | Cars & Trucks (by owner) |
| `ctd` | Cars & Trucks (by dealer) |
| `jjj` | Jobs |
| `sss` | For Sale (all) |
| `fua` | Furniture |
| `ela` | Electronics |
| `bfs` | Business / Commercial |

### Kijiji (Canada)
Kijiji pages are often normal HTML pages, not importable RSS feeds. For legal reasons, this plugin will not scrape Kijiji pages automatically.

Use Kijiji only when you have:
- An official Kijiji RSS/feed URL that validates as XML
- Written permission or a partner-approved feed source
- Your own listing data, imported through Directorist core CSV import

### OLX
Paste the category/search page URL first. If OLX exposes an official RSS/Atom link on that page, the plugin will find it. If not, it will stop and explain that the page is not importable.

### Any Other Site
If a site has an official RSS/Atom feed, Listing Importer can work with it. Good signs:
- An RSS icon (orange broadcast icon) on the page
- A link labelled "RSS", "Feed", or "Subscribe"
- `/feed/` or `?format=rss` in the URL

Important: a URL ending in `/rss` or `/feed/` is not always usable. Some publishers redirect to broken endpoints, block server requests, or publish news/blog feeds instead of actual directory listings. The plugin validates the feed before saving it.

---

## Adding Your First Feed

1. Go to **Directorist → Listing Importer → RSS / Feed Import** in your WP admin
2. On the **RSS Feeds** tab, fill in the **Add Source** form:

| Field | What to enter |
|---|---|
| **Feed Name** | A label for your own reference (e.g. "NYC Apartments") |
| **Source URL** | A normal source page URL or direct RSS/Atom feed URL |
| **Directorist Category** | Which category imported listings should appear in |
| **Sync Interval** | How often to auto-check for new listings |

3. Click **Find Feed & Add Source**

If a feed is found, the plugin saves the feed and shows a short preview of detected item titles. If no feed is found, it shows a plain-language reason instead of importing anything.

Your feed appears in the **Saved Feeds** table below. To test it immediately, click the **Run Now** button in the Actions column.

You can also use the Actions column to:
- **Edit** a feed's name, URL, category, or interval
- **Pause** a feed without deleting it
- **Resume** a paused feed later
- **Delete** a feed you no longer want to sync

---

## Settings Explained

Go to **Listing Importer → RSS / Feed Import → Settings** tab.

### Default Listing Status
Controls what happens to a listing immediately after import:

- **Pending Review** *(Recommended)* — Listings are saved as drafts. You review and approve them in Directorist before they go live. Best for quality control.
- **Published Immediately** — Listings go live on your directory the moment they're imported. Use only if you trust the source completely.

### Batch Size per Run
How many listings to process per feed per scheduled run.

- **Recommended:** 10–25 on shared hosting
- **Recommended:** 50–100 on VPS/dedicated servers
- Setting this too high on shared hosting can cause PHP timeouts

---

## Reading the Import Logs

Go to the **Import Logs** tab to see a history of every import run.

| Column | Meaning |
|---|---|
| **Time** | When the import ran |
| **Feed** | Which feed was processed |
| **Imported** | New listings created in this run |
| **Skipped** | Listings already in your directory (duplicates) |
| **Errors** | Listings that failed to import |
| **Note** | Error message if something went wrong |

> Logs are capped at the last 200 entries. Use **Clear Logs** to reset.

---

## How Scheduling Works

Listing Importer uses **WP-Cron** for RSS/feed syncs — WordPress's built-in task scheduler.

### What triggers the schedule
WP-Cron runs when someone visits your website. On a busy site this is fine. On a low-traffic site, imports may be delayed.

### Forcing real cron (recommended for production)
For reliable scheduling, disable WP-Cron and set up a real server cron job:

1. Add this to `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

2. Add a cron job on your server (via cPanel or SSH):
   ```bash
   # Run every 15 minutes
   */15 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
   ```

### Per-feed intervals
Each feed has its own interval setting. The scheduler checks if enough time has passed since `last_run` before processing a feed, so multiple feeds can coexist without conflict.

---

## What Gets Imported

For each RSS item, Listing Importer attempts to extract and map:

| RSS Field | Directorist Field | Notes |
|---|---|---|
| `<title>` | Listing title | Always available |
| `<description>` | Listing content | Always available |
| `<link>` | `_directorist_listing_import_source_url` meta | Used for dedup |
| `<pubDate>` | Post date | Falls back to current time |
| `<cl:price>` | `price` meta | Craigslist only; also tries regex on title |
| `<cl:neighborhood>` | `address` meta | Craigslist only |
| `<enclosure>` | Featured image | Downloads & attaches image |

### What is NOT imported
- Full street addresses (not in RSS for privacy)
- Phone numbers / emails
- Multiple photos (only the first enclosure)
- Private seller contact info

---

## Moderating Imported Listings

With **Default Listing Status** set to *Pending Review*:

1. Go to **Directorist → All Listings**
2. Filter by status: **Pending**
3. Review each listing — check title, description, price, image
4. Click **Publish** to make it live, or **Trash** to discard it

**Pro tip:** Use Directorist's bulk actions to approve or trash multiple listings at once.

---

## Troubleshooting

### "Feed not working" / 0 listings imported
- Open the RSS URL in your browser — does it show XML? If not, the URL is wrong
- Check that your server allows outgoing HTTP requests (some hosts block this)
- Try clicking **Run Now** manually to see if an error appears in the logs

### Listings are imported as drafts, not showing on the site
- This is intentional if **Default Listing Status** is set to *Pending Review*
- Go to Directorist → All Listings → Pending and publish them

### Images not loading / not attached
- Image sideloading requires your WordPress media upload folder to be writable
- Check `wp-content/uploads/` permissions (`755` or `775`)
- Some RSS feeds don't include image enclosures — this is a source limitation

### Duplicate listings appearing
- Each listing is deduplicated by its source URL (`<link>` tag in RSS)
- If you delete a previously imported listing, it will be re-imported on the next run (the dedup record is gone)
- To prevent re-importing a deleted listing, either keep it as a draft or remove the feed temporarily

### WP-Cron not firing
- Confirm your site gets regular traffic (WP-Cron is triggered by page visits)
- Or set up a real server cron job as described in [How Scheduling Works](#how-scheduling-works)

### PHP timeout on large imports
- Reduce the **Batch Size** in Settings to 10–15
- Upgrade to a VPS or managed WordPress host for better performance

---

## FAQ

**Is scraping Craigslist legal with this plugin?**
Listing Importer only uses Craigslist's official, publicly published RSS feeds — not HTML scraping. Craigslist publishes these feeds intentionally for aggregation. Always review the ToS of any source you use.

**Will imported listings automatically expire?**
Not in v1.0. Future versions will support TTL (time-to-live) per feed so listings are automatically unpublished when they disappear from the source.

**Can I import from Facebook Marketplace?**
Facebook Marketplace does not provide RSS feeds or a public API. It cannot be imported automatically. Users must add listings manually.

**Why did Kijiji fail?**
Most Kijiji category/search URLs are normal HTML pages. Kijiji's terms restrict automated scraping/collection without permission, so this plugin will not scrape those pages. Use an official feed, a permission-based source, or Directorist CSV import for data you own.

**Why did a URL ending in `/rss` or `/feed/` fail?**
The source may not be serving valid RSS/Atom to your server. For example, some sites redirect `/rss` to `/feed/` but the final endpoint returns an error such as HTTP 415 instead of XML. In that case, use another official feed URL or ask the publisher for an approved feed.

**Can I add the same feed twice?**
No. The admin screen prevents saving the same RSS URL twice. This keeps your feed list cleaner and avoids repeated skip-only import runs.

**Does this work with Directorist custom fields?**
v1.0 maps standard fields (title, description, price, address, image). Custom field mapping is on the roadmap for v1.1.

**Can I pause a feed without deleting it?**
Yes. Use **Pause** in the Saved Feeds table. Paused feeds stay saved but are skipped by scheduled imports until you click **Resume**.

**Can I import a spreadsheet with this plugin?**
No. This plugin is intentionally focused on RSS/classified feed imports. For Excel, Google Sheets, or CSV workflows, use Directorist's built-in CSV importer under **Directorist → Tools → Import/Export**.

**How do I update the plugin?**
Replace the `directorist-listing-import/` folder in `/wp-content/plugins/` with the new version. Your saved feeds, logs, and settings are stored in `wp_options` and are not affected by updates.

---

## Developer Notes

### Post type
All listings are created as `at_biz_dir` (Directorist's post type).

### Meta keys added by Listing Importer
| Key | Value |
|---|---|
| `_directorist_listing_import_source_url` | Original listing URL (used for deduplication) |
| `_directorist_listing_import_source_name` | Human-readable source name (e.g. "Craigslist") |
| `_directorist_listing_import_imported_at` | Unix timestamp of import |

### Hooks available for developers
```php
// Modify a listing's post data before it's inserted
add_filter( 'directorist_listing_import_pre_insert_listing', function( $post_data, $rss_item, $feed ) {
    // Modify $post_data array
    return $post_data;
}, 10, 3 );

// Fire after a listing is successfully created
add_action( 'directorist_listing_import_listing_created', function( $post_id, $rss_item, $feed ) {
    // e.g. send a notification, add custom meta
}, 10, 3 );
```

Add these to your theme's `functions.php` or a custom plugin.

### File structure
```
directorist-listing-import/
├── directorist-listing-import.php              ← Main plugin entry, constants, activation
├── includes/
│   ├── class-feed-manager.php    ← CRUD for feed configs (wp_options)
│   ├── class-importer.php        ← RSS fetch, parse, dedup, listing creation
│   └── class-scheduler.php       ← WP-Cron registration & "Run Now" handler
├── admin/
│   ├── class-admin-page.php      ← Menu registration, form handlers
│   └── views/
│       └── admin-page.php        ← HTML template (Feeds / Logs / Settings tabs)
├── assets/
│   └── admin.css                 ← Admin panel styles
└── USAGE-GUIDE.md                ← This file
```

---

*Listing Importer v1.0.0 — Built for Directorist*
