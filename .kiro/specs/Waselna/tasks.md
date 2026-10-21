# Implementation Plan: Food Delivery Platform

## Overview

The plan builds the Delivery_API (Laravel 12 / PHP 8.4) bottom-up along the `Backend_Layer_Chain`: shared kernel and request pipeline first, then each of the 14 modules in dependency order (Auth → Account → Zone → Catalog → Cart → Pricing → Order → Payment → Dispatch → Rider → Tracking → Notification → Review → Admin), then the Realtime broadcaster, then the Web_Client (React + TypeScript + Vite), then the enforcement and pipeline jobs. Every module task delivers its migrations, Eloquent_Models, repositories, Service_Interfaces, Service_Implementations, Controllers, Form_Requests, and Module_Boundary interfaces together so no orphaned code exists at any point.

Property tests use Pest + `giorgiosironi/eris` on the backend and Vitest + `fast-check` on the Web_Client, one test per property in the design's Correctness Properties section, ≥100 generated inputs each, tagged as the Testing Strategy states. Time-dependent behaviour is exercised through the injected `Clock`, never wall-clock time.

## Tasks

- [x] 1. Project foundation, shared kernel, and request pipeline
  - [x] 1.1 Scaffold the Delivery_API application and the 14 module skeletons
    - Create the Laravel 12 application, the `app/Modules/{Auth,Account,Catalog,Zone,Cart,Pricing,Order,Payment,Dispatch,Rider,Tracking,Notification,Review,Admin}` trees with `Http/{Controllers,Requests,Resources}`, `Contracts/Services`, `Services`, `Repositories`, `Models`, `Domain`, `Events`, `Listeners`, `Jobs`, `Boundary`, `Providers`, and the `app/Shared/{Domain,Http,Support}` tree
    - Register one service provider per module for container bindings; add empty Deptrac, PHPStan, and Pest configuration files
    - _Requirements: 22.1, 22.2, 22.5, 22.6, 22.7, 38.1, 38.6, 38.7_

  - [x] 1.2 Implement the shared kernel value objects and injected services
    - Implement framework-free `Money` (integer minor units, half-up `percentHalfUp`, `clamp`), `Coordinate` (decimal strings at 7 fractional digits, `haversineKilometres` half-up to 3 digits), `Page`, `ApiError`, `TimeRange`
    - Implement the `Clock` and `IdGenerator` interfaces with system and ULID implementations plus test-controllable fakes
    - _Requirements: 7.4, 20.9, 40.4, 40.7_

  - [x] 1.3 Write unit tests for the shared kernel value objects
    - Cover monetary values at 0, 1, and 99,999,999 minor units, currency-mismatch assertions, rounding at the .5 boundary, coordinate range bounds, and distance rounding
    - _Requirements: 7.4, 40.4_

  - [x] 1.4 Implement the cross-cutting request pipeline and error rendering
    - Implement the seven middleware in the documented order (`AssignCorrelationIdentifier`, `SecurityHeaders`, `Cors`, `AuthenticateBearer` placeholder, `AuthorizeRole` placeholder, `ThrottleRequests` with Redis counters, `JsonApiResponse`)
    - Implement `DomainException` with `ValidationException`, `ConflictException`, `AuthorizationException`, the single exception handler, the error code catalogue, the data/total/next-cursor envelope, and structured single-line JSON logging with secret redaction
    - _Requirements: 20.2, 20.3, 20.4, 20.10, 27.3, 27.4, 27.5, 27.7, 27.9, 28.3, 28.4, 28.9_

  - [x] 1.5 Write property test for Correlation_Identifier propagation
    - **Property 61: The Correlation_Identifier is echoed or generated and propagated everywhere**
    - **Validates: Requirements 27.4, 27.5**

  - [x] 1.6 Write property test for structured, secret-free logging
    - **Property 62: Logs are structured and free of secrets**
    - **Validates: Requirements 27.3, 27.7, 27.9**

  - [x] 1.7 Write property test for per-scope rate limiting
    - **Property 63: Rate limits are enforced per scope with a Retry-After header**
    - **Validates: Requirements 28.3**

  - [x] 1.8 Write property test for cross-origin restriction
    - **Property 64: Cross-origin requests are restricted to configured origins**
    - **Validates: Requirements 28.4**

  - [x] 1.9 Implement the queue, scheduler, and health foundation
    - Implement the base queued Job carrying the Correlation_Identifier, the 3-retry policy with 1/2/4-second waits, dead-letter routing with an error-level Observability_Stack entry, the queue names (`default`, `notifications`, `payments`, `dispatch`, `reports`), the scheduler entry point, and the health endpoint reporting PostgreSQL and Redis reachability
    - _Requirements: 21.1, 21.5, 21.6, 21.7, 21.8, 27.1, 27.2, 27.6_

  - [x] 1.10 Write property test for queued Job idempotency and retry bounds
    - **Property 67: Queued Jobs are idempotent and bounded in retries**
    - **Validates: Requirements 21.6, 21.7, 21.8**

  - [x] 1.11 Author the Local_Dev_Environment and boot-time configuration validation
    - Write the Docker Compose file with exactly the six services (frontend, backend, queue-worker, reverb, postgres with PostGIS, redis), bind-mounted source, and named `pgdata`/`redisdata` volumes
    - Implement the boot check that terminates startup naming any absent required environment variable and its grouping heading; add the artisan commands for migrate, seed, and test
    - Wire the documented seed command to the demonstration data seeder implemented in task 12.7, and list the seeder's `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` variables in the committed example environment file with non-secret placeholder values
    - _Requirements: 24.1, 24.2, 24.3, 24.4, 24.5, 24.6, 24.8, 24.9, 44.7, 44.8_

  - [x] 1.12 Write example tests for the health endpoint and boot validation
    - Cover the healthy and degraded health responses and a failed boot with a missing environment variable
    - _Requirements: 27.1, 27.2, 24.9, 44.8_

- [x] 2. Checkpoint - foundation
  - Ensure all tests pass, ask the user if questions arise.

- [x] 3. Auth module: credentials, tokens, and authorization
  - [x] 3.1 Create the Auth schema, models, and repositories
    - Migrations for `users` (with `email_lower` unique, role and account_status checks, scope checks), `access_tokens`, `refresh_tokens` with hashed token columns, expiry and revocation columns, and the documented indexes
    - _Requirements: 1.4, 2.1, 40.1, 40.2, 40.3, 40.5, 40.6, 40.7_

  - [x] 3.2 Implement AuthenticationService with opaque database-backed tokens
    - Register, sign in, refresh with rotation, sign out, and the Redis `signin_fail:{emailLower}` counter with a 15-minute TTL; 60-minute Access_Token and 30-day Refresh_Token expiry read from the injected `Clock`; SHA-256 token hashes only
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11_

  - [x] 3.3 Write property test for registration
    - **Property 1: Registration creates exactly one customer and never echoes credentials**
    - **Validates: Requirements 1.1, 1.4, 2.1**

  - [x] 3.4 Write property test for token issuance, rotation, and revocation
    - **Property 4: Token issuance, rotation, and revocation**
    - **Validates: Requirements 1.5, 1.8, 1.9, 1.10, 1.11, 18.3, 31.5, 33.6**

  - [x] 3.5 Write property test for sign-in failure indistinguishability and lockout
    - **Property 5: Sign-in failures are indistinguishable and lockout is time-bounded**
    - **Validates: Requirements 1.6, 1.7**

  - [x] 3.6 Implement AuthorizationService, ActorContext, and the Role middleware
    - Build `ActorContext` from the database record on every request, implement `can()` over the Role and ownership matrix, the policy objects reused by Realtime channel authorization, and the `403 forbidden` / `401 unauthenticated` decisions
    - _Requirements: 2.1, 2.2, 2.3, 2.10, 33.3, 33.4, 33.5_

  - [x] 3.7 Implement the UserDirectory boundary with status and Role changes
    - `createUser`, `findById`, `findByIds`, `setAccountStatus` revoking every token in the same transaction, `setRole`, `listUsers`
    - _Requirements: 18.3, 33.6, 37.2, 2.10_

  - [x] 3.8 Write feature tests for every documented auth endpoint error code
    - One success test and one test per API_Error code across register, login, refresh, logout
    - _Requirements: 1.2, 1.3, 1.6, 1.9, 1.11, 20.4, 23.3_

