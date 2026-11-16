# Amenity and Ticket database index audit

## A. Environment and scope

- Date: 2026-10-16. Repository: `UMISORA09/QUANLY_TOANHA-DANCU`.
- Branch: `DangNguyen/optimize-amenity-ticket-database-indexes`.
- Base: `origin/master`, `9978f9cb1877cb30607399854456ae24f119b957`.
- Final commit: not committed; no push, merge, production migration or deployment.
- Verified runtime: Laravel **13.30.1**, PHP **8.4.25**, MySQL **8.0.46**, PHPUnit **12.5.34**, Pint **1.31.0**. React 19, TypeScript 7; build used Vite **8.3.0**. The original task's Laravel 12 version is not the installed version.
- Read `AGENTS.md`; `.ai/rules` does not exist. Reviewed schema, migration index definitions, repository/service/controller queries, model relations and existing tests. All existing migrations ran on newly created disposable MySQL databases.
- Benchmark database: `amenity_ticket_index_audit_final_test`; regression database: `amenity_ticket_regression_index_audit_test`. Both separate from development/team/production. Test HTTP server binds container localhost, uses `APP_ENV=testing` and four workers. No real payment or external SePay request is involved.
- Earlier candidate runs used separate disposable databases: `dangnguyen_index_audit_20261016_test` and `amenity_ticket_index_audit_50000_test`.

## B. Feature results and query mapping

| Feature | Current indexes / query | Finding | Change / decision | Verification |
|---|---|---|---|---|
| F1 Catalog/search | `idx_amenities_perf`, unique `amenity_code`, FULLTEXT, unique slot day/time | Empty search is SQL-paginated. Nonempty Smart Search loads filtered candidates and ranks in PHP; FULLTEXT is not used by this path. | Preserve ranking, synonyms, accents, fuzzy matches and filter semantics. Existing catalog/slot indexes retained. | MySQL plans for listing, exact code, candidate retrieval and slots; SearchEngineTest. Fuzzy performance remains a limitation. |
| F2 Resident booking | Resident/date index orders by usage date; API orders by creation time and ID | Personal history filesorts a large history. Valid residency columns are DATE. | Add resident/deleted/creation/ID index; direct DATE comparisons with explicit date-only boundaries. | MySQL plan and booking/residency regression tests. |
| F3 Capacity/overlap | Conflict `(amenity_id,booking_date,status)`, performance `(amenity_id,status,deleted_at)`, apartment/date/time | `DATE(booking_date)` prevents direct date lookup; blackout table has only PK on fresh migrated MySQL. | Direct comparisons; add blackout amenity/date. Preserve holding-payment predicate, overlap comparisons, parent lock order and row locks. | Day-boundary/lock regression, existing overlap/capacity/blackout tests, HTTP races. |
| F4 Approvals | Booking PK, payment booking/reference/bank transaction UNIQUEs, status/expiry | Global pending worklist lacks creation order index. Empty-payment synthetic data initially hid the benefit of a status/order index. | Add status/deleted/creation/ID after measuring again with 50,000 payment rows. No unique, payment, expiry or approval behavior changed. | Worklist plan, approval/payment/closure regression and HTTP races. REPORTED/REVIEW still use payment status/expiry + unique booking lookup; no additional speculative payment index. |
| F5 Incident reports | Creator/creation, apartment/creation, creator/idempotency UNIQUE | Resident creator OR valid apartment list sorts many matching tickets. | Add deleted/creation DESC/ID ASC index for newest-first lists; existing ownership/filter predicates untouched. | Resident list plan, Ticket ACL/idempotency/pagination tests. Six statistics COUNT queries are unchanged, not represented by the LIMIT-list timings. |
| F6 Private images | No ticket_id index exists on fresh MySQL | Eager-load WHERE ticket_id IN (...) scans attachments. Inline REFERENCES in the source schema did not create this FK/index in actual MySQL. | Add ticket_id BTREE only; no path index or unnecessary creation-time composite. | Attachment IN-list plan, private photo/IDOR/MIME/rollback tests. |
| F7 Management | Status/priority, status/SLA, apartment/creation; scope EXISTS apartment/block | Scoped status listing still starts from apartment and sorts. A status/creation candidate did not improve the initial measured plan. | Keep scope and API unchanged; do not add unproven category/status indexes. Global newest list benefits from F5 index. | Scoped manager plan measured; manager-filter latency remains, not claimed fixed. Selective category workload NOT MEASURED. |
| F8 Workflow | Technician/status; notification recipient/read/creation; history PK only | Technician newest list, ordered ticket history and category-specific notification feed scan/sort. | Add technician/deleted/creation/ID, history ticket/creation, notification recipient/category/creation/ID. Version, history writes, recipient checks and state machine untouched. | Plans, state/permission tests and HTTP version/assignment/retry races. |

Queries retain LIMIT/OFFSET sizes and sorting directions (Ticket: created DESC, ID ASC; notifications: created DESC, ID DESC). Mixed-direction columns are explicit; appending ID is necessary for deterministic ties. Counts and EXISTS retain their original scope. UPDATE/locking by UUID still uses the primary key.

## C. Migration decisions and safety

New migration: `database/migrations/2026_10_16_072349_optimize_amenity_ticket_lookup_indexes.php`. Eight measured nonunique BTREE indexes; old migrations and constraints are untouched.

- Detect equivalent declared BTREE prefixes by ordered columns and absence of prefix truncation, regardless of index name; ignore direction on equality columns and recognize backward scans for creation/ID ordering. This includes PRIMARY, UNIQUE and FK-supporting indexes reported by MySQL. FULLTEXT is not mistaken for BTREE. Production review should also examine implicit InnoDB primary-key extensions, which are not declared columns in SHOW INDEX.
- Refuse an incompatible index with the intended name. Persist an ownership comment when creating an index. A fresh migration object/process on rollback removes only exact owned nonunique definitions, never an index merely because its name matches.
- Rollback preserves an owned index if it is the remaining support for a foreign key; MySQL may discard an older automatically generated supporting index when a composite is added. This deliberate retention is safer than breaking the constraint and is covered by a real MySQL FK test.
- MySQL-only index DDL; other drivers no-op. No new table/model/package or data type change.
- Reference `DangNguyen/Fix_all` migration was inspected, not copied: its name-only existence checks and down() could remove pre-existing indexes or recreate indexes it never removed.
- `idx_amenities_code_btree` duplicates unique `amenity_code` in fresh MySQL; exact lookup selected the UNIQUE. Kept for now: cleanup of historical indexes is deliberately separate from additive optimization/ownership rollback, and production metadata has not been inspected. No UNIQUE was removed.
- `idx_tickets_apt` and `idx_tickets_apt_created` share column names but differ in creation-time direction. Do not classify them as identical just from column names; the implicit PK tie direction matters.
- Keep existing conflict/date, slot UNIQUE, payment UNIQUE, idempotency UNIQUE, resident, apartment, staff and unread-notification indexes. No unique capacity restriction added.

## D. Measurement method and limits

Real SELECTs on MySQL 8, seven timings per query, median of client-observed execution + result-fetch time, with ANALYZE TABLE before each phase. Full SQL, bindings, EXPLAIN keys and EXPLAIN ANALYZE execution trees appear below. Benchmark candidates use `idx_audit_*` names; the migration uses descriptive production names with identical column order/directions. Candidate indexes are dropped in finally. The database/data are disposable and retained for inspection.

Final dataset: 50,000 each bookings, payments, blackouts, tickets, history, attachments and notifications; 1,007 amenities. Attachments have at most five rows per ticket. Skew intentionally stresses one account/amenity/apartment; statuses vary, notifications mix TICKET/SYSTEM. These are SQL load fixtures, not transactions claimed to have been accepted by booking business rules. Fake payment rows do not represent money received. One category/block and one slot are insufficient for broad catalog/category selectivity conclusions.

