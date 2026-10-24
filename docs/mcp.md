# Listing Import MCP integration

Listing Importer 1.2.0 contributes extension API 1 tools under owner `listing-import`, contract `1.0.0`. It uses Directorist's existing server, permission guards, confirmation tokens and durable external-operation records. No alternative server, generated bulk action, credential tool or global cron runner is registered.

Install the accompanying Directorist core changes with this extension. Those changes keep the native MCP listing image path free of filesystem work, preserve omitted image references, and allow a currently authorized administrator to inspect an external operation initiated by another administrator. Current tool permission and extension admission still apply.

## Available tools

All names start with `directorist-listing-import-`. Every tool requires `manage_options`; feed mutations and importing also require native listing creation capability. Listing writes apply the current native object, directory, submission, publication and entitlement policies.

| Tools | Behavior |
| --- | --- |
| `get-schema` | Effective directory mappings, required fields, statuses, sources, bounds, provider configuration flag and scheduler prerequisite. |
| `list-feeds`, `get-feed` | Local configuration reads; source descriptors contain only scheme and host. |
| `create-feed`, `update-feed`, `delete-feed` | Previewed configuration changes. Creation explicitly passes `paused` and `manual`; it does not run an import. Deletion retains imported listings and refuses active job dependencies. |
| `discover-feed` | Confirmed bounded RSS/Atom discovery from an explicitly approved public URL. |
| `search-places` | Confirmed, potentially billable, single Google Places text-search POST. |
| `discover-feed-operation`, `search-places-operation` | Generated readers of the original durable operation. They never send a new provider request or rerun the native writer. |
| `get-staged-candidates` | Authorized local staged reads with duplicate hints, quality, expiry and an opaque continuation. |
| `import-candidates` | One durable job for 1–50 explicit, unique candidate IDs and client references. Each item is independent. |
| `list-jobs`, `get-job`, `list-import-logs` | Local progress, per-item outcomes, safe references, filtered totals and pagination. No read starts a worker. |
| `cancel-job` | Stops future items at the next lock/checkpoint and retains completed results. |

Lists default to 20 and cap at 100. Inputs are closed and typed; numeric strings cannot substitute for JSON integer IDs. `get-schema.source_type` identifies the intended native source; both supported source families use the same admitted directory mappings.

## Preview and execute

1. Read schema for a real directory ID and list existing term/attachment references using native Directorist tools.
2. Call a mutation with `dry_run: true`.
3. Inspect its per-item plan or external cost/limit description.
4. Repeat the exact business input with `expected_version` from `changes.version`, the `confirm_token`, and a new `idempotency_key`.
5. Retry an interrupted request using that same complete input and idempotency key. An unknown external outcome never authorizes a fresh dispatch.

A feed example (the source must be a supported feed you are permitted to use):

```json
{
  "name": "Reviewed restaurant feed",
  "source_url": "https://publisher.example/feed.xml",
  "directory_id": 3,
  "dry_run": true
}
```

Discovery uses `source_url`, `source_approved: true`, `directory_id`, optional `sample_size` (1–20; default 5), and optional `feed_id`. An attached feed's source must match the selected configuration.

Places search requires `directory_id`, `keyword`, and either `location` or both `latitude` and `longitude`. Radius is 0–50,000 metres. `result_limit` is 1–20 and is also constrained by the native maximum. A continuation must repeat the original query, directory, coordinates, radius and limit and supply its opaque handle. It belongs to its initiating user, expires in 15 minutes, can be consumed by only one durable operation, and is bounded to three pages. Each page is a separate previewed external operation and counts against the quota.

An import example:

```json
{
  "snapshot_id": "opaque-snapshot-id",
  "directory_id": 3,
  "items": [
    { "candidate_id": "opaque-candidate-id", "client_ref": "reviewed-business-1" }
  ],
  "status": "draft",
  "duplicate_mode": "find_new_only",
  "mapping": [
    { "source": "title", "target": "title" },
    { "source": "description", "target": "description" }
  ],
  "dry_run": true
}
```

Supported source fields are `title`, `description`, `address`, `phone`, `email`, and `website`. Targets are discovered from active native directory definitions. Optional `category_id` must reference an existing compatible term. Optional explicit native `consents` must satisfy the current listing policy. `find_new_only` and `update_existing` are a single mutually exclusive `duplicate_mode` enum. Matching uses the native source URL/legacy source URL or Google place ID within the target directory, never title alone. Ambiguous duplicates fail for manual resolution.

Creation defaults to draft. Native policy decides whether pending/publish are permitted and the effective status of updates. Preview shows that status. Required or unsupported fields and entitlement restrictions produce independent validation outcomes without guessing extension metadata. Omitted custom fields, owner, financial values, unrelated taxonomy assignments and existing images remain intact.

## Durable execution and recovery

`admin_init` and plugin activation perform the versioned InnoDB storage upgrade. Discovery and preview never migrate storage. If storage is unavailable, writes fail with a prerequisite message.