- [x] 4. Account module: profiles, addresses, and preferences
  - [x] 4.1 Create the Account schema and models with encrypted casts
    - Migrations for `user_profiles`, `delivery_addresses` (partial unique default index, coordinate checks), `notification_preferences`, `email_change_tokens`; encrypted casts for address lines and contact details with deterministic lookup hashes
    - _Requirements: 28.6, 31.9, 40.1, 40.2, 40.3, 40.5, 40.6_

  - [x] 4.2 Implement ProfileService including the email-change and password-change flows
    - Own-profile read and partial update, email-change token issuance invalidating previous tokens, 24-hour confirmation, password change against the stored hash
    - _Requirements: 31.1, 31.2, 31.3, 31.4, 31.5, 31.6, 31.12, 31.13, 31.14_

  - [x] 4.3 Write property test for partial profile updates
    - **Property 71: Partial profile updates change exactly the submitted fields**
    - **Validates: Requirements 31.1, 31.2, 58.2, 58.5**

  - [x] 4.4 Write property test for the email-change token flow
    - **Property 72: The email-change token flow preserves sign-in continuity**
    - **Validates: Requirements 31.4, 31.12, 31.13**

  - [x] 4.5 Write property test for password change
    - **Property 73: Password change succeeds only against the stored hash**
    - **Validates: Requirements 31.5, 31.6**

  - [x] 4.6 Implement AddressService, AddressDirectory, and NotificationPreferenceReader
    - Create, update, mark default in one transaction, archive on delete, ordered listing, 20-address bound, and the boundary readers consumed by Order, Pricing, and Notification
    - _Requirements: 31.7, 31.8, 31.9, 31.10, 31.11_

  - [x] 4.7 Write property test for Delivery_Address defaults and archival
    - **Property 70: Delivery_Address defaults and archival keep their invariants**
    - **Validates: Requirements 31.7, 31.9, 31.10, 31.11**

  - [x] 4.8 Wire the registration transaction across Auth and Account
    - One transaction inserting `users`, `user_profiles`, and the 10 `notification_preferences` rows from the Platform_Configuration defaults
    - _Requirements: 1.1, 16.12, 40.8, 40.9_

- [x] 5. Checkpoint - identity and accounts
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Zone module: geometry, containment, and address resolution
  - [x] 6.1 Implement the framework-free GeoJSON_Parser and GeoJSON_Printer
    - Grammar acceptance for 1–10 rings of 4–1,000 closed coordinate pairs within the coordinate ranges and the 1,000,000-character bound, first-violation offset reporting, printing at most 7 fractional digits with each ring closed
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x] 6.2 Write property test for GeoJSON parsing
    - **Property 15: GeoJSON parsing accepts exactly the conforming grammar and reports the first violation**
    - **Validates: Requirements 5.1, 5.2**

  - [x] 6.3 Write property test for geometry round-tripping
    - **Property 16: Geometry round-trips through the printer exactly**
    - **Validates: Requirements 5.3, 5.4**

  - [x] 6.4 Write property test for printer idempotency
    - **Property 17: Printing is idempotent after normalization**
    - **Validates: Requirements 5.5**

  - [x] 6.5 Implement Delivery_Zone persistence, containment, and serviceability
    - Migrations for `delivery_zones` (jsonb + `geometry(Polygon,4326)`, GIST index, validity check) and `delivery_zone_branch`; the PostGIS containment query and the in-memory ray-cast evaluator behind one interface; Serviceable_Area as the union of zones on active Restaurant_Branches
    - _Requirements: 5.8, 5.9, 32.11, 40.6_

  - [x] 6.6 Write property test for boundary-inclusive containment
    - **Property 18: Containment is boundary-inclusive and implementation-agnostic**
    - **Validates: Requirements 5.8, 5.9, 32.11**

  - [x] 6.7 Implement the AddressResolver abstraction and serviceability rejections
    - `AddressResolver` interface with the local deterministic adapter, the `geocode_cache` table keyed by normalized address, the 3,000-millisecond budget, `address_not_resolvable` and `address_not_serviceable` rejections that store nothing
    - _Requirements: 5.6, 5.7, 5.10, 32.14, 43.7, 23.8_

  - [x] 6.8 Write property test for unresolvable and unserviceable addresses
    - **Property 19: Unserviceable and unresolvable addresses are rejected without side effects**
    - **Validates: Requirements 5.6, 5.7, 5.10, 32.14**

  - [x] 6.9 Write unit tests for inside, outside, and on-boundary probe points
    - Cover exterior-ring and interior-ring boundary points and a Restaurant with no assigned zones
    - _Requirements: 5.8, 5.9, 23.2_

- [x] 7. Catalog module: Restaurants, Restaurant_Branches, and hours
  - [x] 7.1 Create the Restaurant and Restaurant_Branch schema, models, and repositories
    - Migrations for `restaurants` (status, currency, tax and fee columns, trigram and rating indexes), `restaurant_cuisine_tags`, `restaurant_branches`, `opening_hours`, `branch_hours` with the overlap-preventing exclusion constraints
    - _Requirements: 32.1, 32.2, 40.1, 40.2, 40.3, 40.4, 40.5, 40.6, 59.1_

  - [x] 7.2 Implement the framework-free OpeningHoursEvaluator
    - Half-open `[start, end)` membership in the applicable IANA time zone, Branch_Hours overriding Opening_Hours where at least one interval exists, daylight-saving transitions handled through the injected `Clock`
    - _Requirements: 3.5, 11.7, 32.5, 32.7_

  - [x] 7.3 Write property test for open-or-closed evaluation
    - **Property 30: Open-or-closed status is half-open interval membership in the applicable time zone**
    - **Validates: Requirements 11.7, 32.5, 32.7, 3.5**

  - [x] 7.4 Implement the hours management endpoints with atomic replacement
    - Submitted weekly schedule replaces the stored schedule in one transaction; interval rule violations reject with `invalid_hours` and change nothing
    - _Requirements: 11.7, 11.8, 32.5, 32.6, 40.8_

  - [x] 7.5 Write property test for schedule replacement
    - **Property 31: Submitted schedules replace the stored schedule atomically**
    - **Validates: Requirements 11.7, 11.8, 32.5, 32.6**

  - [x] 7.6 Implement Restaurant profile and Restaurant_Branch management
    - Restaurant profile writes, branch create/update with address resolution, zone assignment, activation flag, and the 50-branch and 20-zone bounds enforced with `SELECT ... FOR UPDATE` on the parent row
    - _Requirements: 32.1, 32.2, 32.3, 32.12, 32.13, 32.14_

- [x] 8. Catalog module: Menu structure, availability, and cached read models
  - [x] 8.1 Implement Menu_Category, Menu_Item, Product_Variant, and Modifier management
    - Create, update, archive-on-delete, price and modifier-group validation, display ordering, and the 50/200/20/10/50 collection bounds with `catalog_limit_reached`
    - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.6, 11.8, 11.9, 11.10, 34.1, 34.2, 34.3, 34.4_

  - [x] 8.2 Implement availability toggling with event publication and cache invalidation
    - Availability writes publish exactly one `menu_item.availability_changed` event carrying item, optional variant, new flag, and UTC timestamp, and invalidate the affected `menu:v1:{restaurantId}` and search keys
    - _Requirements: 11.5, 34.5, 34.8, 34.9, 6.4_

  - [x] 8.3 Implement the Menu read model with CacheOrFallback
    - Complete, ordered Menu projection including unavailable items, 300-second cached entries, and PostgreSQL fallback recording the failure when Redis is unreachable
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 21.2, 21.3, 21.4_

  - [x] 8.4 Write property test for the Menu read model
    - **Property 12: Menu read models are complete, ordered, and availability-preserving**
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.7**

  - [x] 8.5 Write property test for cache equality and invalidation
    - **Property 13: Cached reads equal uncached reads and reflect every committed write**
    - **Validates: Requirements 4.5, 4.6, 4.8, 21.2, 21.3, 34.7, 59.7**

  - [x] 8.6 Write property test for Cache_Store degradation
    - **Property 14: A Cache_Store failure degrades to PostgreSQL without failing the request**
    - **Validates: Requirements 21.4**