These are representative SELECT benchmarks, not timings of complete API requests/screens. Worklist totals/expire(), the six resident Ticket counters, permission checks, eager-loaded related records and PHP fuzzy ranking are additional work outside the corresponding LIMIT-query medians. The worklist probe retains the amenity/payment joins; its display-only user/apartment joins and narrower controller projection are not part of that probe. End-to-end API correctness is checked separately below; no whole-page speedup percentage is claimed.

Earlier 10,000- and 50,000-row candidate runs informed choices; the final evidence includes payment rows and a newest-ticket index omitted in earlier candidates. Do not compare timings across differently seeded runs as if only one index changed. CPU/cache/I/O are shared on a development laptop; these are measured local workload results, not production SLA or throughput guarantees. The unchanged queries serve as noise controls.

Smart Search cannot safely use MATCH as an exclusive candidate filter: short Vietnamese tokens, synonyms and Levenshtein matches can be absent from FULLTEXT results. Actual computeRelevanceScore matches name/code, despite the older class comment claiming eight searchable fields; SearchEngineTest explicitly rejects matches only in description/location/category. SQL narrows category/block/activity/deletion. An index alone cannot make LIKE '%keyword%' or arbitrary PHP fuzzy ranking indexed. No result-capping or FULLTEXT substitution was introduced.

## E. Verification

Final results and commands are recorded at the end after all checks finish. Backend changes are limited to DATE predicates and the new additive index migration. No frontend behavior changed. Initial Playwright launch used the wrong Vite output root and was stopped; rerun serves `public` explicitly, including `/build/assets`.

## F. Risks and outstanding evidence

- Production schema/index existence, Vercel runtime, real bank settlement and real SePay delivery: NOT VERIFIED; no production access/mutation/deploy in this task.
- Sparse resident access, many blocks/categories, deep OFFSET pages and high-volume simultaneous writes need production-like distribution/load testing. Scoped manager list and fuzzy candidate/ranking remain potential bottlenecks.
- Eight indexes consume storage and add INSERT/UPDATE maintenance. Read plans are measured; sustained write throughput and DDL lock duration on production-sized tables are NOT MEASURED. Status/technician updates maintain affected indexes. Apply during an appropriate deployment window after schema review.
- Additional indexes can affect optimizer access paths and row-lock ranges. Existing parent locks, transactions and strict interval predicates are unchanged; true concurrent tests are run separately against QA.
- Historical redundant index cleanup deferred; no claim that all old index duplication is removed. Category-only and REPORTED/REVIEW queue performance at scale need additional distribution-specific measurement.
- Audit is scoped to amenity/Ticket indexes. No changes to reception, invoices, RFID, shared search ranking, dependencies or CI workflows.

## G. Measured inventory and execution evidence

Inventory below is SHOW INDEX from the newly migrated benchmark database BEFORE this migration, not evidence of the production schema. ASC/DESC and prefix length are included.

| Table | Index | Ordered columns | Type | Before / decision |
|---|---|---|---|---|
| amenities | amenity_code | amenity_code ASC | BTREE/UNIQUE | Present / keep |
| amenities | idx_amenities_code_btree | amenity_code ASC | BTREE | Present / keep |
| amenities | idx_amenities_name_code_ft | amenity_name, amenity_code | FULLTEXT | Present / keep |
| amenities | idx_amenities_perf | deleted_at ASC, is_active ASC, category_id ASC, block_id ASC, created_at ASC | BTREE | Present / keep |
| amenities | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| amenity_categories | category_code | category_code ASC | BTREE/UNIQUE | Present / keep |
| amenity_categories | category_name | category_name ASC | BTREE/UNIQUE | Present / keep |
| amenity_categories | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| amenity_time_slots | idx_amenity_slots_perf | amenity_id ASC, is_active ASC | BTREE | Present / keep |
| amenity_time_slots | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| amenity_time_slots | uq_amenity_day_slot | amenity_id ASC, day_of_week ASC, slot_start_time ASC, slot_end_time ASC | BTREE/UNIQUE | Present / keep |
| amenity_blackouts | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| amenity_bookings | booking_code | booking_code ASC | BTREE/UNIQUE | Present / keep |
| amenity_bookings | checkin_qr_code | checkin_qr_code ASC | BTREE/UNIQUE | Present / keep |
| amenity_bookings | idx_amenity_bookings_perf | amenity_id ASC, status ASC, deleted_at ASC | BTREE | Present / keep |
| amenity_bookings | idx_bookings_apt_date_time | apartment_id ASC, booking_date ASC, start_time ASC | BTREE | Present / keep |
| amenity_bookings | idx_bookings_conflict_lookup | amenity_id ASC, booking_date ASC, status ASC | BTREE | Present / keep |
| amenity_bookings | idx_bookings_date | booking_date ASC | BTREE | Present / keep |
| amenity_bookings | idx_bookings_resident | resident_user_id ASC, booking_date DESC | BTREE | Present / keep |
| amenity_bookings | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| amenity_booking_payments | amenity_booking_payments_booking_id_unique | booking_id ASC | BTREE/UNIQUE | Present / keep |
| amenity_booking_payments | amenity_booking_payments_confirmed_by_user_id_foreign | confirmed_by_user_id ASC | BTREE | Present / keep |
| amenity_booking_payments | amenity_booking_payments_reference_unique | reference ASC | BTREE/UNIQUE | Present / keep |
| amenity_booking_payments | amenity_booking_payments_status_expires_at_index | status ASC, expires_at ASC | BTREE | Present / keep |
| amenity_booking_payments | amenity_payment_bank_transaction_unique | bank_bin ASC, bank_transaction_id ASC | BTREE/UNIQUE | Present / keep |
| amenity_booking_payments | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| tickets | idx_tickets_apt | apartment_id ASC, created_at DESC | BTREE | Present / keep |
| tickets | idx_tickets_apt_created | apartment_id ASC, created_at ASC | BTREE | Present / keep |
| tickets | idx_tickets_creator_created | creator_user_id ASC, created_at ASC | BTREE | Present / keep |
| tickets | idx_tickets_status | status ASC, priority ASC | BTREE | Present / keep |
| tickets | idx_tickets_status_sla | status ASC, sla_deadline ASC | BTREE | Present / keep |
| tickets | idx_tickets_tech | current_technician_user_id ASC, status ASC | BTREE | Present / keep |
| tickets | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| tickets | ticket_number | ticket_number ASC | BTREE/UNIQUE | Present / keep |
| tickets | tickets_creator_idempotency_unique | creator_user_id ASC, idempotency_key ASC | BTREE/UNIQUE | Present / keep |
| ticket_categories | category_code | category_code ASC | BTREE/UNIQUE | Present / keep |
| ticket_categories | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| ticket_attachments | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| ticket_history | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| user_in_app_notifications | idx_in_app_notifs | recipient_user_id ASC, is_read ASC, created_at DESC | BTREE | Present / keep |
| user_in_app_notifications | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| residents | idx_residents_active_stay | is_active ASC, stay_start_date ASC | BTREE | Present / keep |
| residents | idx_residents_apt | apartment_id ASC, is_active ASC | BTREE | Present / keep |
| residents | idx_residents_apt_active_head | apartment_id ASC, is_active ASC, is_head_of_household ASC | BTREE | Present / keep |
| residents | idx_residents_type_active | resident_type ASC, is_active ASC | BTREE | Present / keep |
| residents | idx_residents_user_active | user_id ASC, is_active ASC | BTREE | Present / keep |
| residents | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| residents | uq_resident_apt | user_id ASC, apartment_id ASC | BTREE/UNIQUE | Present / keep |
| apartments | idx_apartments_block_floor_del | block_id ASC, floor_id ASC, deleted_at ASC | BTREE | Present / keep |
| apartments | idx_apartments_block_status | block_id ASC, status ASC | BTREE | Present / keep |
| apartments | idx_apartments_block_status_del | block_id ASC, status ASC, deleted_at ASC | BTREE | Present / keep |
| apartments | idx_apartments_current_resident | current_resident_user_id ASC | BTREE | Present / keep |
| apartments | idx_apartments_floor_del | floor_id ASC, deleted_at ASC | BTREE | Present / keep |
| apartments | idx_apartments_number_del | apartment_number ASC, deleted_at ASC | BTREE | Present / keep |
| apartments | idx_apartments_status_del | status ASC, deleted_at ASC | BTREE | Present / keep |
| apartments | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| apartments | uq_apartment_number | block_id ASC, apartment_number ASC | BTREE/UNIQUE | Present / keep |
| staff_profiles | employee_code | employee_code ASC | BTREE/UNIQUE | Present / keep |
| staff_profiles | PRIMARY | id ASC | BTREE/PRIMARY | Present / keep |
| staff_profiles | user_id | user_id ASC | BTREE/UNIQUE | Present / keep |