`{prefix}dli_mcp_records` contains private snapshots, continuation handles, jobs and safe events. Jobs persist the accepted per-item plan and schema/lifecycle fingerprint. Snapshot data expires for use after 15 minutes. Records are durable and are not automatically deleted by cache eviction; expiry rejects access rather than silently refreshing. Administrators should include this table in the site's data retention and backup procedures. Core's private external-operation/idempotency records retain provider evidence and deduplication references under its own contract.

Confirmed job acceptance schedules only the selected job's worker. Before a native write the worker reserves a recovery wake-up, persists a running checkpoint, rechecks the initiating user's current capabilities and the active extension, checks freshness and compares the current independent plan. The native listing and its terminal outcome/event commit in one transaction under Directorist's shared database lock. Native feed CRUD/import and Google AJAX entry points participate in this lock. A killed process rolls back its uncommitted listing; recovery resumes the original checkpoint. Terminal workers do not continually reschedule themselves. WP-Cron must be serviced for unattended progress.

Outcomes are `created`, `updated`, `skipped-duplicate`, `validation-failed`, `permission-blocked`, `cancelled`, and `outcome-unknown`, plus queued/running progress. Cancellation cannot undo completed listings or an external request. An item already inside its guarded transaction finishes before a cancellation can commit; no subsequent item starts after that committed stop checkpoint.

There is no retry-everything tool. A trusted local administrator may invoke:

```php
DLI_MCP_Service::work( $existing_job_id );
DLI_MCP_Service::reconcile_item( $existing_job_id, $client_ref );
```

Reconciliation proves a matching durable item marker and native source/directory identity. It never inserts or updates a listing. Missing/inconsistent evidence leaves the outcome unknown. If a staged result persisted but its external operation's final bookkeeping was interrupted, core's local `External_Operations::complete( $operation_id, $native_result )` verifies that existing result; it does not call the native writer. Google queries with no retained result cannot be reconciled at the provider and remain unknown without automatic redispatch.

## Network, credentials and bounds

Previews are local, including URL syntax checks. Confirmed dispatch resolves public DNS, validates all returned addresses, pins cURL to a verified address, disables proxies/automatic redirects, verifies TLS certificates and validates every redirect target. Only public HTTP(S) on ports 80/443 is admitted; embedded credentials, credential/proxy query parameters, private/link-local/metadata/reserved addresses, external XML declarations and oversized payloads fail safely. Each fetch has a 10-second timeout, a one MiB response ceiling and at most three redirects. HTML is used only to locate a declared RSS/Atom alternate link; arbitrary page scraping is excluded.

Places uses the server-selected native Google endpoint and the existing encrypted credential store. It performs no implicit geocode, detail, image or retry calls. Every search/continuation intent, including failed and interrupted attempts, counts towards 100 requests/hour/user together with native UI runs. Native UI quota checks use the same serialized combined count. Credentials, provider continuation strings, signed image URLs and provider error bodies are never returned. Safe log reasons identify transport, source, quota and validation failures.

Media is limited to existing authorized attachment references, optionally supplied as each item's `attachment_ids`. Remote photo imports are excluded from this implementation. Consequently jobs create no staged files or unattached temporary assets requiring cleanup, and listing commits perform no filesystem or remote fetch work.

Automatic feed activation through MCP remains blocked with `scheduler_prerequisite`: native scheduled workers still combine remote fetching and listing writes and do not provide the required staging/checkpoint bounds. Paused feed configuration remains manageable. MCP never runs the global scheduler or every due feed. No native UI redesign is included.

## Verification

The verification suites are local development files under ignored `/tests/` and are not committed or distributed with the plugin. With those local fixtures available, run only against a disposable `codex_import_*` database with Directorist and Listing Importer active:

```sh
wp --path=/path/to/disposable/site eval-file wp-content/plugins/directorist-listing-import/tests/mcp-integration.php
python3 wp-content/plugins/directorist-listing-import/tests/mcp-concurrency.py /path/to/disposable/site
```

The integration suite covers registration of all 16 tools, pure previews/reads, defaults, source rejection/redaction, confirmed discovery and replay, selected candidates, duplicates, policy rejection, native field/ownership/media preservation, stale confirmations, interruption/recovery, cancellation, revoked authority, deactivation, lifecycle changes, pagination, encrypted Places fixtures, user/query-bound continuation limits, combined quotas, both operation readers, completion proofs, redirects to private IPs, oversized responses and indeterminate timeouts.

The process suite exits a worker after native insertion inside its transaction, verifies rollback and the durable checkpoint, starts two simultaneous recovery workers, and proves exactly one committed listing and stable worker reentry.

Provider responses in these tests are fixtures. A real public HTTPS fetch also verified DNS pinning and TLS on the local cURL transport. Native feed/log and Google import/history pages plus the native listing form/access guard received server-render smoke checks. Live Google credentials/billing, production DNS/transport behavior, browser interaction/published-page rendering, and third-party extension combinations still require release-environment checks. Do not infer live provider certification from fixture results.