- [ ] 9. Catalog module: Restaurant_Status lifecycle and discovery
  - [x] 9.1 Implement the Restaurant_Status lifecycle
    - The five permitted transitions, `restaurant_not_publishable` naming every unmet publication condition, suspension removing the Restaurant from Customer-facing results and rejecting checkout with `restaurant_suspended`, the `last_active_branch` guard
    - _Requirements: 18.1, 18.2, 32.4, 59.1, 59.3, 59.4, 59.5, 59.6, 59.7, 59.8_

  - [x] 9.2 Write property test for Restaurant_Status transitions
    - **Property 51: Restaurant_Status transitions are constrained and publication is conditional**
    - **Validates: Requirements 18.1, 18.2, 59.1, 59.3, 59.4, 59.5, 59.6, 59.8**

  - [x] 9.3 Write property test for the active-branch invariant
    - **Property 52: A published Restaurant always retains an active Restaurant_Branch**
    - **Validates: Requirements 32.4, 32.3**

  - [~] 9.4 Implement Restaurant discovery, relevance ranking, and search cursors
    - Serviceability-filtered search with conjunctive cuisine, rating, and fee filters; the four-tier deterministic `RelevanceRanker`; signed 15-minute cursors rejected with `invalid_cursor`; cached search pages
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.7, 3.8, 3.9, 3.10, 17.8_

  - [~] 9.5 Write property test for the qualifying Restaurant set
    - **Property 9: Restaurant discovery returns exactly the qualifying set**
    - **Validates: Requirements 3.1, 3.3, 17.8, 32.8**

  - [~] 9.6 Write property test for relevance ranking
    - **Property 10: Relevance ranking is a deterministic total order**
    - **Validates: Requirements 3.2**

  - [~] 9.7 Write property test for search cursor authenticity
    - **Property 11: Search cursors are authentic and expire**
    - **Validates: Requirements 3.9**

  - [~] 9.8 Write an example test for the empty Restaurant list
    - HTTP 200 with an empty list, total 0, and no next cursor
    - _Requirements: 3.10_

- [~] 10. Checkpoint - catalog and zones
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 11. Cart module
  - [~] 11.1 Implement the Redis-backed Cart repository and document
    - Cart document with ULID line identifiers under `cart:v1:{customerId}`, 72-hour sliding expiry reset only on an accepted write, empty-Cart read with a Line_Subtotal of 0
    - _Requirements: 6.6, 6.7, 6.11, 21.1_

  - [~] 11.2 Implement CartService mutations and invariants
    - Add, quantity change, quantity 0 removal, single-Restaurant rule with `cart_restaurant_conflict`, modifier bounds with `invalid_modifier_selection`, variant rules with `variant_selection_required` / `invalid_variant_selection`, availability gating with `item_unavailable`, the 50-line bound, and the mapped 404 codes
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.8, 6.9, 6.10, 34.3, 34.4_

  - [~] 11.3 Write property test for Cart invariants
    - **Property 20: Cart mutations preserve the Cart invariants**
    - **Validates: Requirements 6.1, 6.2, 6.3, 6.5, 6.6, 6.10**

  - [~] 11.4 Write property test for Product_Variant selection rules
    - **Property 22: Variant selection rules are enforced exactly**
    - **Validates: Requirements 34.3, 34.4**

  - [~] 11.5 Write an example test for the empty Cart response
    - HTTP 200, empty Cart_Line collection, Line_Subtotal 0
    - _Requirements: 6.11_

- [ ] 12. Pricing module: Pricing_Engine and Promotions
  - [x] 12.1 Implement Platform_Configuration storage and the read-only boundary
    - Single-row `platform_configuration` table with its checks, seeded defaults, and `PlatformConfigurationReader` exposing service-fee bounds, Offer_Radii, reason lists, and Notification defaults
    - _Requirements: 37.9, 37.10, 16.12_

  - [x] 12.2 Implement the Pricing_Engine components in integer minor units
    - Line_Subtotal, distance-based Delivery_Fee clamped to its bounds with the 20.000 km cap, percentage Service_Fee clamped to its bounds, Tax_Amount before discount, Order_Total in 0–99,999,999, exactly one half-up rounding step per produced value, provisional breakdown when no Delivery_Address is selected
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.8, 7.9, 7.10, 7.12, 34.2_

  - [x] 12.3 Write property test for pricing determinism and component correctness
    - **Property 24: Pricing is integer-exact, component-correct, and deterministic**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.8, 7.9, 7.10, 7.12, 34.2**

  - [x] 12.4 Implement Promotions, eligibility conditions, and discount clamping
    - `promotions` and `promotion_conditions` tables, single-code rule, activation window evaluated on the injected `Clock`, Discount_Amount clamped to 0..Line_Subtotal + Delivery_Fee, `promotion_not_applicable` rejections
    - _Requirements: 7.5, 7.6, 7.7, 18.4_

  - [ ] 12.5 Write property test for discount clamping and ineligible Promotions
    - **Property 25: Discount_Amount is clamped and ineligible Promotions change nothing**
    - **Validates: Requirements 7.5, 7.6, 7.7**

  - [x] 12.6 Write unit tests for each Pricing_Engine component
    - One test per component including bound and rounding edges
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 23.2_

  - [x] 12.7 Implement the demonstration data seeder with a default Administrator account
    - Implement the seeder invoked by the documented seed command of task 1.11, producing the shared seeded baseline the design's "Fixtures and data" section describes: at least 3 Restaurants, 5 Restaurant_Branches with resolved coordinates and assigned Delivery_Zones, 20 Menu_Items, 5 Product_Variants, and exactly 1 Platform user per Role
    - Seed a default Administrator with Role `administrator` and Account_Status `active`, reading its email and password from `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD`; document both in the example environment file with non-secret local placeholders and never commit a production credential or a hardcoded password literal
    - Store the seeded Administrator password only as a salted adaptive hash, the same write path as registration
    - Make every seeded record idempotent by matching on its natural key (`email_lower` for Platform users, Restaurant and Branch names, Menu_Item and Product_Variant names within their parent) so re-running the seed command creates no duplicate Administrator and no duplicate demonstration rows
    - Seed through the owning modules' write paths only (`UserDirectory`, Account, Catalog, Zone, and the Platform_Configuration defaults of task 12.1), never cross-module SQL
    - _Requirements: 24.7, 24.6, 24.8, 1.4, 2.1, 44.7_

  - [~] 12.8 Write example tests for demonstration seeder idempotency
    - Assert that running the seeder twice leaves exactly one Platform user per Role, exactly one Administrator matching `SEED_ADMIN_EMAIL`, and unchanged Restaurant, Restaurant_Branch, Menu_Item, and Product_Variant counts at or above the required baseline
    - Assert the seeded Administrator can sign in and that no plaintext password is persisted
    - _Requirements: 24.7, 2.1, 1.4_

- [ ] 13. Order module: Order_State_Machine and Order_Timeline
  - [x] 13.1 Create the Order schema, models, and repositories
    - Migrations for `orders`, `order_lines`, `order_line_modifiers`, `order_timeline_entries` (append-only), `idempotency_keys` with the documented unique constraints, checks, and indexes
    - _Requirements: 8.1, 8.2, 8.3, 40.1, 40.2, 40.3, 40.4, 40.5, 40.6_

  - [~] 13.2 Implement the framework-free Order_State_Machine
    - The 18 permitted edges as data over the 12 Order_States with the per-edge permitted-actor sets, edge membership evaluated before actor permission, `invalid_state_transition` / `forbidden` / `cancellation_window_closed` outcomes
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.7, 10.8, 10.9, 10.10_

  - [~] 13.3 Write property test for the permitted edge set
    - **Property 32: The Order_State_Machine permits exactly the 18 edges**
    - **Validates: Requirements 10.1, 10.2, 10.7, 10.8**

  - [~] 13.4 Write property test for transition refusal ordering
    - **Property 33: Transition refusals evaluate membership before actor permission**
    - **Validates: Requirements 10.3, 10.4, 10.5, 10.9, 10.10, 33.4, 33.5, 35.10**

  - [~] 13.5 Implement the transition service with timeline and post-commit events
    - Row-lock, machine assertion, state update, one appended Order_Timeline entry with actor identity and UTC timestamp, and one `order.state_changed` domain event collected in the transaction and dispatched after commit
    - _Requirements: 10.6, 10.11, 10.15, 40.8, 40.9_

  - [~] 13.6 Write unit tests for every edge and rejection
    - All 18 permitted transitions, at least one rejected transition per Order_State, and one rejected actor per edge
    - _Requirements: 10.1, 10.3, 23.2_