### Final added indexes

| Table | Index | Columns | Beneficiary |
|---|---|---|---|
| amenity_blackouts | idx_blackouts_amenity_date | amenity_id, blackout_date | F3 daily closures |
| ticket_attachments | idx_ticket_attachments_ticket | ticket_id | F6 eager-loaded photo lists |
| ticket_history | idx_ticket_history_ticket_created | ticket_id, created_at | F8 ordered history |
| user_in_app_notifications | idx_notifications_recipient_category_created | recipient_user_id, category, created_at DESC, id DESC | F8/F2 category feed |
| tickets | idx_tickets_technician_created | current_technician_user_id, deleted_at, created_at DESC, id ASC | F8 assigned work |
| tickets | idx_tickets_created | deleted_at, created_at DESC, id ASC | F5/F7 newest list |
| amenity_bookings | idx_bookings_resident_created | resident_user_id, deleted_at, created_at DESC, id ASC | F2 personal history |
| amenity_bookings | idx_bookings_status_created | status, deleted_at, created_at DESC, id ASC | F4 pending worklist |

### Row counts

```json
{
  "amenities": 1007,
  "amenity_categories": 2,
  "amenity_time_slots": 1,
  "amenity_blackouts": 50000,
  "amenity_bookings": 50000,
  "amenity_booking_payments": 50000,
  "tickets": 50000,
  "ticket_categories": 1,
  "ticket_attachments": 50000,
  "ticket_history": 50000,
  "user_in_app_notifications": 50000,
  "residents": 2,
  "apartments": 3,
  "staff_profiles": 0
}
```

### Median SELECT timings (milliseconds, seven samples)

| Query | Before ms | After ms | After EXPLAIN key(s) |
|---|---:|---:|---|
| blackout_date_function | 35.883 | 24.279 | idx_audit_blackout_day |
| blackout_direct | 21.616 | 1.207 | idx_audit_blackout_day |
| holds_date_function | 34.563 | 34.108 | idx_bookings_conflict_lookup, amenity_booking_payments_booking_id_unique |
| holds_direct | 3.129 | 3.537 | idx_bookings_date, amenity_booking_payments_booking_id_unique |
| amenity_list | 0.882 | 0.891 | idx_amenities_perf |
| amenity_exact_code | 0.542 | 0.674 | amenity_code |
| smart_search_candidates | 7.533 | 7.573 | idx_amenities_perf, none, PRIMARY |
| slots | 0.632 | 0.683 | uq_amenity_day_slot |
| booking_worklist | 94.901 | 1.264 | idx_audit_booking_status_time, PRIMARY, amenity_booking_payments_booking_id_unique |
| resident_bookings | 349.156 | 0.999 | idx_audit_booking_resident_time, PRIMARY, PRIMARY |
| resident_tickets | 159.553 | 0.944 | idx_audit_ticket_created |
| manager_tickets | 68.801 | 66.938 | uq_apartment_number, idx_tickets_status_sla |
| technician_tickets | 45.927 | 0.940 | idx_audit_ticket_tech_time |
| history | 33.742 | 3.566 | idx_audit_history_time |
| attachments | 36.208 | 1.598 | idx_audit_attachment_ticket |
| notifications | 115.758 | 0.812 | idx_audit_notification_category |

DATE predicate rewrites compare blackout_date_function BEFORE to blackout_direct AFTER, and holds_date_function BEFORE to holds_direct AFTER. Same-SQL rows above isolate index effects; unchanged controls may be slower within timing noise.

### blackout_date_function

```sql
select * from `amenity_blackouts` where `amenity_id` = ? and date(`blackout_date`) = ?
```

Bindings (synthetic QA identifiers only):
```json
["3a05feb1-1754-4f35-beb2-444431e81810","2027-01-01"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Filter: ((amenity_blackouts.amenity_id = '3a05feb1-1754-4f35-beb2-444431e81810') and (cast(amenity_blackouts.blackout_date as date) = '2027-01-01'))  (cost=668 rows=5151) (actual time=0.18..38.7 rows=149 loops=1)
    -> Table scan on amenity_blackouts  (cost=668 rows=51505) (actual time=0.0446..20.5 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Index lookup on amenity_blackouts using idx_audit_blackout_day (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810'), with index condition: (cast(amenity_blackouts.blackout_date as date) = '2027-01-01')  (cost=2611 rows=21523) (actual time=0.397..24.3 rows=149 loops=1)

```

### blackout_direct

```sql
select * from `amenity_blackouts` where `amenity_id` = ? and `blackout_date` = ?
```

Bindings (synthetic QA identifiers only):
```json
["3a05feb1-1754-4f35-beb2-444431e81810","2027-01-01"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Filter: ((amenity_blackouts.blackout_date = DATE'2027-01-01') and (amenity_blackouts.amenity_id = '3a05feb1-1754-4f35-beb2-444431e81810'))  (cost=4840 rows=515) (actual time=0.123..23 rows=149 loops=1)
    -> Table scan on amenity_blackouts  (cost=4840 rows=51505) (actual time=0.0407..19.1 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Index lookup on amenity_blackouts using idx_audit_blackout_day (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810', blackout_date=DATE'2027-01-01')  (cost=52.1 rows=149) (actual time=0.0501..0.3 rows=149 loops=1)

```

### holds_date_function

```sql
select * from `amenity_bookings` where `amenity_id` = ? and `deleted_at` is null and `status` in (?, ?, ?, ?) and date(`booking_date`) = ? and not exists (select 1 from `amenity_booking_payments` where `amenity_booking_payments`.`booking_id` = `amenity_bookings`.`id` and (`amenity_booking_payments`.`status` in (?, ?, ?) or (`amenity_booking_payments`.`status` = ? and `amenity_booking_payments`.`expires_at` <= ?) or (`amenity_booking_payments`.`status` = ? and CONCAT(amenity_bookings.booking_date, " ", amenity_bookings.start_time) <= ?)))
```

