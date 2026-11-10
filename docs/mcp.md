# Listing Import MCP integration

Listing Importer 1.3.0 contributes extension API 1 tools under owner `listing-import`, contract `1.2.0`, using Directorist's server, permissions, confirmations and durable operation records.

## Core compatibility

| Directorist core | MCP behavior | Native UI |
| --- | --- | --- |
| Before 8.11 | MCP tools and workers are disabled. The importer screen explains that MCP requires an update. | Google Business and RSS/Feed screens remain available. |
| 8.11–8.11.2 | 26 tools remain available. `update-settings` is omitted because its credential input requires the newer schema validator. Reads, mapping updates and compatible imports remain available. Image assignment and automatic image sync using a native image-field subclass require 8.11.3. | Settings, mappings, history and imports remain available. |
| 8.11.3 or later | All 27 tools are available when required core classes and methods are present. | Existing behavior is retained. |
| Missing or incomplete MCP runtime | Unsupported tools and workers are disabled, with an update/reinstall message. A version number alone does not enable missing functionality. | Optional MCP lock calls are checked before use. |

`get-schema` returns a `compatibility` object containing the detected core version, minimum versions, settings-update and image-import availability, and actionable upgrade guidance. Unsupported image operations fail with `core_update_required` before downloading photos. Pausing or deleting a source remains possible. Existing checkpoints and source configuration are preserved if a core downgrade prevents workers from running. After updating core, edit/resume the source or reactivate the importer to restore its scheduled events and queued workers.

There are **27 tools**: 22 operations and five generated external-operation readers. No MCP operation clears, deletes or resets import history or logs. The native UI's existing history-clearing controls remain available only in the UI.

## Tools

All names start with `directorist-listing-import-`. All require `manage_options`; feed mutations, source runs, candidate imports and photo imports also require native listing creation permission. Media additionally requires `upload_files`. Listing writes retain native directory, submission, publication and entitlement validation.

| Tool suffix | Behavior |
| --- | --- |
| `get-schema` | Active native mappings, Google source fields, statuses, bounds, provider/storage readiness and WP-Cron diagnostics. |
| `get-settings` | Read saved Google (`source_type: places`) or RSS (`source_type: feed`) settings. Never returns the key. |
| `update-settings` | Preview/save selected native UI settings, including encrypted replacement or removal of the Google key. |
| `get-field-mapping` | Read saved effective per-directory Google mapping, source labels and compatible native destinations. |
| `update-field-mapping` | Preview/save selected mapping rows or restore mapping defaults with `reset:true`. |
| `list-import-history` | Read the actual Google or RSS UI history store, with pagination and safe error counts. No clearing. |
| `list-feeds`, `get-feed` | Read source configuration, version, managed-sync flag, next event and last job ID. Source descriptors reveal only scheme/host. |
| `create-feed`, `update-feed`, `delete-feed` | Preview/save source configuration. Defaults are paused/manual. Active recurring sources use the staged worker. Deletion retains listings/history and refuses active job dependencies. |
| `discover-feed` | Confirm bounded discovery from an approved public RSS/Atom URL or HTML page declaring a feed. |
| `search-places` | Confirm one bounded, potentially billable Google Places text search. Returns rich mapping fields and an opaque continuation. |
| `get-place-details` | Confirm one potentially billable details request for a staged Google candidate. Creates a fresh one-candidate snapshot with mapping data, opening hours, reviews and a private photo reference. |
| `import-photo` | Confirm download of a staged Google photo or feed image enclosure to the media library. Returns an authorized attachment ID. |
| `import-candidates` | Preview/accept a durable job for 1–50 explicit unique candidates. Supports saved Google mappings, reviews, photos, category/location and duplicate handling. |
| `run-feed` | Confirm one selected source fetch and accept a durable import job using the saved RSS status/batch size, source category and enclosure images. Works for one-time and recurring sources. |
| `list-jobs`, `get-job`, `list-import-logs` | Read progress, independent item outcomes, photo warnings, review counts, local events and legacy RSS log summaries. Reads never start work. |
| `cancel-job` | Stop future items at a checkpoint; completed imports remain. This does not pause the source's future sync intervals. |
| `discover-feed-operation`, `search-places-operation`, `get-place-details-operation`, `import-photo-operation`, `run-feed-operation` | Read the original durable external operation without redispatching its provider request or native writer. |

Lists default to 20 (mapping destinations default to 100) and cap at 100. IDs must use the declared JSON type: directory/term/attachment IDs are integers; feed/job/snapshot/candidate/operation IDs are strings. Inputs reject undeclared properties, including history-clearing flags.

## Preview and execute