- [ ] 14. Order module: checkout, snapshots, and scoped reads
  - [~] 14.1 Implement the deterministic BranchSelector
    - Nearest active, open, servicing Restaurant_Branch with ties broken by ascending identifier, `restaurant_closed` and `no_branch_available` rejections
    - _Requirements: 8.4, 32.9, 32.10_

  - [~] 14.2 Write property test for fulfilling-branch selection
    - **Property 29: The fulfilling Restaurant_Branch is selected deterministically**
    - **Validates: Requirements 8.4, 32.9, 32.10**

  - [x] 14.3 Implement the checkout use case
    - Idempotency lookup and record per (Customer, Idempotency_Key), Cart and availability validation, Order_Number generation with at most 5 retries then `internal_error`, immutable Order_Line and address snapshots, stored breakdown, single transaction committing in `pending_payment`, Cart deletion only on reaching `confirmed`
    - _Requirements: 8.1, 8.2, 8.3, 8.5, 8.6, 8.7, 8.8, 8.9, 40.10_

  - [~] 14.4 Write property test for checkout idempotency
    - **Property 26: Checkout is idempotent per Customer and Idempotency_Key**
    - **Validates: Requirements 8.3**

  - [~] 14.5 Write property test for Order_Number well-formedness and uniqueness
    - **Property 27: Order_Numbers are well-formed and unique**
    - **Validates: Requirements 8.2**

  - [~] 14.6 Write property test for Order snapshot immutability
    - **Property 28: Order snapshots are immutable under later catalog change**
    - **Validates: Requirements 8.1, 8.7, 31.10, 34.10**

  - [~] 14.7 Write property test for availability gating
    - **Property 21: Availability gates new selections and never alters existing records**
    - **Validates: Requirements 6.4, 8.5, 11.5, 34.5, 34.8, 34.9**

  - [~] 14.8 Implement the Order read endpoints with Role scoping
    - Single-Order and list reads scoped per Role with the non-disclosing `order_not_found` helper, and the Customer, Operator, and Staff active-order lists
    - _Requirements: 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 12.7, 12.8, 19.1, 19.2_

- [~] 15. Checkpoint - ordering path
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 16. Payment module
  - [x] 16.1 Implement the payment schema, Payment_Gateway interface, and Simulated_Payment_Gateway
    - Migrations for `payment_intents`, `payment_events`, `refunds` with the documented unique and partial-unique constraints; the gateway abstraction bound at boot with the deterministic in-process simulator making no outbound call
    - _Requirements: 36.1, 36.2, 36.3, 36.4, 36.5, 36.8, 9.13, 23.8_

  - [~] 16.2 Implement the Payment_Intent lifecycle reacting to order.state_changed
    - Exactly one Payment_Intent per Order for the Order_Total, authorization in a queued Job with 3 retries at 1/2/4 seconds, capture once, decline and exhausted-retry handling, refund and void paths ending at `refunded`, timeline records, gateway Idempotency_Key derived from the Order identifier alone
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.8, 9.9, 9.10, 9.11, 9.12, 9.14_

  - [~] 16.3 Write property test for payment outcome mapping
    - **Property 45: Payment outcomes map one-to-one onto Order_States and money movements**
    - **Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.8, 9.9, 9.10, 9.11, 9.12, 9.14**

  - [~] 16.4 Implement webhook ingestion
    - Signature verification before any effect, `400 webhook_signature_invalid` with the rejection recorded, 7-day deduplication on `(gateway, provider_event_id)`
    - _Requirements: 9.5, 9.6, 9.7_

  - [~] 16.5 Write property test for webhook processing
    - **Property 46: Webhook processing is signature-gated and idempotent**
    - **Validates: Requirements 9.5, 9.6, 9.7**

  - [~] 16.6 Write property test for the Payment_Gateway contract
    - **Property 47: Every bound Payment_Gateway adapter satisfies the same contract deterministically**
    - **Validates: Requirements 9.13, 36.4, 36.8**

  - [~] 16.7 Write example tests for the documented simulator inputs and boot binding
    - The five documented simulator inputs and the Payment_Gateway binding resolved at boot
    - _Requirements: 36.2, 36.5_

- [ ] 17. Restaurant order handling
  - [~] 17.1 Implement the accept, reject, preparing, and ready actions
    - Preparation time in 1–240 whole minutes, rejection reason drawn from the Platform_Configuration list, transitions driven through the Order_State_Machine
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5_

  - [~] 17.2 Write property test for Restaurant order handling
    - **Property 36: Restaurant order handling records exactly what was submitted**
    - **Validates: Requirements 12.2, 12.3, 12.4, 12.5**

  - [~] 17.3 Implement the Operator and Staff active-order queues
    - Scoped, creation-time-ascending lists excluding other branches from results and totals
    - _Requirements: 12.7, 12.8_

  - [~] 17.4 Implement the un-accepted-Order reminder schedule
    - Scheduler-driven queued Job sending the first reminder at 10 minutes then every 5 minutes, stopping at the earlier of leaving `confirmed` and 6 reminders
    - _Requirements: 12.6_

- [ ] 18. Dispatch module
  - [x] 18.1 Implement the Dispatch schema and Delivery_Job creation
    - Migrations for `delivery_jobs` and `delivery_job_offers` with the partial unique active-job index; job creation on Order acceptance carrying Pickup_Location and Drop_Off_Location
    - _Requirements: 13.1, 40.2, 40.3, 40.6_

  - [~] 18.2 Implement the Available_Courier predicate and the self-rescheduling offer cycle
    - One predicate object evaluated identically in the SQL candidate query and the in-memory classifier; at most 10 nearest Couriers in the current Offer_Radius; widening once to the maximum at 120 seconds; 30-second re-evaluation when empty; escalation at 600 seconds withdrawing every offer and marking the Administrator_Queue
    - _Requirements: 13.2, 13.5, 13.6, 13.8, 13.9, 13.10_

  - [~] 18.3 Write property test for the offered Courier set
    - **Property 38: The offered Courier set is exactly the nearest Available_Couriers in radius**
    - **Validates: Requirements 13.2, 13.8, 13.10**

  - [~] 18.4 Write property test for reminder and escalation schedules
    - **Property 37: Time-driven reminder and escalation schedules are exact and bounded**
    - **Validates: Requirements 12.6, 13.5, 13.6, 14.8**

  - [~] 18.5 Implement offer acceptance, withdrawal, and the one-active-job invariant
    - Conditional `UPDATE ... WHERE assigned_courier_id IS NULL` resolving one winner, `job_already_assigned` for losers, sibling offers withdrawn within 2 seconds, `courier_has_active_job` guard
    - _Requirements: 13.3, 13.4, 13.7, 13.11_

  - [~] 18.6 Write property test for assignment exclusivity
    - **Property 39: Delivery_Job assignment has exactly one winner and one active job per Courier**
    - **Validates: Requirements 13.1, 13.3, 13.4, 13.7, 13.11**

  - [~] 18.7 Implement Courier execution actions and Delivery_Job closure
    - Pickup, start, delivered, and failure reporting guarded by assignment with `delivery_not_found`, outcomes `delivered` / `failed` / `cancelled`, failure escalation with an Administrator Notification within 60 seconds
    - _Requirements: 14.1, 14.2, 14.6, 14.8, 35.9, 35.10_

  - [~] 18.8 Write property test for Courier execution guards
    - **Property 40: Courier execution actions are guarded and close the Delivery_Job correctly**
    - **Validates: Requirements 14.1, 14.2, 14.6, 35.9, 35.10**

  - [~] 18.9 Wire assignment, readiness, and withdrawal to Order_State and Rider_Status
    - Assignment at `ready_for_pickup` transitioning to `assigned_to_rider`, deferred transition on reaching `ready_for_pickup`, withdrawal returning to `ready_for_pickup`, Rider_Status following assignment and closure
    - _Requirements: 10.12, 10.13, 10.14, 35.3, 35.8_

  - [~] 18.10 Write property test for automatic state coupling
    - **Property 35: Assignment, readiness, and withdrawal drive the Order_State automatically**
    - **Validates: Requirements 10.12, 10.13, 10.14, 35.3, 35.8**

- [ ] 19. Rider module
  - [x] 19.1 Implement the Courier profile schema and CourierProfileService
    - `courier_profiles` migration with Rider_Status and vehicle-type checks, encrypted phone column, profile read and partial update
    - _Requirements: 58.1, 58.2, 58.3, 58.5_

  - [~] 19.2 Implement RiderStatusService with Tracking_Session coupling
    - `goOnline` requiring vehicle type and phone with `courier_profile_incomplete`, `goOffline` rejected with `active_delivery_in_progress` while a job is active, session open and close, suspension forcing `offline` only when no active job exists
    - _Requirements: 35.1, 35.2, 35.4, 35.5, 58.4, 58.6_

  - [~] 19.3 Write property test for Rider_Status transitions
    - **Property 44: Rider_Status transitions respect profile completeness and active work**
    - **Validates: Requirements 35.1, 35.2, 35.4, 35.5, 58.1, 58.4, 58.6**

  - [~] 19.4 Implement RiderWorkService and CourierDirectory
    - Assigned job list, paginated history, date-range summary, and the candidate query consumed by Dispatch
    - _Requirements: 35.6, 35.7, 35.11_