Bindings (synthetic QA identifiers only):
```json
["3a05feb1-1754-4f35-beb2-444431e81810","PENDING","APPROVED","CONFIRMED","CHECKED_IN","2027-01-01","EXPIRED","CANCELLED","REVIEW","PENDING","2026-10-16T00:31:20.117028Z","REPORTED","2026-10-16T00:31:20.117107Z"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Nested loop antijoin  (cost=1435 rows=993) (actual time=0.0506..32.7 rows=149 loops=1)
    -> Filter: (amenity_bookings.deleted_at is null)  (cost=1087 rows=993) (actual time=0.0332..31.2 rows=149 loops=1)
        -> Index lookup on amenity_bookings using idx_bookings_conflict_lookup (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810'), with index condition: ((amenity_bookings.`status` in ('PENDING','APPROVED','CONFIRMED','CHECKED_IN')) and (cast(amenity_bookings.booking_date as date) = '2027-01-01'))  (cost=1087 rows=24832) (actual time=0.032..31.2 rows=149 loops=1)
    -> Filter: ((amenity_booking_payments.`status` in ('EXPIRED','CANCELLED','REVIEW')) or ((amenity_booking_payments.`status` = 'PENDING') and (amenity_booking_payments.expires_at <= '2026-10-16 07:31:20')) or ((amenity_booking_payments.`status` = 'REPORTED') and (concat(amenity_bookings.booking_date,' ',amenity_bookings.start_time) <= '2026-10-16 07:31:20')))  (cost=0.25 rows=1) (actual time=0.00984..0.00984 rows=0 loops=149)
        -> Single-row index lookup on amenity_booking_payments using amenity_booking_payments_booking_id_unique (booking_id=amenity_bookings.id)  (cost=0.25 rows=1) (actual time=0.00936..0.00939 rows=1 loops=149)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Nested loop antijoin  (cost=10343 rows=20790) (actual time=0.0526..31.4 rows=149 loops=1)
    -> Filter: (amenity_bookings.deleted_at is null)  (cost=3067 rows=20790) (actual time=0.0352..30.4 rows=149 loops=1)
        -> Index lookup on amenity_bookings using idx_bookings_conflict_lookup (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810'), with index condition: ((amenity_bookings.`status` in ('PENDING','APPROVED','CONFIRMED','CHECKED_IN')) and (cast(amenity_bookings.booking_date as date) = '2027-01-01'))  (cost=3067 rows=20790) (actual time=0.0344..30.4 rows=149 loops=1)
    -> Filter: ((amenity_booking_payments.`status` in ('EXPIRED','CANCELLED','REVIEW')) or ((amenity_booking_payments.`status` = 'PENDING') and (amenity_booking_payments.expires_at <= '2026-10-16 07:31:20')) or ((amenity_booking_payments.`status` = 'REPORTED') and (concat(amenity_bookings.booking_date,' ',amenity_bookings.start_time) <= '2026-10-16 07:31:20')))  (cost=0.25 rows=1) (actual time=0.00619..0.00619 rows=0 loops=149)
        -> Single-row index lookup on amenity_booking_payments using amenity_booking_payments_booking_id_unique (booking_id=amenity_bookings.id)  (cost=0.25 rows=1) (actual time=0.00571..0.00574 rows=1 loops=149)

```

### holds_direct

```sql
select * from `amenity_bookings` where `amenity_id` = ? and `deleted_at` is null and `status` in (?, ?, ?, ?) and `booking_date` = ? and not exists (select 1 from `amenity_booking_payments` where `amenity_booking_payments`.`booking_id` = `amenity_bookings`.`id` and (`amenity_booking_payments`.`status` in (?, ?, ?) or (`amenity_booking_payments`.`status` = ? and `amenity_booking_payments`.`expires_at` <= ?) or (`amenity_booking_payments`.`status` = ? and CONCAT(amenity_bookings.booking_date, " ", amenity_bookings.start_time) <= ?)))
```

Bindings (synthetic QA identifiers only):
```json
["3a05feb1-1754-4f35-beb2-444431e81810","PENDING","APPROVED","CONFIRMED","CHECKED_IN","2027-01-01","EXPIRED","CANCELLED","REVIEW","PENDING","2026-10-16T00:31:20.117211Z","REPORTED","2026-10-16T00:31:20.117224Z"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Nested loop antijoin  (cost=103 rows=146) (actual time=0.0381..1.13 rows=149 loops=1)
    -> Filter: ((amenity_bookings.amenity_id = '3a05feb1-1754-4f35-beb2-444431e81810') and (amenity_bookings.deleted_at is null) and (amenity_bookings.`status` in ('PENDING','APPROVED','CONFIRMED','CHECKED_IN')))  (cost=51.9 rows=146) (actual time=0.0234..0.479 rows=149 loops=1)
        -> Index lookup on amenity_bookings using idx_bookings_date (booking_date=DATE'2027-01-01')  (cost=51.9 rows=149) (actual time=0.0208..0.383 rows=149 loops=1)
    -> Filter: ((amenity_booking_payments.`status` in ('EXPIRED','CANCELLED','REVIEW')) or ((amenity_booking_payments.`status` = 'PENDING') and (amenity_booking_payments.expires_at <= '2026-10-16 07:31:20')) or ((amenity_booking_payments.`status` = 'REPORTED') and (concat(amenity_bookings.booking_date,' ',amenity_bookings.start_time) <= '2026-10-16 07:31:20')))  (cost=0.251 rows=1) (actual time=0.00418..0.00418 rows=0 loops=149)
        -> Single-row index lookup on amenity_booking_payments using amenity_booking_payments_booking_id_unique (booking_id=amenity_bookings.id)  (cost=0.251 rows=1) (actual time=0.00377..0.0038 rows=1 loops=149)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Nested loop antijoin  (cost=104 rows=149) (actual time=0.0367..1.81 rows=149 loops=1)
    -> Filter: ((amenity_bookings.amenity_id = '3a05feb1-1754-4f35-beb2-444431e81810') and (amenity_bookings.deleted_at is null) and (amenity_bookings.`status` in ('PENDING','APPROVED','CONFIRMED','CHECKED_IN')))  (cost=52.1 rows=149) (actual time=0.0232..0.761 rows=149 loops=1)
        -> Index lookup on amenity_bookings using idx_bookings_date (booking_date=DATE'2027-01-01')  (cost=52.1 rows=149) (actual time=0.0205..0.632 rows=149 loops=1)
    -> Filter: ((amenity_booking_payments.`status` in ('EXPIRED','CANCELLED','REVIEW')) or ((amenity_booking_payments.`status` = 'PENDING') and (amenity_booking_payments.expires_at <= '2026-10-16 07:31:20')) or ((amenity_booking_payments.`status` = 'REPORTED') and (concat(amenity_bookings.booking_date,' ',amenity_bookings.start_time) <= '2026-10-16 07:31:20')))  (cost=0.251 rows=1) (actual time=0.0068..0.0068 rows=0 loops=149)
        -> Single-row index lookup on amenity_booking_payments using amenity_booking_payments_booking_id_unique (booking_id=amenity_bookings.id)  (cost=0.251 rows=1) (actual time=0.00622..0.00626 rows=1 loops=149)

```

### amenity_list

```sql
select * from `amenities` where `deleted_at` is null and `is_active` = ? and `category_id` = ? and `block_id` = ? order by `created_at` desc limit 15
```

Bindings (synthetic QA identifiers only):
```json
[1,"698ab720-b5f4-47b8-8bb9-19ce531ecebd","8b33b282-04fc-4028-a523-9df0e2ac9282"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=64.6 rows=15) (actual time=0.0298..0.073 rows=15 loops=1)
    -> Filter: (amenities.deleted_at is null)  (cost=64.6 rows=503) (actual time=0.0293..0.0714 rows=15 loops=1)
        -> Index lookup on amenities using idx_amenities_perf (deleted_at=NULL, is_active=1, category_id='698ab720-b5f4-47b8-8bb9-19ce531ecebd', block_id='8b33b282-04fc-4028-a523-9df0e2ac9282') (reverse)  (cost=64.6 rows=503) (actual time=0.0287..0.0695 rows=15 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=64.6 rows=15) (actual time=0.0297..0.0807 rows=15 loops=1)
    -> Filter: (amenities.deleted_at is null)  (cost=64.6 rows=503) (actual time=0.0293..0.079 rows=15 loops=1)
        -> Index lookup on amenities using idx_amenities_perf (deleted_at=NULL, is_active=1, category_id='698ab720-b5f4-47b8-8bb9-19ce531ecebd', block_id='8b33b282-04fc-4028-a523-9df0e2ac9282') (reverse)  (cost=64.6 rows=503) (actual time=0.0286..0.0768 rows=15 loops=1)

```

### amenity_exact_code

```sql
select * from `amenities` where `amenity_code` = ?
```

Bindings (synthetic QA identifiers only):
```json
["TEST-ERX6CUE7"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Rows fetched before execution  (cost=0..0 rows=1) (actual time=104e-6..193e-6 rows=1 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Rows fetched before execution  (cost=0..0 rows=1) (actual time=105e-6..190e-6 rows=1 loops=1)

```