1. Read `get-schema` for the selected directory. Use native Directorist reference tools for compatible terms and attachments.
2. Preview a mutation with `dry_run:true`; previewing is local and performs no provider requests or product writes.
3. Review the field changes, per-item decisions, scheduling effects and disclosed network/cost bounds.
4. Repeat the exact business input with `expected_version` from `changes.version`, `confirm_token` and a new `idempotency_key`.
5. If interrupted, retry with the **same complete input and idempotency key**. Never replace an unknown operation with a fresh dispatch.

### Saved settings

```json
{
  "source_type": "places",
  "changes": { "default_status": "draft", "max_results": 10 },
  "dry_run": true
}
```

Google settings support `default_status` (`draft`, `pending`, `publish`), `max_results` (1–20), write-only `api_key` (replacement), and `remove_key:true`. Replacement and removal cannot be combined. The key uses the same encrypted native store. Reads, previews, history and retry records never return or persist its plaintext. Credentials are accepted only as bounded write-only local mutation inputs; they remain forbidden in output/read/preview schemas and external-operation payloads.

RSS settings support `default_status` (`pending`, `publish`) and `batch_size` (1–100). Omitted settings are preserved. These changes are visible in the corresponding native UI tab. Omitted candidate-import status uses the selected source's saved default; pass an explicit `status` to override within native policy.

### Saved Google field mappings

```json
{
  "directory_id": 3,
  "mapping": [
    { "source": "editorial_summary", "destination": "skip" },
    { "source": "plus_code", "destination": "field:description" }
  ],
  "dry_run": true
}
```

Discover real destination IDs with `get-field-mapping`; do not assume the example field key exists. It returns all Google source rows with each row's compatible destinations, total and `destinations_has_more`. `page`/`per_page` paginate each row's destination choices. Selected changes preserve omitted source rows; a destination may be assigned only once. `reset:true` restores native defaults for that directory, without affecting other directories or history.

Google imports use saved mappings by default. `use_saved_mapping:false` selects the six-field generic mapping path; an explicit generic `mapping` also selects that path unless `use_saved_mapping:true` is supplied. Generic source fields are `title`, `description`, `address`, `phone`, `email`, and `website`. Saved Google mappings support the native UI's richer sources, including place ID, coordinates, Google Maps URL, ratings, review count, business/place types, price values, Plus Code and service-area flag. Destination writes still pass native listing/field policy; unsupported third-party fields are not written as arbitrary metadata.

### Google import

1. `search-places` with a keyword and either `location` or both `latitude` and `longitude`.
2. Review `get-staged-candidates`. Optionally call `get-place-details` for each selected business and use its returned snapshot ID.
3. Preview/confirm `import-candidates` using explicit candidate IDs and unique `client_ref` values.

```json
{
  "directory_id": 3,
  "snapshot_id": "snapshot-returned-by-details",
  "items": [ { "candidate_id": "candidate-id", "client_ref": "restaurant-001" } ],
  "use_saved_mapping": true,
  "import_reviews": true,
  "import_photos": true,
  "duplicate_mode": "find_new_only",
  "status": "draft",
  "dry_run": true
}
```

`import_reviews` imports the already staged review sample using native deduplication and preserves Google's aggregate rating. Opening hours use the same local native conversion as the UI. `import_photos` downloads the first staged photo before the listing transaction; existing listing photos are retained. Optional photo failures appear as per-item `media_warning` values and do not discard an otherwise valid business. Alternatively, call `import-photo` separately and supply its returned ID in an item's `attachment_ids`.

Search results omit detail-only data until `get-place-details` is executed. Neither listing commits nor history reads implicitly fetch details. Each detail request is separately previewed and potentially billable. Snapshots and Google continuation handles expire for new selection after 15 minutes. Accepted jobs retain their reviewed plans for up to 24 hours, with fresh policy, authority and lifecycle checks before every listing write. Continuations repeat the original query/directory/coordinates/radius/limit, are bound to the initiating user and settings version, and allow at most three pages. Search radius is 0–50,000 metres; page size is 1–20, constrained by saved settings.

`find_new_only` skips duplicates; `update_existing` updates matched listings. Matching uses the native source/legacy source URL or Google Place ID **within the target directory**, never title alone. Ambiguous matches require manual resolution. Omitted owner, entitlements, unrelated fields/taxonomies and existing images remain intact.

### RSS source and scheduled sync

```json
{
  "name": "Restaurant feed",
  "source_url": "https://publisher.example/feed.xml",
  "directory_id": 3,
  "mode": "sync",
  "interval": "hourly",
  "status": "active",
  "dry_run": true
}
```