- [ ] 20. Tracking module
  - [~] 20.1 Implement the Tracking schema and the Location_Report acceptance predicate
    - Migrations for `courier_current_locations`, `courier_location_history`, `tracking_sessions`; the fixed-order composed predicate over field ranges, the +60 s / −300 s timestamp window, Rider_Status membership, and the 10-second `locrate:{courierId}` gate
    - _Requirements: 14.3, 14.4, 43.1, 43.2, 43.4, 43.5, 43.9_

  - [~] 20.2 Write property test for Location_Report acceptance
    - **Property 41: Location_Report acceptance is a fixed composed predicate**
    - **Validates: Requirements 14.3, 14.4, 43.4, 43.5, 43.9**

  - [~] 20.3 Implement the accepted-report effect ordering
    - Upsert the single current-location row, append one history row, publish the domain event, then broadcast `courier.location_updated`, in that order, with rejections producing no effect
    - _Requirements: 14.5, 42.3, 42.4, 43.6_

  - [~] 20.4 Write property test for report effects
    - **Property 42: Accepted reports produce ordered effects and rejected reports produce none**
    - **Validates: Requirements 14.5, 42.3, 42.4, 43.6**

  - [~] 20.5 Implement the retention prune and the Courier movement simulator command
    - Scheduled prune retaining exactly the last 30 days since Delivery_Job closure, plus the local artisan command that generates Location_Reports along a route
    - _Requirements: 14.7, 24.10, 43.7_

  - [~] 20.6 Write property test for Courier_Location retention
    - **Property 43: Courier_Location retention keeps exactly the last 30 days**
    - **Validates: Requirements 14.7**

  - [~] 20.7 Implement TrackingQueryService for Order tracking
    - Latest Courier_Location for an Order, authorized through the same policy as the Order read
    - _Requirements: 43.1, 43.3, 15.1_

- [~] 21. Checkpoint - fulfilment path
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 22. Notification module
  - [x] 22.1 Implement the notification schema and the NotificationComposer
    - `notifications` migration with category, channel, and delivery-status checks; composer mapping each domain event to the Notification set Requirement 16 assigns and to no other
    - _Requirements: 16.1, 16.7_

  - [~] 22.2 Implement NotificationService with preference gating and queued delivery
    - Preference gate before enqueue, one Job per channel with 3 retries at 1/2/4 seconds and a recorded final outcome, email through the configured mail transport, in-app persistence with recipient-scoped listing and `markRead`
    - _Requirements: 16.2, 16.3, 16.4, 16.5, 16.6, 16.8, 16.9, 16.10, 16.11_

  - [~] 22.3 Write property test for category tagging and preference gating
    - **Property 48: Notifications are category-tagged and preference-gated**
    - **Validates: Requirements 16.4, 16.5, 16.11, 16.12**

  - [~] 22.4 Write property test for queued delivery and outcomes
    - **Property 49: Notification delivery is queued, bounded-retry, and outcome-recorded**
    - **Validates: Requirements 16.2, 16.3, 16.6, 16.8, 16.9, 16.10**

  - [~] 22.5 Write property test for transition side effects
    - **Property 34: Every successful transition appends one timeline entry, publishes one event, and sends exactly the mapped Notifications**
    - **Validates: Requirements 10.6, 10.11, 10.15, 10.16, 12.1, 16.1, 16.7, 33.7**

- [ ] 23. Review module
  - [x] 23.1 Implement Reviews and Aggregate_Rating recomputation
    - Migrations for `reviews` and `restaurant_rating_aggregates`; submit once per delivered own Order with `order_not_reviewable` / `review_already_exists`, Administrator hiding, aggregate recomputed in the same transaction as the write
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.6, 17.7_

  - [~] 23.2 Write property test for Review eligibility and rating aggregation
    - **Property 50: Reviews are once-per-delivered-own-Order and ratings aggregate exactly**
    - **Validates: Requirements 17.1, 17.2, 17.3, 17.4, 17.6, 17.8**

  - [~] 23.3 Implement RatingReader and the Catalog projection refresh
    - `review.aggregate_updated` consumed by Catalog to refresh the projection column and invalidate affected search read models; absent rating reported as null
    - _Requirements: 17.4, 17.6, 17.8_

- [ ] 24. Admin module
  - [x] 24.1 Implement Audit_Entry recording across every Administrator write
    - `audit_entries` migration and the `AuditTrail` recorder invoked by every Administrator write path, including previous and new Restaurant_Status, and never on a rejected action
    - _Requirements: 18.6, 37.9, 59.9_

  - [~] 24.2 Write property test for Audit_Entry completeness
    - **Property 53: Every Administrator write produces exactly one complete Audit_Entry**
    - **Validates: Requirements 18.6, 37.9, 59.9**

  - [~] 24.3 Implement the Platform_Configuration administration surface
    - Validated updates to service-fee percentage and bounds, Offer_Radii, reason lists, and Notification defaults taking effect for every request after commit
    - _Requirements: 37.9, 37.10_

  - [~] 24.4 Write property test for configuration application
    - **Property 56: Platform_Configuration applies to every request after commit**
    - **Validates: Requirements 37.9, 37.10**

  - [~] 24.5 Implement the Administrator list surfaces through Module_Boundary interfaces
    - User, Restaurant, Courier, Delivery_Job, Order, and Review lists with the documented conjunctive filters and projections, plus the Administrator_Queue view, all delegating to owning modules
    - _Requirements: 18.5, 37.1, 37.2, 37.3, 37.4, 37.5, 37.6, 37.11_

  - [~] 24.6 Write property test for Administrator list filters
    - **Property 55: Administrator list filters are conjunctive and complete**
    - **Validates: Requirements 18.5, 37.1, 37.3, 37.4, 37.5**

  - [~] 24.7 Implement ReportService
    - Order counts by Order_State, gross total, mean delivery duration in whole seconds, failed Delivery_Job count, per-Restaurant counts, with the 366-day and range-order validation
    - _Requirements: 18.7, 37.7, 37.8_

  - [~] 24.8 Write property test for Report aggregation
    - **Property 54: Administrator Reports equal a reference aggregation over the requested range**
    - **Validates: Requirements 18.7, 37.7, 37.8**

  - [~] 24.9 Implement the account and Restaurant creation surfaces
    - Restaurant_Staff creation by an Operator with the 20-per-branch bound, Courier and Administrator creation, and Restaurant record creation starting in `draft`
    - _Requirements: 33.1, 33.8, 33.9, 33.11, 58.1, 58.7, 59.2_

  - [~] 24.10 Write property test for case-insensitive email uniqueness
    - **Property 3: Email uniqueness is case-insensitive across every creation path**
    - **Validates: Requirements 1.3, 31.14, 33.9**

- [ ] 25. Realtime_Gateway integration
  - [~] 25.1 Implement the Broadcaster adapter and channel resolution
    - Translate domain events into exactly the five permitted Realtime_Events, resolve the four private channel families, propagate the Correlation_Identifier, and swallow-and-log transport failures
    - _Requirements: 42.1, 42.2, 42.3, 42.5, 42.6, 15.1, 15.4_

  - [~] 25.2 Implement channel authorization callbacks
    - Delegate every subscription decision to the policy objects used by the corresponding REST read, refusing with `channel_forbidden`
    - _Requirements: 15.2, 15.3, 33.7_

  - [~] 25.3 Write property test for channel authorization parity
    - **Property 74: Realtime channel authorization equals resource authorization**
    - **Validates: Requirements 15.2, 15.3, 33.7**

  - [~] 25.4 Write property test for the permitted event set
    - **Property 75: Only the five permitted Realtime_Events are ever broadcast**
    - **Validates: Requirements 42.2**

  - [~] 25.5 Write property test for Realtime_Gateway failure isolation
    - **Property 76: A failing Realtime_Gateway never changes persisted state or request outcome**
    - **Validates: Requirements 42.6**

- [~] 26. Checkpoint - cross-cutting reactions
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 27. Order history and reorder
  - [~] 27.1 Implement the Customer Order history endpoints
    - Paginated descending history with the stored breakdown and lines, single-Order detail, and the 7-year retention guarantee expressed in the schema and archival policy
    - _Requirements: 19.1, 19.2, 19.4_

  - [~] 27.2 Implement the reorder use case
    - Build a Cart from a past Order carrying only still-available lines and reporting the omitted Order_Lines
    - _Requirements: 19.3_

  - [~] 27.3 Write property test for reorder
    - **Property 57: Reorder carries exactly the still-available lines and names the omissions**
    - **Validates: Requirements 19.3**