### smart_search_candidates

```sql
select `amenities`.*, `amenity_categories`.`category_name`, `amenity_categories`.`category_code`, `blocks`.`block_name`, `blocks`.`block_code` from `amenities` left join `amenity_categories` on `amenities`.`category_id` = `amenity_categories`.`id` left join `blocks` on `amenities`.`block_id` = `blocks`.`id` where `amenities`.`deleted_at` is null and `amenities`.`category_id` = ?
```

Bindings (synthetic QA identifiers only):
```json
["698ab720-b5f4-47b8-8bb9-19ce531ecebd"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Nested loop left join  (cost=214 rows=1006) (actual time=0.0577..4.11 rows=1001 loops=1)
    -> Left hash join (<hash>(amenity_categories.id)=<hash>(amenities.category_id)), extra conditions: (amenity_categories.id = amenities.category_id)  (cost=101 rows=1006) (actual time=0.0509..3.65 rows=1001 loops=1)
        -> Index lookup on amenities using idx_amenities_perf (deleted_at=NULL), with index condition: ((amenities.deleted_at is null) and (amenities.category_id = '698ab720-b5f4-47b8-8bb9-19ce531ecebd'))  (cost=19.3 rows=503) (actual time=0.0234..2.83 rows=1001 loops=1)
        -> Hash
            -> Table scan on amenity_categories  (cost=0.0134 rows=2) (actual time=0.0137..0.0166 rows=2 loops=1)
    -> Single-row index lookup on blocks using PRIMARY (id=amenities.block_id)  (cost=0.126 rows=1) (actual time=207e-6..239e-6 rows=1 loops=1001)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Nested loop left join  (cost=214 rows=1006) (actual time=0.059..4.15 rows=1001 loops=1)
    -> Left hash join (<hash>(amenity_categories.id)=<hash>(amenities.category_id)), extra conditions: (amenity_categories.id = amenities.category_id)  (cost=101 rows=1006) (actual time=0.0518..3.68 rows=1001 loops=1)
        -> Index lookup on amenities using idx_amenities_perf (deleted_at=NULL), with index condition: ((amenities.deleted_at is null) and (amenities.category_id = '698ab720-b5f4-47b8-8bb9-19ce531ecebd'))  (cost=19.3 rows=503) (actual time=0.0237..2.88 rows=1001 loops=1)
        -> Hash
            -> Table scan on amenity_categories  (cost=0.0134 rows=2) (actual time=0.0133..0.0162 rows=2 loops=1)
    -> Single-row index lookup on blocks using PRIMARY (id=amenities.block_id)  (cost=0.126 rows=1) (actual time=156e-6..188e-6 rows=1 loops=1001)

```

### slots

```sql
select * from `amenity_time_slots` where `amenity_id` = ? and `day_of_week` = ? order by `slot_start_time` asc
```

Bindings (synthetic QA identifiers only):
```json
["3a05feb1-1754-4f35-beb2-444431e81810",0]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Index lookup on amenity_time_slots using uq_amenity_day_slot (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810', day_of_week=0)  (cost=0.35 rows=1) (actual time=0.0143..0.0157 rows=1 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Index lookup on amenity_time_slots using uq_amenity_day_slot (amenity_id='3a05feb1-1754-4f35-beb2-444431e81810', day_of_week=0)  (cost=0.35 rows=1) (actual time=0.0139..0.0152 rows=1 loops=1)

```

### booking_worklist

```sql
select `b`.*, `p`.`status` as `payment_status` from `amenity_bookings` as `b` inner join `amenities` as `a` on `a`.`id` = `b`.`amenity_id` left join `amenity_booking_payments` as `p` on `p`.`booking_id` = `b`.`id` where `b`.`deleted_at` is null and `a`.`deleted_at` is null and `b`.`status` = ? order by `b`.`created_at` desc, `b`.`id` asc limit 15
```

Bindings (synthetic QA identifiers only):
```json
["PENDING"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=12482 rows=15) (actual time=91.8..91.9 rows=15 loops=1)
    -> Nested loop left join  (cost=12482 rows=24808) (actual time=91.8..91.9 rows=15 loops=1)
        -> Nested loop inner join  (cost=9939 rows=24808) (actual time=91.7..91.7 rows=15 loops=1)
            -> Sort: b.created_at DESC, b.id  (cost=4849 rows=49665) (actual time=91.7..91.7 rows=15 loops=1)
                -> Filter: ((b.deleted_at is null) and (b.`status` = 'PENDING'))  (cost=4849 rows=49665) (actual time=0.0349..57.1 rows=12500 loops=1)
                    -> Table scan on b  (cost=4849 rows=49665) (actual time=0.0223..50.5 rows=50000 loops=1)
            -> Filter: (a.deleted_at is null)  (cost=0.25 rows=0.5) (actual time=0.0032..0.00331 rows=1 loops=15)
                -> Single-row index lookup on a using PRIMARY (id=b.amenity_id)  (cost=0.25 rows=1) (actual time=0.00286..0.00289 rows=1 loops=15)
        -> Single-row index lookup on p using amenity_booking_payments_booking_id_unique (booking_id=b.id)  (cost=0.25 rows=1) (actual time=0.0105..0.0105 rows=1 loops=15)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=13978 rows=15) (actual time=0.0405..0.154 rows=15 loops=1)
    -> Nested loop left join  (cost=13978 rows=10385) (actual time=0.04..0.151 rows=15 loops=1)
        -> Nested loop inner join  (cost=10343 rows=10385) (actual time=0.0312..0.0767 rows=15 loops=1)
            -> Index lookup on b using idx_audit_booking_status_time (status='PENDING', deleted_at=NULL), with index condition: (b.deleted_at is null)  (cost=3067 rows=20790) (actual time=0.0219..0.0579 rows=15 loops=1)
            -> Filter: (a.deleted_at is null)  (cost=0.25 rows=0.5) (actual time=864e-6..967e-6 rows=1 loops=15)
                -> Single-row index lookup on a using PRIMARY (id=b.amenity_id)  (cost=0.25 rows=1) (actual time=665e-6..697e-6 rows=1 loops=15)
        -> Single-row index lookup on p using amenity_booking_payments_booking_id_unique (booking_id=b.id)  (cost=0.25 rows=1) (actual time=0.0047..0.00474 rows=1 loops=15)

```

### resident_bookings

```sql
select `amenity_bookings`.*, `amenities`.`amenity_name`, `amenities`.`location_detail`, `amenities`.`min_cancel_hours_before`, `apartments`.`apartment_number` from `amenity_bookings` inner join `amenities` on `amenities`.`id` = `amenity_bookings`.`amenity_id` inner join `apartments` on `apartments`.`id` = `amenity_bookings`.`apartment_id` where `amenity_bookings`.`resident_user_id` = ? and `amenity_bookings`.`deleted_at` is null order by `amenity_bookings`.`created_at` desc, `amenity_bookings`.`id` asc limit 10
```