Recurring sync requires `mode:sync`, active status and an hourly/twicedaily/daily interval. The confirmation binds the administrator who enables it. Each source owns one recurring `dli_mcp_sync_feed` event. Its worker fetches only that source, stages up to the saved RSS batch size, and queues a durable listing job. The legacy global runner excludes managed sources, preventing double imports. Source runs preserve native extracted price, formatted description and past publication date, and can import enclosure images.

Use `update-feed` with `changes:{"status":"paused"}` to stop future intervals, and with active status to resume. UI pause/interval/delete operations also update the managed event. `get-feed.next_run` and `last_job_id` identify scheduling and progress. `run-feed` runs exactly one saved source immediately through the same staged path; it never invokes the global scheduler. An unfinished/unknown job blocks another run of that source.

A serviced **WP-Cron runner** is required for unattended sync and job progress. `get-schema.cron_disabled` reports `DISABLE_WP_CRON`; an external cron runner can service such a site. Activation restores managed events; deactivation unschedules managed sync/worker events. Workers recheck the initiating user's capabilities, plugin lifecycle, directory policy and item state. Future directory/consent/entitlement restrictions are reported as validation or permission failures rather than bypassed.

## History and durability

`list-import-history` accepts `source_type:places|feed`, `page`, `per_page`, optional Unix `after`/`before` and an RSS `feed_id`. Google rows include time, actor ID, keyword/name, location, listing status, counts and MCP job references. Error text/provider bodies are summarized rather than exposed. Existing legacy UI records are included. Completed MCP jobs append to the same native stores, preserving the UI's native retention limits (Google 50 rows, RSS 500 rows).

There is no history-clear, history-delete or log-reset MCP tool. Mapping reset only resets mappings. Settings updates cannot write history options.

Private `{prefix}dli_mcp_records` records retain snapshots, continuations, jobs, photo checkpoints, selected-source runs and safe events. Activation/admin upgrade paths initialize storage; reads and previews never migrate it. Include this table in backups/retention procedures. An item and its terminal listing result commit together under Directorist's shared write lock. Killed listing transactions roll back and resume their original checkpoint. Cancellation retains completed items and prevents subsequent item starts after its committed stop checkpoint.

Photos are separate durable intents. Interrupted image requests are not automatically repeated. Imported attachments are retained in the WordPress media library even if a later listing is rejected or cancelled; they can be reused. No remote request or image filesystem processing occurs inside a listing commit. Local photo metadata creation is part of the photo operation, not listing creation.

Outcomes include created, updated, skipped-duplicate, validation-failed, permission-blocked, cancelled and outcome-unknown. Terminal workers do not continually reschedule. Trusted local recovery remains available through `DLI_MCP_Service::work($job_id)` and `DLI_MCP_Service::reconcile_item($job_id,$client_ref)`. Reconciliation proves durable listing markers; it does not insert another listing. External-operation readers similarly never rerun a provider or native writer.

## Network and bounds

Public source requests use verified DNS, cURL address pinning, TLS verification, disabled proxies, validated redirect targets, a ten-second timeout and a one MiB text-response ceiling. Public HTTP(S) ports 80/443 only; credentials/proxy queries, private/reserved addresses, external XML declarations and arbitrary page scraping are excluded. Google authenticated requests use fixed endpoints and never follow redirects with the key. Photo downloads admit JPEG, PNG, GIF or WebP up to 10 MiB and 40 million pixels; provider-returned CDN URLs are constrained to public HTTPS Googleusercontent hosts and never returned to callers.

Google searches, details and photo lookup intents share the existing native UI quota of 100 intents/hour/user, including failed and interrupted attempts. Provider data and continuations are not credential-management interfaces. There is no implicit geocode, unbounded pagination or retry-everything tool.

## Verification

The local fixture suite is excluded from Git under `/tests/` and requires a disposable `codex_import_*` database and blocks running against the working site's database:

```sh
wp --path=/path/to/disposable/site eval-file wp-content/plugins/directorist-listing-import/tests/mcp-ui-parity.php
```

It exercises registration, encrypted settings, write-only schema boundaries, stale confirmations, saved mappings/reset, real UI history reads, no history-clearing tools, detailed Google imports/photos/reviews, RSS source runs/images/price, scheduled sync, duplicate prevention, pause, revoked authority and shared history. Provider responses are fixtures; live Google credentials/billing and production DNS/transport require environment-specific validation.

Additional local development suites, where available:

```sh
wp --path=/path/to/disposable/site eval-file wp-content/plugins/directorist-listing-import/tests/mcp-integration.php
python3 wp-content/plugins/directorist-listing-import/tests/mcp-concurrency.py /path/to/disposable/site
```