- [ ] 28. Cross-cutting API contract and data invariants
  - [~] 28.1 Implement the API resource layer and the OpenAPI_Document
    - Symmetric serializer/decoder pairs for every resource, the documented status mapping, ISO 8601 UTC timestamps, money shape, versioned `/api/v1` routes, and the generated OpenAPI_Document with a route-parity check
    - _Requirements: 20.1, 20.5, 20.6, 20.7, 20.8, 20.9, 20.11, 20.12, 20.13, 20.14, 20.15_

  - [~] 28.2 Write property test for resource serialization round-tripping
    - **Property 58: Delivery_API resources round-trip through serialization**
    - **Validates: Requirements 20.7**

  - [~] 28.3 Write property test for API response conventions
    - **Property 59: Every response obeys the API conventions**
    - **Validates: Requirements 20.3, 20.8, 20.9, 20.10, 28.9**

  - [~] 28.4 Write property test for client-independent responses
    - **Property 60: Responses do not depend on the calling client**
    - **Validates: Requirements 20.13**

  - [~] 28.5 Write property test for validation rejection totality
    - **Property 2: Validation rejection is total, precise, and side-effect free**
    - **Validates: Requirements 1.2, 3.8, 6.9, 11.3, 11.4, 11.8, 11.9, 17.7, 18.4, 20.4, 28.1, 31.3, 32.6, 32.12, 37.8, 37.10, 43.5, 58.3, 59.2**

  - [~] 28.6 Write property test for the authorization matrix
    - **Property 6: Authorization conforms to the Role and ownership matrix**
    - **Validates: Requirements 2.1, 2.2, 2.3, 2.10, 33.3, 33.4, 33.5, 37.2, 58.7**

  - [~] 28.7 Write property test for actor-scoped reads
    - **Property 7: Reads are scoped to the actor and non-existence is undisclosed**
    - **Validates: Requirements 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 12.7, 12.8, 16.9, 31.11, 33.2, 33.10, 35.6, 35.9**

  - [~] 28.8 Write property test for cursor pagination
    - **Property 8: Cursor pagination partitions the result set exactly once**
    - **Validates: Requirements 3.4, 16.9, 17.5, 19.1, 20.10, 20.15, 35.7, 37.1, 37.3, 37.4, 37.5**

  - [~] 28.9 Write property test for collection bounds
    - **Property 23: Collection limits are enforced at the bound and nowhere else**
    - **Validates: Requirements 6.8, 11.10, 31.7, 31.8, 32.3, 32.13, 33.1, 33.11, 34.1**

  - [~] 28.10 Implement account deletion anonymization
    - Clear or pseudonymize every personal field of the Platform user while retaining every Order record in anonymized form, and revoke all credentials
    - _Requirements: 28.6, 28.7_

  - [~] 28.11 Write property test for personal-data encryption and anonymization
    - **Property 65: Stored personal data is encrypted and recoverable, and anonymization is complete**
    - **Validates: Requirements 28.6, 28.7**

  - [~] 28.12 Write property test for literal input handling
    - **Property 66: User input is stored and returned literally, never interpreted**
    - **Validates: Requirements 28.2**

  - [~] 28.13 Write property test for multi-table write atomicity
    - **Property 68: Multi-table writes are atomic and name the failed step**
    - **Validates: Requirements 8.6, 8.8, 40.8, 40.9, 40.10**

  - [~] 28.14 Write property test for database-level constraint enforcement
    - **Property 69: Database constraints reject invalid rows even when the application is bypassed**
    - **Validates: Requirements 40.3, 40.4, 40.5, 40.12**

  - [~] 28.15 Write schema introspection and migration reversibility tests
    - One introspection test per table asserting keys, foreign keys with delete behaviour, unique and check constraints, enumerated columns, index presence, and column types; plus an apply-then-revert check against an empty database
    - _Requirements: 40.1, 40.2, 40.3, 40.4, 40.5, 40.6, 40.7, 40.11_

- [ ] 29. Web_Client foundation
  - [~] 29.1 Scaffold the Web_Client application and module structure
    - Vite + React + TypeScript app with `src/features/*`, `src/components`, generated request/response types from the OpenAPI_Document, and ESLint import rules forbidding feature-to-feature imports and non-`API_Client` HTTP access
    - _Requirements: 29.1, 29.2, 29.3, 41.1, 41.9, 41.10_

  - [~] 29.2 Implement the API_Client
    - Base URL, bearer Access_Token, Correlation_Identifier header, API_Error decoding, and the single refresh-and-retry interceptor clearing credentials and routing to sign-in on failure
    - _Requirements: 41.5, 29.9, 29.10_

  - [~] 29.3 Write property test for token refresh behaviour
    - **Property 79: Token refresh is attempted exactly once per failed request**
    - **Validates: Requirements 29.9, 29.10**

  - [~] 29.4 Implement the Query_Client, Feature_Hook pattern, and Redux_Store
    - TanStack Query instance owning all Server_State, one Feature_Hook per feature as the sole Query_Client caller, Redux Toolkit slices holding Client_State only, and mutation-driven cache invalidation
    - _Requirements: 41.2, 41.3, 41.4, 41.6, 41.7_

  - [~] 29.5 Write property test for Server_State status handling
    - **Property 78: Server_State status drives loading and submit-control state**
    - **Validates: Requirements 29.5, 41.6**

  - [~] 29.6 Write property test for mutation cache invalidation
    - **Property 80: Successful mutations invalidate exactly the affected cache keys**
    - **Validates: Requirements 41.7**

  - [~] 29.7 Implement form validation and API_Error field rendering
    - Shared form components mapping per-field validation details onto their inputs and rendering the error message
    - _Requirements: 29.4_

  - [~] 29.8 Write property test for API_Error rendering
    - **Property 77: The Web_Client renders API_Errors with per-field detail**
    - **Validates: Requirements 29.4**

- [ ] 30. Web_Client realtime and Order tracking
  - [~] 30.1 Implement the Reverb subscriber with backoff and cache invalidation
    - Private-channel subscriptions, exponential reconnection delay min(2^(n−1), 30) seconds, refetch on reconnect, and cache-key invalidation instead of direct state writes
    - _Requirements: 15.5, 15.6, 15.7, 42.7_

  - [~] 30.2 Write property test for reconnection backoff
    - **Property 81: WebSocket reconnection backs off exponentially to a 30-second cap**
    - **Validates: Requirements 15.5**

  - [~] 30.3 Implement the active Order tracking screen
    - Current Order_State display and the Courier_Location map shown exactly in `assigned_to_rider`, `picked_up`, and `out_for_delivery` when a location exists
    - _Requirements: 29.6_

  - [~] 30.4 Write property test for active Order display
    - **Property 82: Active Order display tracks the Order_State**
    - **Validates: Requirements 29.6**

  - [~] 30.5 Write example tests for reconnect refetch and advisory-event gating
    - Refetch after reconnect, and re-reading through the Query_Client before gating an action on a Realtime_Event
    - _Requirements: 15.6, 42.7_

- [ ] 31. Web_Client feature surfaces
  - [~] 31.1 Implement the Customer surfaces
    - Discovery and search, Menu browsing, Cart, checkout with address selection and Idempotency_Key, Order history, reorder, and Review submission
    - _Requirements: 29.5, 29.7, 3.1, 4.1, 6.1, 8.1, 19.1, 19.3, 17.1_

  - [~] 31.2 Implement the Restaurant_Operator and Restaurant_Staff surfaces
    - Incoming order queue with accept/reject/prepare/ready actions, Menu and availability management, hours, branches, and Restaurant_Staff accounts
    - _Requirements: 12.1, 12.2, 12.7, 12.8, 11.1, 11.5, 11.7, 32.1, 33.1_

  - [~] 31.3 Implement the Courier surfaces
    - Rider_Status control, offered jobs, accept, pickup, start, delivered, failure reporting, location reporting, and history
    - _Requirements: 35.1, 35.6, 35.7, 13.3, 14.1, 14.2, 14.3_

  - [~] 31.4 Implement the Administrator surfaces
    - User, Restaurant, Courier, Delivery_Job and Order lists, Administrator_Queue, Review hiding, Platform_Configuration editing, and Reports
    - _Requirements: 18.1, 18.5, 18.7, 37.1, 37.3, 37.4, 37.5, 37.6, 37.9_

  - [~] 31.5 Write frontend tests including accessibility assertions
    - Component, Feature_Hook, form, auth-flow, Order-workflow, and Realtime_Event tests against MSW doubles, plus axe, keyboard-traversal, and contrast checks on the five named screens
    - _Requirements: 29.7, 29.8, 23.10_