Bindings (synthetic QA identifiers only):
```json
["01a1421e-7430-70a4-aa0f-a7259301993a"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 10 row(s)  (cost=7444 rows=10) (actual time=348..348 rows=10 loops=1)
    -> Nested loop inner join  (cost=7444 rows=24832) (actual time=348..348 rows=10 loops=1)
        -> Nested loop inner join  (cost=4340 rows=24832) (actual time=348..348 rows=10 loops=1)
            -> Sort: amenity_bookings.created_at DESC, amenity_bookings.id  (cost=1236 rows=24832) (actual time=348..348 rows=10 loops=1)
                -> Filter: (amenity_bookings.deleted_at is null)  (cost=1236 rows=24832) (actual time=0.0273..165 rows=50000 loops=1)
                    -> Index lookup on amenity_bookings using idx_bookings_resident (resident_user_id='01a1421e-7430-70a4-aa0f-a7259301993a')  (cost=1236 rows=24832) (actual time=0.0266..160 rows=50000 loops=1)
            -> Single-row index lookup on apartments using PRIMARY (id=amenity_bookings.apartment_id)  (cost=0.25 rows=1) (actual time=0.00423..0.00428 rows=1 loops=10)
        -> Single-row index lookup on amenities using PRIMARY (id=amenity_bookings.amenity_id)  (cost=0.25 rows=1) (actual time=0.00242..0.00248 rows=1 loops=10)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 10 row(s)  (cost=6393 rows=10) (actual time=0.0362..0.0702 rows=10 loops=1)
    -> Nested loop inner join  (cost=6393 rows=20790) (actual time=0.0355..0.0683 rows=10 loops=1)
        -> Nested loop inner join  (cost=3794 rows=20790) (actual time=0.0292..0.0586 rows=10 loops=1)
            -> Index lookup on amenity_bookings using idx_audit_booking_resident_time (resident_user_id='01a1421e-7430-70a4-aa0f-a7259301993a', deleted_at=NULL), with index condition: (amenity_bookings.deleted_at is null)  (cost=1196 rows=20790) (actual time=0.0226..0.0483 rows=10 loops=1)
            -> Single-row index lookup on apartments using PRIMARY (id=amenity_bookings.apartment_id)  (cost=0.25 rows=1) (actual time=701e-6..730e-6 rows=1 loops=10)
        -> Single-row index lookup on amenities using PRIMARY (id=amenity_bookings.amenity_id)  (cost=0.25 rows=1) (actual time=704e-6..738e-6 rows=1 loops=10)

```

### resident_tickets

```sql
select * from `tickets` where `deleted_at` is null and (`creator_user_id` = ? or `apartment_id` = ?) order by `created_at` desc, `id` asc limit 15
```

Bindings (synthetic QA identifiers only):
```json
["01a1421e-7430-70a4-aa0f-a7259301993a","53b06f81-792d-4fb2-8646-019c88a18f6d"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=5270 rows=15) (actual time=172..172 rows=15 loops=1)
    -> Sort: tickets.created_at DESC, tickets.id, limit input to 15 row(s) per chunk  (cost=5270 rows=53418) (actual time=172..172 rows=15 loops=1)
        -> Filter: ((tickets.deleted_at is null) and ((tickets.creator_user_id = '01a1421e-7430-70a4-aa0f-a7259301993a') or (tickets.apartment_id = '53b06f81-792d-4fb2-8646-019c88a18f6d')))  (cost=5270 rows=53418) (actual time=0.0194..83.7 rows=50000 loops=1)
            -> Table scan on tickets  (cost=5270 rows=53418) (actual time=0.0173..61 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=1591 rows=15) (actual time=0.025..0.0781 rows=15 loops=1)
    -> Filter: ((tickets.creator_user_id = '01a1421e-7430-70a4-aa0f-a7259301993a') or (tickets.apartment_id = '53b06f81-792d-4fb2-8646-019c88a18f6d'))  (cost=1591 rows=5069) (actual time=0.0244..0.0761 rows=15 loops=1)
        -> Index lookup on tickets using idx_audit_ticket_created (deleted_at=NULL), with index condition: (tickets.deleted_at is null)  (cost=1591 rows=26678) (actual time=0.0226..0.0686 rows=15 loops=1)

```

### manager_tickets

```sql
select * from `tickets` where `deleted_at` is null and `status` = ? and exists (select 1 from `apartments` where `apartments`.`id` = `tickets`.`apartment_id` and `apartments`.`deleted_at` is null and `block_id` = ?) order by `created_at` desc, `id` asc limit 15
```

Bindings (synthetic QA identifiers only):
```json
["NEW","8b33b282-04fc-4028-a523-9df0e2ac9282"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (actual time=71.6..71.6 rows=15 loops=1)
    -> Sort: tickets.created_at DESC, tickets.id, limit input to 15 row(s) per chunk  (actual time=71.6..71.6 rows=15 loops=1)
        -> Stream results  (cost=1096 rows=734) (actual time=0.041..55.3 rows=12500 loops=1)
            -> Nested loop inner join  (cost=1096 rows=734) (actual time=0.0339..39.9 rows=12500 loops=1)
                -> Filter: (apartments.deleted_at is null)  (cost=0.283 rows=0.333) (actual time=0.0156..0.0189 rows=1 loops=1)
                    -> Index lookup on apartments using uq_apartment_number (block_id='8b33b282-04fc-4028-a523-9df0e2ac9282')  (cost=0.283 rows=1) (actual time=0.0149..0.0181 rows=1 loops=1)
                -> Filter: ((tickets.apartment_id = apartments.id) and (tickets.deleted_at is null))  (cost=1744 rows=2202) (actual time=0.0168..38.5 rows=12500 loops=1)
                    -> Index lookup on tickets using idx_tickets_status_sla (status='NEW')  (cost=1744 rows=22020) (actual time=0.0151..32.5 rows=12500 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (actual time=75.3..75.3 rows=15 loops=1)
    -> Sort: tickets.created_at DESC, tickets.id, limit input to 15 row(s) per chunk  (actual time=75.3..75.3 rows=15 loops=1)
        -> Stream results  (cost=1096 rows=3670) (actual time=0.0458..58.3 rows=12500 loops=1)
            -> Nested loop inner join  (cost=1096 rows=3670) (actual time=0.038..41.7 rows=12500 loops=1)
                -> Filter: (apartments.deleted_at is null)  (cost=0.283 rows=0.333) (actual time=0.0183..0.0227 rows=1 loops=1)
                    -> Index lookup on apartments using uq_apartment_number (block_id='8b33b282-04fc-4028-a523-9df0e2ac9282')  (cost=0.283 rows=1) (actual time=0.0175..0.0216 rows=1 loops=1)
                -> Filter: ((tickets.apartment_id = apartments.id) and (tickets.deleted_at is null))  (cost=4387 rows=11010) (actual time=0.0181..40.5 rows=12500 loops=1)
                    -> Index lookup on tickets using idx_tickets_status_sla (status='NEW')  (cost=4387 rows=22020) (actual time=0.0163..34.1 rows=12500 loops=1)

```

### technician_tickets

```sql
select * from `tickets` where `deleted_at` is null and `current_technician_user_id` = ? order by `created_at` desc, `id` asc limit 15
```

Bindings (synthetic QA identifiers only):
```json
["01a1421e-7438-7317-9723-78528308949b"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=1292 rows=15) (actual time=46..46 rows=15 loops=1)
    -> Sort: tickets.created_at DESC, tickets.id, limit input to 15 row(s) per chunk  (cost=1292 rows=20842) (actual time=46..46 rows=15 loops=1)
        -> Filter: (tickets.deleted_at is null)  (cost=1292 rows=20842) (actual time=0.0243..30 rows=10000 loops=1)
            -> Index lookup on tickets using idx_tickets_tech (current_technician_user_id='01a1421e-7438-7317-9723-78528308949b')  (cost=1292 rows=20842) (actual time=0.0236..29 rows=10000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=2986 rows=15) (actual time=0.0241..0.0741 rows=15 loops=1)
    -> Index lookup on tickets using idx_audit_ticket_tech_time (current_technician_user_id='01a1421e-7438-7317-9723-78528308949b', deleted_at=NULL), with index condition: (tickets.deleted_at is null)  (cost=2986 rows=19020) (actual time=0.0234..0.0718 rows=15 loops=1)

```

### history

```sql
select * from `ticket_history` where `ticket_id` = ? order by `created_at` asc
```