- [ ] 32. Enforcement rules and delivery pipelines
  - [~] 32.1 Implement the static boundary enforcement rules with violation fixtures
    - Deptrac layers for the 14 modules and the layer chain, custom PHPStan rules for Payment_Provider containment and cross-module model references, ESLint import rules for the Web_Client call chain, each paired with a fixture proving the rule fails on a violation
    - _Requirements: 22.4, 22.8, 36.6, 36.7, 38.8, 41.8_

  - [~] 32.2 Implement the Build_Pipeline workflow
    - Static analysis, the five separately selectable suites, coverage reporting with uncovered lines, suite and pipeline timing guards, OpenAPI parity, environment-variable parity, dependency vulnerability and committed-secret scans, and commit-message conventions
    - _Requirements: 23.5, 23.6, 23.7, 23.9, 23.11, 25.1, 25.2, 25.3, 25.4, 25.5, 25.6, 25.7, 25.8, 28.8, 44.6, 44.7, 47.9, 47.10, 48.6, 48.7, 48.8, 56.4, 56.6_

  - [~] 32.3 Implement the Deploy_Pipeline workflow
    - OIDC_Trust credential exchange, image build and push by commit SHA, migration step, ECS service update, steady-state wait, Smoke_Test_Suite invocation, and previous-image restoration on failure
    - _Requirements: 25.9, 47.1, 47.2, 47.3, 47.5, 47.6, 47.7, 47.8_

  - [~] 32.4 Implement the Smoke_Test_Suite
    - Post-deploy checks over health, authentication, Restaurant discovery, and Order placement against the deployed environment
    - _Requirements: 47.4_

  - [~] 32.5 Implement the acceptance-criterion-to-test mapping checker
    - Script asserting every acceptance criterion identifier in `requirements.md` maps to at least one test naming it, wired into the Build_Pipeline
    - _Requirements: 23.1, 48.10_

- [~] 33. Final checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP, though skipping them abandons the Test-Driven Development order of Requirement 48 and the 100-percent coverage obligation of Requirement 23.6.
- Backend code is PHP 8.4 on Laravel 12; Web_Client code is TypeScript with React and Vite, as bound in the design's technology table.
- Each property test carries the tag comment `// Feature: food-delivery-platform, Property N: <title>` and runs at least 100 generated inputs with seed and shrink reporting.
- Time-dependent behaviour is driven through the injected `Clock`; identifier generation through `IdGenerator`. No test calls wall-clock time or performs an outbound HTTP request.
- Task 12.7 owns the demonstration data seeder, including the default Administrator account; it is the shared seeded baseline the feature and property suites extend through factories. Its credentials come from environment variables with non-secret local placeholders, never a committed secret.
- Checkpoints sit at the end of the foundation, identity, catalog, ordering, fulfilment, reaction, and final phases.

## Customer ordering happy-path — implemented and demoable

A complete customer ordering flow now works end-to-end through Docker, verified
against the running stack (login → browse → cart → checkout → pay → confirmed →
order view, plus idempotent checkout replay):

- **Cart module** (`app/Modules/Cart`): Redis-backed `CartService` under
  `cart:v1:{customerId}` with 72-hour sliding expiry, single-Restaurant rule
  (`cart_restaurant_conflict`), availability gating (`item_unavailable`), the
  50-line bound, quantity-0 removal; `CartController` with GET/POST/PATCH/DELETE
  routes behind `auth.bearer:required`.
- **Pricing module** (`app/Modules/Pricing`): `PricingEngine` in integer minor
  units with one half-up rounding step per component (subtotal, clamped delivery
  fee, service fee %, tax, clamped discount, order total).
- **Order module** (`app/Modules/Order`): `Order`/`OrderLine`/`OrderTimelineEntry`
  models, framework-free `OrderStateMachine` (edge membership asserted before
  actor permission), `OrderTransitionService` (row-locked transition + one
  timeline entry), `CheckoutService` (idempotent per Customer+Idempotency-Key,
  immutable line/address snapshots, stored breakdown, deterministic branch
  selection, Order_Number generation), `OrderQueryService` (role-scoped reads
  with non-disclosing `order_not_found`).
- **Payment module** (`app/Modules/Payment`): deterministic
  `SimulatedPaymentGateway` (no outbound call), `PaymentService` that creates one
  Payment_Intent per Order and drives `pending_payment → confirmed` on capture or
  `→ payment_failed` on decline.
- **Catalog**: added the `RestaurantBranch` model; the demo seeder now creates one
  active branch per Restaurant so checkout can select a fulfilling branch.
- **Order status check constraint** widened to the full 12 Order_States.
- **Frontend**: global stylesheet (`src/index.css`), redesigned auth screen, and a
  styled customer app — restaurant/menu browsing with Add-to-cart, a sticky cart
  panel with quantity controls and checkout, and an orders view with a
  live-refreshing status timeline. New feature hooks: `cart/useCart`,
  `orders/useOrders`.

## Operational modules — restaurant handling, dispatch, rider (implemented)

Building on the customer happy path, the fulfilment chain now works end-to-end
(verified: customer order+pay → operator accept+ready → courier online→accept→
pickup→transit→deliver → order `delivered` with a full timeline):

- **Restaurant order handling** (`Order` module): `RestaurantOrderService` +
  `RestaurantOrderController` give the operator/staff a Restaurant-scoped order
  queue and the accept (`confirmed→preparing`), reject (`confirmed→rejected`),
  and ready (`preparing→ready_for_pickup`) actions. Routes under
  `/api/v1/restaurant/orders`. Marking an order ready creates its Delivery_Job.
- **Rider** module: `CourierProfile` model, `RiderService` + `RiderController` —
  profile read/update (vehicle type, phone), go online (requires a complete
  profile, else `courier_profile_incomplete`) and go offline (blocked while a
  delivery is active with `active_delivery_in_progress`). Routes under
  `/api/v1/rider`.
- **Dispatch** module: `DeliveryJob` model, `DispatchService` +
  `DispatchController` — unassigned job list, atomic accept (conditional UPDATE
  so exactly one courier wins, else `job_already_assigned`; one active job per
  courier via `courier_has_active_job`), and pickup/transit/deliver actions that
  couple the Order to `assigned_to_rider → picked_up → out_for_delivery →
  delivered`. Routes under `/api/v1/dispatch`.
- **Seeder**: the demo operator and staff are now linked to Bella Napoli (and its
  branch) so their order queue is scoped; the courier account can complete its
  profile and work jobs.

## Admin oversight + role dashboards (implemented)

- **Admin** module: `AdminService` + `AdminController` expose Administrator-only
  read surfaces under `/api/v1/admin` — a summary (order counts by status, gross
  revenue, restaurant/courier/active-delivery counts) and lists of orders,
  restaurants, couriers, and delivery jobs. Guarded by `role:administrator`
  (verified: a customer receives 403).
- **Frontend role dashboards**: `App.tsx` now routes by Role —
  `restaurant_operator`/`restaurant_staff` → OperatorDashboard (kitchen queue
  with accept/reject/ready), `courier` → CourierDashboard (profile, go
  online/offline, available jobs, accept → pickup → start → deliver),
  `administrator` → AdminDashboard (stat cards + orders/couriers tabs),
  `customer` → the existing browse/cart/orders surface.

## Notifications + Reviews (implemented)

- **Notification** module: `Notification` model + `NotificationService` +
  `NotificationController`. Every Order transition records a recipient-scoped
  in-app notification (via `OrderTransitionService`), with per-state messages.
  Routes: `GET /api/v1/notifications` (list + unread count) and
  `POST /api/v1/notifications/read`. Frontend: a notification bell in the topbar
  with an unread badge and dropdown (polls every 10 s, marks read on open).
- **Review** module: `Review` model + `ReviewService` + `ReviewController`. A
  customer reviews a delivered own Order once (`order_not_reviewable` /
  `review_already_exists`); the Restaurant rating aggregate is recomputed in the
  same transaction and reflected on the Restaurant row used by discovery. Routes:
  `POST /api/v1/orders/{order}/review` and public
  `GET /api/v1/restaurants/{restaurant}/reviews`. Frontend: a star-rating card on
  the delivered order screen.

Verified end-to-end against the running stack: customer order → pay → operator
accept+ready → courier accept→pickup→transit→deliver → 7 customer notifications
recorded → review submitted and reflected → admin summary shows the delivered
order and gross revenue.

Scope note: customer, fulfilment, admin, notification, and review paths are
complete with UIs. Still to do: live Tracking (courier location on a map), the
Realtime gateway (websocket push instead of polling), the Zone/serviceability
module, and the property/feature test suites for all modules built in these
sessions. The current UIs use short-interval polling in place of realtime push.

## Local Docker run — status and fixes applied

The full six-service Local_Dev_Environment now boots and serves on Docker. Fixes applied while bringing it up:

- `app/Shared/Support/Logging/StructuredLogChannel.php`: an empty `LOG_STRUCTURED_STREAM` arrived as `''` (not `null`), so the channel tried to open the working directory `/var/www/html/` in append mode and masked every real error behind an `UnexpectedValueException`. Empty strings are now coalesced to the configured file path.
- `database/migrations/2024_01_07_000001_create_remaining_tables.php`: replaced the non-existent `Blueprint::geometryType()` with Laravel 12's `geometry('geometry', 'Polygon', 4326)`, and added `CREATE EXTENSION IF NOT EXISTS postgis` before the geometry column so the Zone table migrates on the `postgis/postgis` image.
- `composer.json` / `composer.lock`: added `laravel/reverb` (the `reverb` service runs `php artisan reverb:start`, which did not exist until the package was installed). Published `config/reverb.php` and `config/broadcasting.php`.
- `bootstrap/app.php`: the `Cors` middleware was only in the `api` route group, so a browser preflight (`OPTIONS` to a POST-only route, answered by Laravel's implicit handler outside the group) got no CORS headers and the browser blocked login. `Cors` is now also prepended globally so it answers preflights; it is a no-op unless an allowed `Origin` is present.
- `routes/api.php`: the `/auth/me` and `/auth/logout` routes used `auth:api`, but this application defines no `api` auth guard (it authenticates with the custom `AuthenticateBearer` middleware), so `/auth/me` returned 500 and the Web_Client bounced straight back to the sign-in screen after a successful login. Both routes now use `auth.bearer:required`.
- Frontend stability: pinned Vite HMR to `localhost:5173` with watch polling (`vite.config.ts`) so the dev server stops issuing periodic full-page reloads across the Windows bind mount, and set conservative TanStack Query defaults (`main.tsx`). The sign-in form now pre-fills the demo customer account.
- `docker/backend/Dockerfile`: enabled OPcache (`enable=1`, `enable_cli=1`, `validate_timestamps=0`). Without it, every request recompiled the framework from the Windows bind mount, making each request 5–19 s; with it, requests are ~4 ms. Because `validate_timestamps=0`, a backend source edit requires `docker compose restart backend` to take effect.
- **Backend HTTP server switched from `php artisan serve` to nginx + php-fpm** (`docker/backend/Dockerfile`, `nginx.conf`, `serve-http.sh`; `docker-compose.yml` backend command is now `serve-http`). `artisan serve` is single-process and serialised every request, so the SPA's parallel calls (login + me + cart + restaurants) queued and the app appeared to hang — intermittent "add/checkout/sign-in not responding". The php-fpm pool (dynamic, up to 20 workers) serves them concurrently: six parallel requests dropped from 0.5–2.7 s to ~0.3–0.6 s. The queue-worker and reverb services share the image and run their CLI commands unchanged.
- The Dockerfile strips CR from the copied shell scripts (`sed -i 's/\r$//'`) so Windows CRLF line endings do not break the shebang/`exec`.
- `docker-compose.yml`: the backend healthcheck uses `wget` (no PHP process) at a 30 s interval.
- `backend/.env`: set `LOG_STRUCTURED_STREAM=php://stderr` so logs go to stderr rather than a bind-mounted file (bind-mount log I/O on Windows was a measurable per-request cost).

Verified against the running stack: all migrations apply (`migrate:fresh`), the demo seeder runs idempotently, `/api/v1/health` reports healthy (postgres, cache, queue reachable), restaurant discovery returns seeded data, and the admin sign-in flow returns access/refresh tokens. Reverb starts cleanly on 8080 and the Vite dev server serves on 5173.

Start command: `docker compose --env-file ./backend/.env up -d --build`

Known non-blocking test-environment artifacts (unrelated to the Docker runtime): `Tests\Unit\LocalEnvironment\DockerComposeTest` reads `docker-compose.yml` from the repo root, which is not bind-mounted into the backend container, so it only passes from a full checkout (CI); the CORS and rate-limit property tests are sensitive to shared Redis state across eris iterations.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.11"] },
    { "id": 2, "tasks": ["1.3", "1.4", "1.12"] },
    { "id": 3, "tasks": ["1.5", "1.6", "1.7", "1.8", "1.9"] },
    { "id": 4, "tasks": ["1.10", "3.1"] },
    { "id": 5, "tasks": ["3.2", "6.1"] },
    { "id": 6, "tasks": ["3.3", "3.4", "3.5", "6.2", "6.3", "6.4"] },
    { "id": 7, "tasks": ["3.6", "4.1", "6.5"] },
    { "id": 8, "tasks": ["3.7", "4.2", "6.6", "6.7"] },
    { "id": 9, "tasks": ["3.8", "4.3", "4.4", "4.5", "6.8", "6.9", "7.1"] },
    { "id": 10, "tasks": ["4.6", "7.2", "12.1"] },
    { "id": 11, "tasks": ["4.7", "4.8", "7.3", "7.4", "8.1"] },
    { "id": 12, "tasks": ["7.5", "7.6", "8.2", "12.2"] },
    { "id": 13, "tasks": ["8.3", "9.1", "12.3", "12.4"] },
    { "id": 14, "tasks": ["8.4", "8.5", "8.6", "9.2", "9.3", "12.5", "12.6"] },
    { "id": 15, "tasks": ["9.4", "11.1", "12.7", "13.1"] },
    { "id": 16, "tasks": ["9.5", "9.6", "9.7", "9.8", "11.2", "12.8", "13.2"] },
    { "id": 17, "tasks": ["11.3", "11.4", "11.5", "13.3", "13.4", "13.5"] },
    { "id": 18, "tasks": ["13.6", "14.1", "22.1"] },
    { "id": 19, "tasks": ["14.2", "14.3", "22.2"] },
    { "id": 20, "tasks": ["14.4", "14.5", "14.6", "14.7", "14.8", "22.3", "22.4"] },
    { "id": 21, "tasks": ["16.1", "17.1", "23.1"] },
    { "id": 22, "tasks": ["16.2", "17.2", "17.3", "23.2", "23.3"] },
    { "id": 23, "tasks": ["16.3", "16.4", "17.4", "22.5"] },
    { "id": 24, "tasks": ["16.5", "16.6", "16.7", "19.1"] },
    { "id": 25, "tasks": ["18.1", "19.2", "20.1"] },
    { "id": 26, "tasks": ["18.2", "19.3", "19.4", "20.2"] },
    { "id": 27, "tasks": ["18.3", "18.4", "18.5", "20.3"] },
    { "id": 28, "tasks": ["18.6", "18.7", "20.4", "20.5"] },
    { "id": 29, "tasks": ["18.8", "18.9", "20.6", "20.7"] },
    { "id": 30, "tasks": ["18.10", "24.1", "24.3", "25.1", "25.2"] },
    { "id": 31, "tasks": ["24.2", "24.4", "24.5", "24.7", "25.3", "25.4", "25.5", "27.1"] },
    { "id": 32, "tasks": ["24.6", "24.8", "24.9", "27.2", "28.1", "28.10"] },
    { "id": 33, "tasks": ["24.10", "27.3", "28.2", "28.3", "28.4", "28.11", "28.15"] },
    { "id": 34, "tasks": ["28.5", "28.6", "28.7", "28.8", "28.9", "28.12", "28.13", "28.14"] },
    { "id": 35, "tasks": ["29.1"] },
    { "id": 36, "tasks": ["29.2", "29.4"] },
    { "id": 37, "tasks": ["29.3", "29.5", "29.6", "29.7"] },
    { "id": 38, "tasks": ["29.8", "30.1", "30.3"] },
    { "id": 39, "tasks": ["30.2", "30.4", "30.5", "31.1"] },
    { "id": 40, "tasks": ["31.2", "31.3", "31.4"] },
    { "id": 41, "tasks": ["31.5", "32.1"] },
    { "id": 42, "tasks": ["32.2", "32.5"] },
    { "id": 43, "tasks": ["32.3", "32.4"] }
  ]
}
```