Bindings (synthetic QA identifiers only):
```json
["c2071b9a-930d-4935-b89f-ff03443fc519"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Sort: ticket_history.created_at  (cost=586 rows=43303) (actual time=35.5..35.6 rows=500 loops=1)
    -> Filter: (ticket_history.ticket_id = 'c2071b9a-930d-4935-b89f-ff03443fc519')  (cost=586 rows=43303) (actual time=0.0319..35.3 rows=500 loops=1)
        -> Table scan on ticket_history  (cost=586 rows=43303) (actual time=0.0184..30.4 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Index lookup on ticket_history using idx_audit_history_time (ticket_id='c2071b9a-930d-4935-b89f-ff03443fc519')  (cost=175 rows=500) (actual time=0.0164..1.6 rows=500 loops=1)

```

### attachments

```sql
select * from `ticket_attachments` where `ticket_id` in (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
```

Bindings (synthetic QA identifiers only):
```json
["c2071b9a-930d-4935-b89f-ff03443fc519","a3be1ebc-48b3-46df-a23d-23c184ad3be3","8ed1b071-d890-4dd4-9534-e588d5ece1d0","3ff44e5f-afd3-4dd6-bb5a-8750db234f74","be091d7f-222e-4019-b77a-74cc62a43b26","90cff989-525c-4134-99be-99071ba2aadc","92a7d17d-5293-42c3-b833-e460bde3baea","bcf4eba0-bb3b-4687-bf95-ad96745a5af8","240bb7b8-ecd8-41b7-a18d-b3515bb5861f","1b368982-6998-4e5c-91f0-128d401f0f0c","499ab047-a2f3-47fc-96da-dbdaf9b0bdcc","94d7ff25-b2da-48b3-b467-bccce81fa1bb","9522a7c8-59eb-4b82-beee-330db2a99b06","93a621d5-d79f-4017-bed9-466590422de9","9365b245-b82c-45d0-b194-429108902798"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Filter: (ticket_attachments.ticket_id in ('c2071b9a-930d-4935-b89f-ff03443fc519','a3be1ebc-48b3-46df-a23d-23c184ad3be3','8ed1b071-d890-4dd4-9534-e588d5ece1d0','3ff44e5f-afd3-4dd6-bb5a-8750db234f74','be091d7f-222e-4019-b77a-74cc62a43b26','90cff989-525c-4134-99be-99071ba2aadc','92a7d17d-5293-42c3-b833-e460bde3baea','bcf4eba0-bb3b-4687-bf95-ad96745a5af8','240bb7b8-ecd8-41b7-a18d-b3515bb5861f','1b368982-6998-4e5c-91f0-128d401f0f0c','499ab047-a2f3-47fc-96da-dbdaf9b0bdcc','94d7ff25-b2da-48b3-b467-bccce81fa1bb','9522a7c8-59eb-4b82-beee-330db2a99b06','93a621d5-d79f-4017-bed9-466590422de9','9365b245-b82c-45d0-b194-429108902798'))  (cost=2544 rows=23262) (actual time=0.0946..37.7 rows=75 loops=1)
    -> Table scan on ticket_attachments  (cost=2544 rows=46525) (actual time=0.0349..28.6 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Index range scan on ticket_attachments using idx_audit_attachment_ticket over (ticket_id = '1b368982-6998-4e5c-91f0-128d401f0f0c') OR (ticket_id = '240bb7b8-ecd8-41b7-a18d-b3515bb5861f') OR (13 more), with index condition: (ticket_attachments.ticket_id in ('c2071b9a-930d-4935-b89f-ff03443fc519','a3be1ebc-48b3-46df-a23d-23c184ad3be3','8ed1b071-d890-4dd4-9534-e588d5ece1d0','3ff44e5f-afd3-4dd6-bb5a-8750db234f74','be091d7f-222e-4019-b77a-74cc62a43b26','90cff989-525c-4134-99be-99071ba2aadc','92a7d17d-5293-42c3-b833-e460bde3baea','bcf4eba0-bb3b-4687-bf95-ad96745a5af8','240bb7b8-ecd8-41b7-a18d-b3515bb5861f','1b368982-6998-4e5c-91f0-128d401f0f0c','499ab047-a2f3-47fc-96da-dbdaf9b0bdcc','94d7ff25-b2da-48b3-b467-bccce81fa1bb','9522a7c8-59eb-4b82-beee-330db2a99b06','93a621d5-d79f-4017-bed9-466590422de9','9365b245-b82c-45d0-b194-429108902798'))  (cost=37.5 rows=75) (actual time=0.0609..0.474 rows=75 loops=1)

```

### notifications

```sql
select * from `user_in_app_notifications` where `recipient_user_id` = ? and `category` = ? order by `created_at` desc, `id` desc limit 15
```

Bindings (synthetic QA identifiers only):
```json
["01a1421e-7430-70a4-aa0f-a7259301993a","TICKET"]
```

BEFORE — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=722 rows=15) (actual time=118..118 rows=15 loops=1)
    -> Sort: user_in_app_notifications.created_at DESC, user_in_app_notifications.id DESC, limit input to 15 row(s) per chunk  (cost=722 rows=21598) (actual time=118..118 rows=15 loops=1)
        -> Filter: (user_in_app_notifications.category = 'TICKET')  (cost=722 rows=21598) (actual time=0.0217..109 rows=5000 loops=1)
            -> Index lookup on user_in_app_notifications using idx_in_app_notifs (recipient_user_id='01a1421e-7430-70a4-aa0f-a7259301993a')  (cost=722 rows=21598) (actual time=0.0205..104 rows=50000 loops=1)

```

AFTER — EXPLAIN ANALYZE:
```text
-> Limit: 15 row(s)  (cost=1505 rows=15) (actual time=0.02..0.0565 rows=15 loops=1)
    -> Index lookup on user_in_app_notifications using idx_audit_notification_category (recipient_user_id='01a1421e-7430-70a4-aa0f-a7259301993a', category='TICKET')  (cost=1505 rows=9984) (actual time=0.0194..0.0546 rows=15 loops=1)

```

## H. Foreign keys actually present

`information_schema.KEY_COLUMN_USAGE` on the final migrated QA database shows only these foreign keys among the 14 scoped tables:

| Table | Constraint | Columns / referenced table | Supporting index |
|---|---|---|---|
| amenity_booking_payments | amenity_booking_payments_booking_id_foreign | booking_id → amenity_bookings.id | booking_id UNIQUE, preserved |
| amenity_booking_payments | amenity_booking_payments_confirmed_by_user_id_foreign | confirmed_by_user_id → users.id | FK-generated confirmed_by index, preserved |

In particular, no attachment/history/blackout FK-generated lookup index was present in this fresh MySQL schema. The migration regression test adds an actual blackout FK, exercises up/down and removes that test constraint. Post-test metadata again contains the two expected payment FKs and all eight owned optimization indexes.

## I. Commands and confirmed checks

Successful database-mutating QA runs used explicit `APP_ENV=testing`, `DB_HOST=db`, an isolated *_test database and isolated APP_CONFIG_CACHE / APP_ROUTES_CACHE paths. Regression/browser/HTTP runs used `DB_DATABASE=amenity_ticket_regression_index_audit_test`; benchmarks used the separate databases listed in A. PHPUnit used ignored QA configs copied from phpunit.xml with the forced database value replaced. No global app config cache was cleared.

| Check | Result |
|---|---|
| git fetch origin; clean working tree; approved branch from origin/master | PASS; base 9978f9c |
| artisan route:list --path=ticket / --path=amenit --except-vendor | Verified 34 Ticket routes (including resident compatibility aliases, management and notifications) and 46 amenity/payment routes |
| composer show --direct; php -v; SELECT VERSION() | Verified installed versions in A |
| New database migrate, artisan rollback --step=1, artisan migrate | PASS, including separate processes; no migrate:fresh on existing data |
| AmenityTicketDatabaseIndexTest | 4 tests, 45 assertions, PASS (reversed unique prefix, backward history scan, idempotence, same-name ownership, FK dependency, exact days/row locks) |
| SearchEngineTest + ResidentAmenityBookingTest + AmenityBookingApprovalTest + AmenityBookingPaymentTest + AmenityClosureTest + ResidentTicketTest + ResidentTicketSeederTest + ResidentAmenityAuditTest | 139 tests, 1,264 assertions, PASS |
| Full PHPUnit, final rerun after index-equivalence refinement | 639 tests, 3,653 assertions; 0 failures, 0 errors, 44 skipped, 3 notices; 595 non-skipped cases; 08:18.154 |
| Tests/Unit notice inspection | 34 tests, 136 assertions; same 3 notices, no failures |
| Dedicated multi-worker ResidentAmenityBookingConcurrencyTest + TicketWorkflowConcurrencyTest after final schema | 20 tests, 129 assertions, PASS, no skips; synthetic sandbox IPN credentials only |
| Final 50,000-row opt-in AmenityTicketIndexBenchmarkTest | 1 test, 15 assertions, PASS; 16 SELECT workloads, seven timings each phase, actual EXPLAIN ANALYZE |
| Pint --dirty --format agent | PASS after formatting |
| php -l on all 7 changed/new PHP files | PASS |
| npm run typecheck | PASS |
| npm run build | PASS, Vite 8.3.0; generated public/index.html is restored before finishing |
| Playwright resident-amenity-booking + resident-incident-ticket + ticket-management | 54/54 PASS; mocked API responses, desktop/mobile/keyboard/network/conflict coverage |
| Playwright ticket-workflow-live | 1/1 PASS, actual QA API/MySQL/private local storage; no mocked domain API responses |
| Playwright resident-amenity-live | 1/1 PASS, actual QA API/MySQL: resident books → manager approves → simulated QA receipt confirmed → resident cancels → capacity released and refund-review history retained; QR image request blocked, no real transfer |
| Existing browser fixture helpers | All four prepare/verify-cleanup cases PASS: Ticket 4 + 8 assertions, amenity 4 + 13 assertions. Both QA session fixture files removed; generated records/private photos cleaned by existing helpers. |
| docker build --target production -f docker/app/Dockerfile -t smart-cassavas:index-audit-smoke . | PASS; local image only |
| Local production-image smoke with QA database and RUN_MIGRATIONS=false | /up renders Application up; /api/health reports system/database/cache healthy; schema file packaged; temporary smoke container stopped/removed |
| GitHub Actions / PR / Vercel verification for this change | N/A: no push/PR/deploy authorized |

Full-suite skips: benchmark (1), amenity races (16), browser fixture helpers (4), Ticket races (4), other members' resident concurrency (9), temporary-registration concurrency (10). The scoped benchmark/races and browser helpers are exercised separately with their explicit opt-in environments; unrelated concurrency suites remain skipped, not claimed verified.

Notices are in existing `tests/Unit/GitHubActionsServiceTest.php` lines 243, 253 and 300: mock objects have no configured expectations. These CI/CD tests are outside this change; no suppression or test deletion was added. Existing resident creation tests also log accepted HTTP 500 conflict responses (`tests/Feature/Residents/ResidentCreateTest.php`); that behavior is outside this audit and was not investigated or changed.

The initial benchmark attempt was blocked by its database-name guard before benchmark seeding because the process DB_DATABASE override was missing. It was rerun with the explicit isolated database. Initial Playwright preview was stopped after discovering the incorrect output root; all 54 tests then passed with `vite preview --outDir public`.

### Reproducing the benchmark in PowerShell

Use a **new**, disposable local database on every run. The benchmark intentionally rejects a database containing tickets. This example creates its own database, runs current migrations, then rolls back only this optimization migration to establish the baseline. Do not substitute a team/production database.

```powershell
$qaDatabase = 'amenity_ticket_index_audit_rerun_test'
docker exec smart_cassavas_db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root -e "CREATE DATABASE amenity_ticket_index_audit_rerun_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"'
$qaXml = (Get-Content phpunit.xml -Raw).Replace('value="quanly_toanha_test"', 'value="' + $qaDatabase + '"').Replace('bootstrap="vendor/autoload.php"', 'bootstrap="../../vendor/autoload.php"').Replace('<directory>tests/', '<directory>../../tests/').Replace('<directory>app</directory>', '<directory>../../app</directory>')
[IO.File]::WriteAllText((Join-Path (Get-Location) 'storage/logs/index-benchmark-phpunit.xml'), $qaXml)
docker exec -e APP_ENV=testing -e APP_CONFIG_CACHE=/tmp/index-benchmark-config.php -e APP_ROUTES_CACHE=/tmp/index-benchmark-routes.php -e DB_HOST=db -e DB_DATABASE=$qaDatabase smart_cassavas_app php artisan migrate --no-interaction
docker exec -e APP_ENV=testing -e APP_CONFIG_CACHE=/tmp/index-benchmark-config.php -e DB_HOST=db -e DB_DATABASE=$qaDatabase smart_cassavas_app php artisan migrate:rollback --step=1 --no-interaction
docker exec -e APP_ENV=testing -e APP_CONFIG_CACHE=/tmp/index-benchmark-config.php -e APP_ROUTES_CACHE=/tmp/index-benchmark-routes.php -e DB_HOST=db -e DB_DATABASE=$qaDatabase -e RUN_INDEX_BENCHMARK=1 -e INDEX_BENCHMARK_ROWS=50000 smart_cassavas_app php vendor/bin/phpunit -c storage/logs/index-benchmark-phpunit.xml tests/Feature/AmenityTicketIndexBenchmarkTest.php
```

The evidence JSON is written to ignored `storage/logs/amenity-ticket-index-benchmark.json`; SQL/plans from the final measured run are embedded in this report. INDEX_BENCHMARK_ROWS accepts 1,000–50,000. A fresh name/config/database is required for each different dataset. Do not use a previously optimized database as a before baseline.

For ordinary regression, use a separate freshly migrated/seeded *_test database and an equivalent ignored PHPUnit config, then run:

```text
php vendor/bin/phpunit -c storage/logs/index-regression-phpunit.xml tests/Feature/AmenityTicketDatabaseIndexTest.php
php vendor/bin/phpunit -c storage/logs/index-regression-phpunit.xml --log-junit storage/logs/index-regression-junit.xml
php vendor/bin/pint --dirty --format agent
npm run typecheck
npm run build
```

Multi-worker tests additionally require TEST_HTTP_BASE_URL pointing to a server with the same QA database. The IPN race requires matching synthetic TEST_SEPAY_IPN_SECRET / server AMENITY_SEPAY_IPN_SECRET and sandbox checkout config. Browser tests use existing opt-in fixture prepare/verify-cleanup helpers; fixture JSON contains QA sessions and must stay ignored and never be printed or committed.

## J. Changed files and review readiness

1. `app/Repositories/Eloquent/DatabaseResidentAmenityBookingRepository.php`: direct DATE predicates for active stays, blackout and held-booking day.
2. `app/Services/ResidentAmenityBookingService.php`: direct DATE in closure scope.
3. `app/Http/Controllers/AmenityController.php`: direct DATE for slot limit and approval blackout reads.
4. `app/Http/Controllers/TicketManagementController.php`: direct DATE for valid reporter selection.
5. `database/migrations/2026_10_16_072349_optimize_amenity_ticket_lookup_indexes.php`: eight proven index definitions, structural detection and persistent rollback ownership.
6. `tests/Feature/AmenityTicketDatabaseIndexTest.php`: four MySQL/date/rollback regressions.
7. `tests/Feature/AmenityTicketIndexBenchmarkTest.php`: opt-in disposable MySQL workload/plan benchmark.
8. `docs/audits/database-index-audit.md`: this requested audit and evidence.

The measured changes are available for local review. Status: **PARTIAL performance coverage / QA-verified changes; NOT production-verified**. F1 fuzzy ranking and F7 scoped manager-list performance remain as explicitly measured limitations; historical duplicate cleanup and sustained-write testing are deferred. This is not a claim that every query across all eight features is fully optimized. No commit, push, merge, PR or deploy was performed. Before a production rollout, review actual schema/index drift, write load and migration locking; apply migrations through the normal approved deployment flow.
