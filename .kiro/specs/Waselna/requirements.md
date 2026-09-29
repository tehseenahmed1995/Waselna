# Requirements Document

## Introduction

The Food Delivery Platform is an API-first, production-ready system that connects Customers ordering food, Restaurants preparing it, and Couriers delivering it, with an Admin function overseeing the marketplace. The backend is a Laravel modular monolith exposing a versioned REST API backed by PostgreSQL, Redis (cache and queues), and Laravel Reverb (realtime). The frontend is a React + TypeScript + Vite single-page application. Local development runs on Docker Compose; production runs on AWS; delivery is automated through GitHub Actions.

This specification is also the basis of a learning and portfolio project. Therefore the deliverables include not only working software but also an explanatory Documentation_Set that records architecture, technology choices, commands, configuration, and design decisions, plus a test suite produced through Test-Driven Development. Requirements for those developer-experience concerns are stated with the same rigor as the product requirements.

The construction process itself is in scope. The Platform is built incrementally by named Agent_Roles through numbered Development_Phases, one Task_Record at a time, with a mandatory Approval_Gate before any installation, infrastructure change, schema change, architectural change, or task implementation. Requirements 49 through 57 state that process in the same EARS form as the product behavior, because in this project an undocumented or unapproved change is a defect regardless of whether the code works.

Scope of this document: functional behavior of the actor-facing surfaces (Customer, Restaurant_Operator, Restaurant_Staff, Courier, Administrator, API, Realtime) and the non-functional behavior of the Platform's infrastructure, quality, documentation, and delivery-process deliverables. Requirements 58 and 59 return to product behavior, covering how Courier accounts and Restaurant records come to exist, because every other requirement assumes those records already do. Implementation choices beyond the mandated technology stack are deferred to the design document.

Naming reconciliation: where external material uses "Rider", "Platform Admin", "Restaurant Admin", "product option", "add-on", or "delivery", this document uses `Courier`, `Administrator`, `Restaurant_Operator`, `Modifier_Option`, `Modifier_Option`, and `Delivery_Job` respectively. The Glossary is authoritative.

## Glossary

### Actors

- **Customer**: An authenticated Platform user who browses Restaurants and places Orders.
- **Restaurant_Operator**: An authenticated Platform user associated with one Restaurant who owns that Restaurant's profile, Restaurant_Branches, Opening_Hours, Menu, and Restaurant_Staff accounts, and who may additionally perform every action permitted to Restaurant_Staff of that Restaurant.
- **Restaurant_Staff**: An authenticated Platform user associated with one Restaurant_Branch who handles incoming Orders for that Restaurant_Branch and sets Menu_Item availability, and who holds no permission over the Restaurant profile, Restaurant_Branch records, Opening_Hours, Menu structure, Prices, or Restaurant_Staff accounts.
- **Courier**: An authenticated Platform user who accepts Delivery_Jobs and transports Orders to Customers.
- **Administrator**: An authenticated Platform user with Platform-wide privileges over Platform user accounts, Restaurants, Couriers, Orders, Delivery_Jobs, Menu_Categories, Menu_Items, Reviews, Reports, and Platform_Configuration.

### Systems and Components

- **Platform**: The complete Food Delivery Platform, comprising the Delivery_API, the Web_Client, and all supporting services.
- **Delivery_API**: The versioned Laravel REST API that is the single write path for all Platform data.
- **Web_Client**: The React + TypeScript + Vite single-page application that serves Customers, Restaurant_Operators, Couriers, and Administrators.
- **Auth_Module**: The Delivery_API module responsible for credential verification, token issuance, and authorization decisions. Covers the concerns named "Identity", "Authentication", and "Authorization" in external material.
- **Account_Module**: The Delivery_API module responsible for Platform user profile records, Delivery_Address records, and Notification preferences. Covers the concern named "Users" in external material.
- **Catalog_Module**: The Delivery_API module responsible for Restaurant records, Restaurant_Branches, Branch_Hours, Menus, Menu_Categories, Menu_Items, Product_Variants, Modifier_Groups, and search. Covers the concerns named "Restaurant" and "Menu" in external material.
- **Zone_Module**: The Delivery_API module responsible for Delivery_Zone definitions, address resolution, and serviceability decisions.
- **Cart_Module**: The Delivery_API module responsible for Cart state.
- **Pricing_Engine**: The component of the Pricing module that computes Order_Total from Cart contents, fees, taxes, and Promotions.
- **Order_Module**: The Delivery_API module responsible for Order records and Order_State transitions.
- **Payment_Module**: The Delivery_API module responsible for Payment_Intent lifecycle and Refunds through the Payment_Gateway.
- **Payment_Gateway**: The Payment_Module interface that abstracts payment authorization, capture, and refund so that no module outside Payment_Module depends on a Payment_Provider type, field name, or error code.
- **Simulated_Payment_Gateway**: The Payment_Gateway adapter, owned by Payment_Module, that resolves authorization, capture, refund, and webhook behavior from deterministic local rules without an outbound network call.
- **Dispatch_Module**: The Delivery_API module responsible for creating Delivery_Jobs, offering them to Couriers, assigning them, and recording Delivery_Job outcomes. Covers the concern named "Delivery" in external material.
- **Rider_Module**: The Delivery_API module responsible for Courier profile records, Rider_Status, a Courier's assigned Delivery_Job list, and a Courier's Delivery_Job history. Covers the concern named "Rider" in external material.
- **Tracking_Module**: The Delivery_API module responsible for Courier_Location records, Tracking_Session records, and Order tracking projections.
- **Realtime_Gateway**: The Laravel Reverb WebSocket server that broadcasts Realtime_Events to subscribed clients.
- **Notification_Module**: The Delivery_API module responsible for composing and dispatching Notifications.
- **Review_Module**: The Delivery_API module responsible for Reviews and Aggregate_Ratings.
- **Admin_Module**: The Delivery_API module responsible for the Administrator surfaces over Platform user accounts, Restaurants, Couriers, Orders, Delivery_Jobs, Menu_Categories, Menu_Items, Reports, and Platform_Configuration, exercised exclusively through the Module_Boundary interfaces of the owning modules.
- **Cache_Store**: The Redis instance used for cached read models, rate-limit counters, and Cart state.
- **Queue_Worker**: The process that consumes queued Jobs from the Redis queue backend.
- **GeoJSON_Parser**: The Zone_Module component that converts GeoJSON text into a Delivery_Zone geometry object.
- **GeoJSON_Printer**: The Zone_Module component that converts a Delivery_Zone geometry object into GeoJSON text.
- **Local_Dev_Environment**: The Docker Compose configuration that runs the Platform and its dependencies on a developer machine.
- **Build_Pipeline**: The GitHub Actions workflow that lints, tests, and builds the Platform on every change.
- **Deploy_Pipeline**: The GitHub Actions workflow that releases a built artifact to an AWS environment.
- **Observability_Stack**: The logging, metrics, tracing, and health-check surfaces of the Platform.
- **Documentation_Set**: The versioned Markdown documentation delivered with the source code for learning purposes, rooted at the `docs/` directory and structured as stated in Requirement 55.
- **Payment_Provider**: An external payment service reachable through a Payment_Gateway adapter. No Payment_Provider is bound in the initial implementation phase; the Simulated_Payment_Gateway is bound instead.
- **API_Client**: The single Web_Client component that issues HTTP requests to the Delivery_API, applying the base URL, the Access_Token, the Correlation_Identifier header, and API_Error decoding.
- **Feature_Hook**: A Web_Client React hook, owned by one feature directory, that exposes Server_State or a mutation to the components of that feature and is the only caller of the Query_Client for that feature.
- **Query_Client**: The Web_Client TanStack Query instance that owns fetching, caching, invalidation, loading status, and error status for all Server_State.
- **Redux_Store**: The Web_Client Redux Toolkit store that holds Client_State only.
- **Task_Board**: The versioned document at `docs/project/TASK-BOARD.md` that lists every Task_Record with its current Task_Status.

### Domain Terms

- **Restaurant**: A merchant record with a name, address, cuisine tags, Opening_Hours, one or more Restaurant_Branches, a Serviceable_Area, a Restaurant_Status, an ISO 4217 currency code, a tax rate, a base Delivery_Fee, a per-kilometre rate, a minimum Delivery_Fee, and a maximum Delivery_Fee.
- **Restaurant_Status**: Exactly one of `draft`, `pending_review`, `published`, `suspended`, held by every Restaurant. Only a Restaurant whose Restaurant_Status is `published` appears in Customer-facing Restaurant results.
- **Restaurant_Branch**: A physical outlet of one Restaurant with its own name, address, resolved coordinate pair, Branch_Hours, assigned Delivery_Zones, and active flag. Every Order is fulfilled by exactly one Restaurant_Branch.
- **Opening_Hours**: The weekly schedule during which a Restaurant accepts Orders, expressed in the Restaurant's time zone, applied to every Restaurant_Branch that defines no Branch_Hours.
- **Branch_Hours**: The weekly schedule of one Restaurant_Branch, expressed in that Restaurant_Branch's time zone, which overrides the Restaurant's Opening_Hours for that Restaurant_Branch.
- **Menu**: The collection of Menu_Categories and Menu_Items offered by one Restaurant.
- **Menu_Item**: A purchasable product with a name, description, Price, availability flag, optional Product_Variants, and optional Modifier_Groups.
- **Product_Variant**: One of a set of mutually exclusive purchasable forms of one Menu_Item, such as a size or a portion, each with its own name, Price that replaces the Menu_Item Price, and availability flag. Where a Menu_Item defines Product_Variants, exactly one Product_Variant is selected per Cart_Line.
- **Modifier_Group**: A named set of Modifier_Options attached to a Menu_Item with a minimum and maximum selection count. Named "product option group" or "add-on group" in external material.
- **Modifier_Option**: A single selectable choice within a Modifier_Group, with a Price_Delta. Named "product option" or "add-on" in external material.
- **Price**: A monetary amount stored as an integer number of minor currency units together with an ISO 4217 currency code.
- **Price_Delta**: A signed monetary amount added to a Menu_Item Price when a Modifier_Option is selected.
- **Cart**: A Customer's in-progress selection of Cart_Lines for exactly one Restaurant.
- **Cart_Line**: One Menu_Item, the selected Product_Variant where the Menu_Item defines Product_Variants, a quantity between 1 and 20, and the selected Modifier_Options.
- **Delivery_Zone**: A closed polygon, defined in GeoJSON, inside which a Restaurant delivers.
- **Serviceable_Area**: The union of the Delivery_Zones assigned to one Restaurant.
- **Delivery_Address**: A Customer-owned postal address with a resolved latitude and longitude.
- **Promotion**: A named discount rule with an activation window, eligibility conditions, and a discount value.
- **Line_Subtotal**: The sum of the line totals of every Cart_Line in a Cart or Order.
- **Delivery_Fee**: The monetary amount charged for transporting one Order from a Restaurant to a Delivery_Address.
- **Service_Fee**: The monetary amount the Platform charges per Order for marketplace services.
- **Tax_Amount**: The monetary amount of tax applied to an Order under the Restaurant's configured tax rate.
- **Discount_Amount**: The monetary amount deducted from an Order by applied Promotions.
- **Order_Total**: The sum of Line_Subtotal, Delivery_Fee, Service_Fee, and Tax_Amount, less Discount_Amount.
- **Order**: An immutable record of a purchase, holding an Order_Number, a snapshot of purchased Order_Lines and prices, a Delivery_Address, a fulfilling Restaurant_Branch, and an Order_State.
- **Order_Line**: One immutable line of an Order, holding a snapshot of the Menu_Item name, the selected Product_Variant name where one was selected, the base Price, the selected Modifier_Option names and Price_Deltas, and a quantity in the closed range 1 to 20.
- **Order_State**: Exactly one of the 12 values `pending_payment`, `confirmed`, `accepted`, `preparing`, `ready_for_pickup`, `assigned_to_rider`, `picked_up`, `out_for_delivery`, `delivered`, `rejected`, `cancelled`, `refunded`. This set is authoritative and supersedes every other Order_State list in project material: the externally used name `PENDING` denotes `pending_payment`, `ASSIGNED_TO_RIDER` denotes `assigned_to_rider`, and `OUT_FOR_DELIVERY` denotes `out_for_delivery`.
- **Order_State_Machine**: The Order_Module component that holds the permitted Order_State transition set of Requirement 10 and is the only code path permitted to change an Order_State.
- **Order_Timeline**: The append-only list of Order_State transitions with actor identity and UTC timestamp.
- **Payment_Intent**: A Payment_Provider record representing an attempt to collect the Order_Total for one Order.
- **Idempotency_Key**: A client-supplied unique string that makes a repeated write request return the original result instead of creating a second record.
- **Refund**: A Payment_Provider record that returns funds for a captured Payment_Intent.
- **Delivery_Job**: A unit of courier work linking one Order to one Courier, with a Pickup_Location and a Drop_Off_Location. Named "delivery" in external material.
- **Pickup_Location**: The resolved coordinate pair of the Restaurant_Branch fulfilling an Order, held on that Order's Delivery_Job.
- **Drop_Off_Location**: The resolved coordinate pair of the Delivery_Address of an Order, held on that Order's Delivery_Job.
- **Delivery_Job_Outcome**: Exactly one of `delivered`, `failed`, `cancelled`, recorded on a Delivery_Job when that Delivery_Job closes.
- **Available_Courier**: A Courier satisfying every availability condition stated in Requirement 13 criterion 8.
- **Offer_Radius**: The straight-line distance from a Pickup_Location within which the Dispatch_Module offers a Delivery_Job, bounded by the initial Offer_Radius and the maximum Offer_Radius held in Platform_Configuration.
- **Administrator_Queue**: The Admin_Module work list holding every Delivery_Job marked as escalated, retrievable by an Administrator as stated in Requirement 37 criterion 5.
- **Rider_Status**: Exactly one of `offline`, `online`, `on_delivery`, held by every Courier.
- **Courier_Location**: A latitude, longitude, accuracy in metres, and UTC timestamp reported by a Courier and stored by the Tracking_Module.
- **Location_Report**: The Tracking_Module request payload carrying a Courier identifier, a latitude, a longitude, an accuracy in metres, and a UTC timestamp.
- **Tracking_Session**: A Tracking_Module record that opens when a Courier's Rider_Status becomes `online` and closes when that Rider_Status becomes `offline`.
- **Platform_Configuration**: The Administrator-editable Platform-wide settings comprising the service-fee percentage, the minimum and maximum Service_Fee, the Notification_Category defaults per channel, the initial Offer_Radius, the maximum Offer_Radius, the Order rejection reason list, and the Delivery_Job failure reason list.
- **Report**: An Admin_Module read model aggregating Orders, Delivery_Jobs, Restaurants, or Couriers over a requested date range.
- **Realtime_Event**: A named message broadcast over the Realtime_Gateway on a Private_Channel.
- **Private_Channel**: A Realtime_Gateway channel whose subscription requires an authorization check by the Auth_Module.
- **Notification**: A message delivered to a Platform user through email or in-application channels.
- **Notification_Category**: Exactly one of `order_status`, `delivery_status`, `payment`, `promotion`, `account`, assigned to every Notification. Named "Notification category" in external material.
- **Job**: A unit of deferred work enqueued by the Delivery_API and executed by the Queue_Worker.
- **Review**: A Customer-authored rating from 1 to 5 with optional text, attached to one delivered Order.
- **Aggregate_Rating**: The arithmetic mean of a Restaurant's Customer-visible Review ratings rounded half up to 1 decimal place, reported as absent where that Restaurant holds no Customer-visible Review.
- **Role**: One of `customer`, `restaurant_operator`, `restaurant_staff`, `courier`, `administrator`, assigned to a Platform user.
- **Account_Status**: Exactly one of `active`, `suspended`, held by every Platform user.
- **Access_Token**: A short-lived bearer credential that authenticates Delivery_API requests.
- **Refresh_Token**: A long-lived credential that obtains a new Access_Token.
- **API_Error**: A JSON response body carrying a stable machine-readable error code, a human-readable message, and per-field validation details.
- **Correlation_Identifier**: The per-request identifier that the Delivery_API accepts in a request header or generates, returns in the response headers, and propagates into every log entry, queued Job, and Realtime_Event of that request.
- **Audit_Entry**: The record of one Administrator write action, holding the Administrator identifier, the action name, the affected record identifier, and the UTC timestamp.
- **OpenAPI_Document**: The machine-readable specification of every Delivery_API endpoint, request schema, and response schema.
- **Module_Boundary**: The public interface of a Delivery_API module, through which other modules are permitted to interact with it.
- **Backend_Layer_Chain**: The ordered Delivery_API call chain Controller → Form_Request → Service_Interface → Service_Implementation → Repository_Interface → Repository_Implementation → Eloquent_Model → PostgreSQL.
- **Form_Request**: The Delivery_API class that declares the validation schema of one endpoint and rejects a non-conforming request before a Service_Implementation is invoked.
- **Service_Interface**: The declared contract of a use case owned by one module.
- **Service_Implementation**: The class that holds the business logic satisfying one Service_Interface.
- **Repository_Interface**: The declared contract for persistence of one aggregate owned by one module.
- **Repository_Implementation**: The Eloquent-backed class satisfying one Repository_Interface.
- **Server_State**: Web_Client state whose authoritative copy is held by the Delivery_API, comprising Restaurants, Menus, Menu_Items, Carts, Orders, Delivery_Jobs, Reviews, Notifications, and Platform user records.
- **Client_State**: Web_Client state whose authoritative copy is held only by the Web_Client, comprising open or closed UI regions, transient form and wizard progress, user display preferences, client-only filter selections, and navigation state.

### Process and Governance Terms

- **Agent_Role**: One of `architect`, `backend`, `frontend`, `devops`, `qa`, `security`, each being a named responsibility scope that owns Task_Records.
- **Task_ID**: A unique identifier of the form `{MODULE}-{NNN}`, where `{MODULE}` is one of `ARCH`, `AUTH`, `REST`, `MENU`, `CART`, `ORDER`, `PAY`, `DELIVERY`, `RIDER`, `TRACK`, `ADMIN`, `NOTIFY`, `REVIEW`, `DEVOPS`, `CI`, `DOCS` and `{NNN}` is a zero-padded three-digit sequence number.
- **Task_Status**: Exactly one of `BACKLOG`, `READY`, `IN_PROGRESS`, `BLOCKED`, `READY_TO_TEST`, `TESTING`, `COMPLETED`, `DEFERRED`, `CANCELLED`.
- **Task_Record**: The Task_Board entry for one unit of work, holding Task_ID, Title, Description, Module, Owner Agent, Task_Status, Priority, Dependencies, Blocks, Requirements, Acceptance Criteria, Files, Tests, Documentation, Approval Status, Blockers, and Notes.
- **Approval_Gate**: A point in the process at which work stops until a human records explicit approval.
- **Dependency_Record**: The entry in `docs/project/TASK-DEPENDENCIES.md` naming a Task_ID, the Task_IDs it depends on, and the Task_IDs it blocks.
- **Blocker_Record**: The entry in `docs/project/BLOCKERS.md` naming the blocked Task_ID, what blocks it, why the blocking item is required, the Task_ID, decision, or resource needed, the possible solutions, and the recommended solution.
- **Handoff_Record**: The record an Agent_Role writes in `docs/project/HANDOFFS.md` when moving a Task_Record to `COMPLETED`, holding Task_ID, Task_Status, what was implemented, files changed, database changes, API changes, tests, coverage, known limitations, documentation, dependencies unlocked, and the next recommended Task_ID.
- **Module_Status**: Exactly one of `PLANNED`, `IN_DEVELOPMENT`, `DEVELOPED`, recorded per module in `docs/project/PROJECT-STATUS.md`.
- **Smoke_Test_Suite**: The test suite the Deploy_Pipeline runs against a deployed environment as stated in Requirement 47 criterion 4.
- **Project_Initialization_Report**: The Development_Phase 0 deliverable summarizing every artifact stated in Requirement 57 criterion 4.
- **ADR**: An Architecture Decision Record stored under `docs/architecture/decisions/`, holding Context, Problem, Options, Decision, Reason, Consequences, and Status.
- **Design_Pattern_Record**: The Documentation_Set entry for one applied design pattern, holding the problem, why the pattern helps, the implementation, the alternative considered, and the reason for the decision.
- **Development_Phase**: One of the 25 numbered phases 0 through 24 defined in Requirement 57.
- **Definition_of_Done**: The checklist at `docs/project/DEFINITION-OF-DONE.md` that a module satisfies before it is marked `DEVELOPED`.
- **Docs_Tree**: The directory structure of the Documentation_Set defined in Requirement 55.
- **Diagram_Set**: The set of Mermaid diagrams defined in Requirement 55.
- **OIDC_Trust**: The trust relationship by which GitHub Actions obtains temporary AWS credentials from an AWS IAM role through OpenID Connect, without a long-lived AWS access key.
- **Cost_Disclosure**: The record produced before an AWS resource is created, holding the service, the purpose, why it is needed, the expected configuration, free-tier eligibility, potential charges, how to monitor cost, and how to delete the resource.

## Requirements

### Requirement 1: Account Registration and Authentication

**User Story:** As a visitor, I want to create an account and sign in, so that I can place and track Orders under my own identity.

#### Acceptance Criteria

1. WHEN a visitor submits a registration request containing an email address of at most 254 characters in `local@domain` form, a password of at least 12 and at most 128 characters, and a display name of at least 1 and at most 100 characters, THE Auth_Module SHALL create exactly one Platform user with the Role `customer`, SHALL return the created user identifier, and SHALL exclude the submitted password from the response.
2. IF a registration request omits the email address, the password, or the display name, or contains an email address longer than 254 characters or not in `local@domain` form, a password shorter than 12 or longer than 128 characters, or a display name shorter than 1 or longer than 100 characters, THEN THE Auth_Module SHALL reject the request with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL create no Platform user.
3. IF a registration request contains an email address that already belongs to a Platform user, compared case-insensitively, THEN THE Auth_Module SHALL reject the request with HTTP status 422 and the API_Error code `email_already_registered`, and SHALL create no Platform user and SHALL leave the existing Platform user unchanged.
4. THE Auth_Module SHALL store every password as a one-way hash produced with a salted adaptive hashing algorithm, and SHALL never return a stored password hash or a plaintext password in any Delivery_API response.
5. WHEN a visitor submits credentials whose email address matches a Platform user and whose password matches that Platform user's stored password hash, THE Auth_Module SHALL issue an Access_Token that expires 60 minutes after issuance and a Refresh_Token that expires 30 days after issuance, and SHALL reset that email address's failed sign-in attempt count to 0.
6. IF a visitor submits credentials whose email address matches no Platform user or whose password does not match the stored password hash, THEN THE Auth_Module SHALL respond with HTTP status 401 and the API_Error code `invalid_credentials`, SHALL issue no Access_Token and no Refresh_Token, SHALL increment the failed sign-in attempt count for the submitted email address, and SHALL return the same API_Error code in both cases so that the response does not disclose whether the email address belongs to a Platform user.
7. IF 5 or more failed sign-in attempts for the same email address occur within any 15-minute window, THEN THE Auth_Module SHALL reject every further sign-in attempt for that email address with HTTP status 429 and the API_Error code `too_many_attempts` for 15 minutes after the 5th failed attempt, SHALL issue no Access_Token and no Refresh_Token during that period even when the submitted password matches the stored password hash, and SHALL reset the failed sign-in attempt count to 0 when the 15-minute rejection period ends.
8. WHEN a Platform user submits a Refresh_Token that is recorded for that Platform user, has not been invalidated, and has not reached its 30-day expiry, THE Auth_Module SHALL issue a new Access_Token that expires 60 minutes after issuance and a new Refresh_Token that expires 30 days after issuance, and SHALL invalidate the presented Refresh_Token.
9. IF a submitted Refresh_Token is not recorded for any Platform user, has already been invalidated, or has reached its 30-day expiry, THEN THE Auth_Module SHALL respond with HTTP status 401 and the API_Error code `invalid_refresh_token`, and SHALL issue no Access_Token and no Refresh_Token.
10. WHEN a Platform user submits a sign-out request carrying a valid Access_Token, THE Auth_Module SHALL invalidate the presented Access_Token and the Refresh_Token associated with that Access_Token, and SHALL return a success status.
11. IF a request presents an Access_Token that has been invalidated or has reached its 60-minute expiry, THEN THE Delivery_API SHALL respond with HTTP status 401 and the API_Error code `invalid_token`, and SHALL perform none of the actions of the target endpoint.

### Requirement 2: Role-Based Authorization

**User Story:** As an Administrator, I want each Platform user restricted to the actions of that user's Role, so that marketplace data stays protected.

#### Acceptance Criteria

1. WHEN a Platform user is created, THE Auth_Module SHALL assign to that Platform user exactly one Role from the set {`customer`, `restaurant_operator`, `restaurant_staff`, `courier`, `administrator`}, and SHALL never hold more than one Role or fewer than one Role for a Platform user.
2. IF a request targets an endpoint that requires authentication and carries no Access_Token, an empty Access_Token, or an Access_Token that is not presented as a bearer credential, THEN THE Delivery_API SHALL respond with HTTP status 401 and the API_Error code `unauthenticated`, SHALL perform none of the actions of the target endpoint, and SHALL leave all Platform data unchanged.
3. IF a request carries a valid Access_Token whose Role lacks permission for the target endpoint, THEN THE Delivery_API SHALL respond with HTTP status 403 and the API_Error code `forbidden`, SHALL perform none of the actions of the target endpoint, SHALL include no field values of the target resource in the response, and SHALL leave all Platform data unchanged.
4. WHEN a Restaurant_Operator requests an Order by Order identifier or Order_Number, THE Order_Module SHALL return the Order only where the Order belongs to the one Restaurant associated with that Restaurant_Operator, and WHEN a Restaurant_Staff requests an Order by Order identifier or Order_Number, THE Order_Module SHALL return the Order only where the Order is assigned to the one Restaurant_Branch associated with that Restaurant_Staff.
5. WHEN a Customer requests an Order by Order identifier or Order_Number, THE Order_Module SHALL return the Order only where the Order was placed by that Customer.
6. WHEN a Courier requests an Order by Order identifier or Order_Number, THE Order_Module SHALL return the Order only where a Delivery_Job links that Courier to the Order and either that Delivery_Job is active or the Order reached Order_State `delivered` within the preceding 24 hours.
7. WHEN an Administrator requests an Order by Order identifier or Order_Number, THE Order_Module SHALL return the requested Order in every Order_State.
8. IF a requested Order identifier or Order_Number matches no Order, or matches an Order that fails the Role scoping of criteria 4, 5, or 6, THEN THE Order_Module SHALL respond with HTTP status 404 and the API_Error code `order_not_found`, SHALL include no Order field values in the response, and SHALL return the same HTTP status and API_Error code in both cases so that the response does not disclose whether the Order exists.
9. WHEN a Platform user requests a list of Orders, THE Order_Module SHALL include only the Orders that criteria 4, 5, 6, or 7 permit that Platform user's Role to retrieve individually, and SHALL exclude every other Order from the results and from the returned total count.
10. WHEN an Administrator changes a Platform user's Role, THE Auth_Module SHALL evaluate every authorization decision for requests received from that Platform user after the change is committed against the newly stored Role, including requests presenting an Access_Token issued before the change.

### Requirement 3: Restaurant Discovery and Search

**User Story:** As a Customer, I want to find Restaurants that deliver to my address, so that I can choose where to order from.

#### Acceptance Criteria

1. WHEN a Customer requests the Restaurant list with a latitude in the closed range -90 to 90 and a longitude in the closed range -180 to 180, each carrying at most 7 fractional digits, THE Catalog_Module SHALL return only those Restaurants whose Restaurant_Status is `published` and that satisfy the Restaurant_Branch serviceability rule of Requirement 32 criterion 8 for that coordinate pair, treating a coordinate pair lying exactly on a Serviceable_Area boundary as contained.
2. WHERE a search term of at least 2 and at most 100 characters is supplied, THE Catalog_Module SHALL return only those Restaurants that also satisfy the Serviceable_Area containment of criterion 1 and whose name or one of whose cuisine tags contains the search term as a case-insensitive substring, ordered by a deterministic relevance rank in which a full name match precedes a name prefix match, a name prefix match precedes a name substring match, a name substring match precedes a cuisine tag match, and Restaurants of equal rank are ordered by ascending name and then by ascending Restaurant identifier.
3. WHERE a cuisine filter of at most 10 cuisine tags, a minimum rating filter in the closed range 1.0 to 5.0 with at most 1 fractional digit, or a maximum Delivery_Fee filter in the closed range 0 to 99,999,999 minor currency units is supplied, THE Catalog_Module SHALL return only the Restaurants that satisfy every supplied filter, treating a Restaurant as satisfying the cuisine filter where at least one of its cuisine tags equals one supplied cuisine tag, as satisfying the minimum rating filter where its Aggregate_Rating is greater than or equal to the supplied value, and as satisfying the maximum Delivery_Fee filter where its configured base Delivery_Fee is less than or equal to the supplied value, and SHALL exclude every Restaurant whose Aggregate_Rating is absent where a minimum rating filter is supplied.
4. THE Catalog_Module SHALL return Restaurant list results in pages of 20 items where no page size is supplied and of the supplied page size where a page size in the closed range 1 to 100 is supplied, and SHALL include in every Restaurant list response the total count of matching Restaurants and a next-page cursor that is present only where at least one further matching Restaurant exists beyond the returned page.
5. WHEN a Customer requests the Restaurant list, THE Catalog_Module SHALL include for each returned Restaurant an open-or-closed status computed as stated in Requirement 32 criterion 7 for that Restaurant's Restaurant_Branch at the shortest straight-line distance from the supplied coordinate pair among the Restaurant_Branches with an active flag of true whose Serviceable_Area contains that coordinate pair, reporting open where that Restaurant_Branch is open and reporting closed otherwise, including where no applicable interval is defined for that local day.
6. WHEN a Customer requests the Restaurant list, THE Catalog_Module SHALL respond within 500 milliseconds at the 95th percentile, measured from request receipt to response completion at the Delivery_API, for a catalog of 5,000 Restaurants under 100 concurrent requests.
7. IF a requested Restaurant identifier matches no Restaurant whose Restaurant_Status is `published`, THEN THE Catalog_Module SHALL respond with HTTP status 404 and the API_Error code `restaurant_not_found`, and SHALL return no Restaurant record.
8. IF a Restaurant list request supplies a latitude outside the closed range -90 to 90, a longitude outside the closed range -180 to 180, a latitude without a longitude or a longitude without a latitude, a search term shorter than 2 or longer than 100 characters, more than 10 cuisine tags, a minimum rating outside the closed range 1.0 to 5.0, a maximum Delivery_Fee below 0 minor currency units, or a page size outside the closed range 1 to 100, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating parameter, and SHALL return no Restaurant.
9. IF a Restaurant list request supplies a next-page cursor that was not issued by the Catalog_Module or that was issued more than 15 minutes earlier, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_cursor`, and SHALL return no Restaurant.
10. WHEN a Restaurant list request is valid and no Restaurant satisfies the supplied coordinate pair, search term, and filters, THE Catalog_Module SHALL respond with HTTP status 200, an empty Restaurant list, a total count of 0, and no next-page cursor.

### Requirement 4: Menu Browsing

**User Story:** As a Customer, I want to browse a Restaurant's Menu with prices and options, so that I can select what to order.

#### Acceptance Criteria

1. WHEN a Customer requests the Menu of a Restaurant whose Restaurant_Status is `published`, THE Catalog_Module SHALL return every Menu_Category of that Restaurant's Menu, at most 50 Menu_Categories, ordered by ascending configured display-order value and, where two Menu_Categories share the same display-order value, by ascending Menu_Category name, each Menu_Category containing its Menu_Items, at most 200 Menu_Items per Menu_Category, ordered by the same two-key rule.
2. THE Catalog_Module SHALL include for every returned Menu_Item the name of 1 to 100 characters, the description of 0 to 2,000 characters, the Price as an integer number of minor currency units together with the Restaurant's ISO 4217 currency code, the availability flag as a boolean, and every attached Modifier_Group, at most 10 Modifier_Groups per Menu_Item, each with its Modifier_Options, at most 50 Modifier_Options per Modifier_Group, and each Modifier_Option's Price_Delta as a signed integer number of minor currency units.
3. THE Catalog_Module SHALL include for every returned Modifier_Group a minimum selection count in the closed range 0 to 50 and a maximum selection count in the closed range 1 to 50, where the minimum selection count is less than or equal to the maximum selection count.
4. IF a returned Menu_Item has the availability flag set to false, THEN THE Catalog_Module SHALL include that Menu_Item in its Menu_Category marked as unavailable, with its name, description, Price, and Modifier_Groups populated as stated in criterion 2, and SHALL NOT omit it from the response.
5. WHEN a Customer requests the Menu of a Restaurant whose Restaurant_Status is `published` and a cached Menu read model exists in the Cache_Store for that Restaurant, THE Catalog_Module SHALL serve the response from that cached Menu read model, with content identical to the content returned from the stored Menu, within 300 milliseconds at the 95th percentile under 100 concurrent requests.
6. WHEN a Restaurant_Operator commits a change to a Menu_Category, a Menu_Item, a Modifier_Group, or a Modifier_Option of a Restaurant's Menu, THE Catalog_Module SHALL invalidate the cached Menu read model for that Restaurant within 5 seconds of the commit, so that every Menu request received after the invalidation returns the changed values.
7. WHEN a Customer requests the Menu of a Restaurant whose Restaurant_Status is `published` and whose Menu holds no Menu_Category, or whose Menu_Category holds no Menu_Item, THE Catalog_Module SHALL return a success status with an empty Menu_Category collection or an empty Menu_Item collection respectively, and SHALL NOT return an API_Error.
8. WHEN a Customer requests the Menu of a Restaurant whose Restaurant_Status is `published` and no cached Menu read model exists in the Cache_Store for that Restaurant, THE Catalog_Module SHALL build the Menu read model from the stored Menu, SHALL store it in the Cache_Store with an expiry of 300 seconds after the write, and SHALL return that Menu read model in the response.
9. IF a Menu request carries a Restaurant identifier that matches no Restaurant whose Restaurant_Status is `published`, THEN THE Catalog_Module SHALL respond with HTTP status 404 and the API_Error code `restaurant_not_found`, and SHALL return no Menu_Category and no Menu_Item.

### Requirement 5: Delivery Zones and Address Serviceability

**User Story:** As a Customer, I want the Platform to confirm that my address is inside the delivery area, so that I do not place an Order that cannot be fulfilled.

#### Acceptance Criteria

1. WHEN an Administrator submits GeoJSON text of at most 1,000,000 characters describing a Delivery_Zone, THE GeoJSON_Parser SHALL produce a Delivery_Zone geometry object containing a closed polygon of at least 4 and at most 1,000 coordinate pairs per linear ring, at most 10 linear rings, whose first and last coordinate pairs of every ring are equal, and whose every coordinate pair has a longitude in the range -180 to 180 inclusive and a latitude in the range -90 to 90 inclusive.
2. IF submitted GeoJSON text violates the GeoJSON polygon grammar, describes a ring whose first and last coordinate pairs differ, contains a ring of fewer than 4 or more than 1,000 coordinate pairs, contains more than 10 linear rings, exceeds 1,000,000 characters, or contains a longitude outside -180 to 180 or a latitude outside -90 to 90, THEN THE GeoJSON_Parser SHALL reject the request with HTTP status 422, the API_Error code `invalid_geojson`, and the zero-based character offset of the first violation, and SHALL leave every stored Delivery_Zone unchanged.
3. THE GeoJSON_Printer SHALL convert any Delivery_Zone geometry object into GeoJSON text that conforms to the GeoJSON polygon grammar, preserving the order of the linear rings and the order of the coordinate pairs within each ring, repeating each ring's first coordinate pair as that ring's last coordinate pair, and rendering every coordinate value in decimal notation with at most 7 fractional digits.
4. FOR ALL Delivery_Zone geometry objects, parsing the output of the GeoJSON_Printer SHALL produce a geometry object equal to the original geometry object, where equality means the same number of linear rings in the same order, the same number of coordinate pairs in the same order within each ring, and coordinate values that are identical when compared at 7 fractional digits of precision (round-trip property).
5. FOR ALL GeoJSON text accepted by the GeoJSON_Parser, printing then parsing then printing SHALL produce text that is character-for-character identical to the first printed output (round-trip property).
6. WHEN a Customer submits a Delivery_Address, THE Zone_Module SHALL resolve the address within 3,000 milliseconds to a latitude in the range -90 to 90 inclusive and a longitude in the range -180 to 180 inclusive, each carrying at most 7 fractional digits, and SHALL return both values to the Account_Module for storage with the Delivery_Address as stated in Requirement 31.
7. IF address resolution returns no match for a submitted Delivery_Address or returns no coordinate pair within 3,000 milliseconds, THEN THE Zone_Module SHALL respond with HTTP status 422 and the API_Error code `address_not_resolvable`, and THE Account_Module SHALL store no Delivery_Address.
8. THE Zone_Module SHALL treat a coordinate pair as inside a Serviceable_Area where the coordinate pair lies within the exterior ring of at least one Delivery_Zone assigned to a Restaurant_Branch of that Restaurant with an active flag of true and outside every interior ring of that Delivery_Zone, and SHALL treat a coordinate pair lying exactly on a ring boundary as inside that Delivery_Zone.
9. IF no Restaurant_Branch with an active flag of true of the Restaurant in the Customer's Cart holds an assigned Delivery_Zone, THEN THE Zone_Module SHALL treat that Restaurant's Serviceable_Area as empty and SHALL respond with HTTP status 422 and the API_Error code `address_not_serviceable`.
10. IF a resolved Delivery_Address lies outside the Serviceable_Area of the Restaurant in the Customer's Cart, THEN THE Zone_Module SHALL respond with HTTP status 422 and the API_Error code `address_not_serviceable`, and SHALL leave the Cart contents unchanged.

### Requirement 6: Cart Management

**User Story:** As a Customer, I want to build and adjust a Cart before checking out, so that I order exactly what I intend to.

#### Acceptance Criteria

1. WHEN a Customer adds a Menu_Item to the Cart, THE Cart_Module SHALL create a Cart_Line holding the Menu_Item identifier, the selected Product_Variant identifier where that Menu_Item holds at least 1 Product_Variant as stated in Requirement 34, a quantity in the closed range 1 to 20, and the selected Modifier_Options.
2. IF a Customer adds a Menu_Item belonging to a Restaurant other than the Restaurant already represented in the Cart, THEN THE Cart_Module SHALL respond with HTTP status 409 and the API_Error code `cart_restaurant_conflict`.
3. IF a Customer submits Modifier_Option selections that violate the minimum or maximum selection count of a Modifier_Group, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_modifier_selection`.
4. IF a Customer adds a Menu_Item whose availability flag is false, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `item_unavailable`.
5. WHEN a Customer changes a Cart_Line quantity to 0, THE Cart_Module SHALL remove that Cart_Line from the Cart.
6. THE Cart_Module SHALL persist each Cart in the Cache_Store with an expiry of 72 hours after the most recent Cart change.
7. WHEN a Customer requests the Cart, THE Cart_Module SHALL return every Cart_Line with its computed line total and the current price breakdown from the Pricing_Engine as stated in Requirement 7 criterion 8.
8. THE Cart_Module SHALL permit at most 50 Cart_Lines per Cart, and IF a Customer adds a Cart_Line to a Cart that already holds 50 Cart_Lines, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `cart_line_limit_reached`, and SHALL create no Cart_Line.
9. IF a Customer submits a Cart_Line quantity that is not an integer, is less than 0, or is greater than 20, THEN THE Cart_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for the quantity, and SHALL leave the Cart contents unchanged.
10. IF a Cart request names a Cart_Line identifier that matches no Cart_Line of the requesting Customer's Cart, THEN THE Cart_Module SHALL respond with HTTP status 404 and the API_Error code `cart_line_not_found`, and IF a Cart request names a Menu_Item identifier that matches no Menu_Item of a Restaurant whose Restaurant_Status is `published`, THEN THE Cart_Module SHALL respond with HTTP status 404 and the API_Error code `menu_item_not_found`, and in both cases SHALL leave the Cart contents unchanged.
11. WHEN a Customer requests the Cart while no Cart is stored in the Cache_Store for that Customer, THE Cart_Module SHALL respond with HTTP status 200, an empty Cart_Line collection, and a Line_Subtotal of 0 minor currency units.

### Requirement 7: Order Pricing

**User Story:** As a Customer, I want a transparent price breakdown before I pay, so that I know what I am charged and why.

#### Acceptance Criteria

1. THE Pricing_Engine SHALL compute Line_Subtotal as the sum over every Cart_Line of the Cart that is not marked as unavailable, for 0 to 50 Cart_Lines, of the Cart_Line quantity multiplied by the sum of that Cart_Line's base Price as stated in Requirement 34 criterion 2 and the Price_Deltas of the selected Modifier_Options, yielding 0 minor currency units for a Cart that holds no available Cart_Line.
2. THE Pricing_Engine SHALL compute Delivery_Fee as the Restaurant's configured base Delivery_Fee plus the Restaurant's configured per-kilometre rate multiplied by the straight-line distance in kilometres, rounded half up to 3 fractional digits and treated as 20.000 kilometres where the measured distance exceeds 20.000 kilometres, between the resolved coordinate pair of the Restaurant_Branch selected as stated in Requirement 32 criterion 9 and the Delivery_Address coordinate pair, then clamped to the closed range from the Restaurant's configured minimum Delivery_Fee to the Restaurant's configured maximum Delivery_Fee.
3. THE Pricing_Engine SHALL compute Order_Total as Line_Subtotal plus Delivery_Fee plus Service_Fee plus Tax_Amount minus Discount_Amount, yielding a value in the closed range 0 to 99,999,999 minor currency units.
4. THE Pricing_Engine SHALL perform every monetary calculation in integer minor currency units and SHALL round every intermediate percentage, rate, or division result half up to the nearest minor currency unit at the step in which it is produced, so that identical inputs always yield identical outputs.
5. WHERE exactly one Promotion code is supplied for the Cart, IF the Promotion is active at the current UTC time and the Cart satisfies every eligibility condition of the Promotion, THEN THE Pricing_Engine SHALL set Discount_Amount to the Promotion discount value computed for that Cart.
6. IF a supplied Promotion code is unknown, is outside its activation window, is ineligible for the Cart, or is supplied together with one or more additional Promotion codes, THEN THE Pricing_Engine SHALL respond with HTTP status 422 and the API_Error code `promotion_not_applicable`, SHALL set Discount_Amount to 0 minor currency units, and SHALL leave the Cart contents unchanged.
7. THE Pricing_Engine SHALL clamp Discount_Amount to the closed range from 0 minor currency units to the sum of Line_Subtotal and Delivery_Fee, applying the clamped value rather than rejecting the Promotion when the Promotion discount value exceeds that sum.
8. WHEN a Customer requests a price breakdown for a Cart, THE Pricing_Engine SHALL return Line_Subtotal, Delivery_Fee, Service_Fee, Tax_Amount, Discount_Amount, and Order_Total, each as an integer number of minor currency units, together with the ISO 4217 currency code configured for the Cart's Restaurant.
9. THE Pricing_Engine SHALL compute Service_Fee as the Platform's configured service-fee percentage, within the closed range 0 to 25 percent, of Line_Subtotal, clamped to the closed range from the Platform's configured minimum Service_Fee to the Platform's configured maximum Service_Fee.
10. THE Pricing_Engine SHALL compute Tax_Amount as the Restaurant's configured tax rate, within the closed range 0 to 30 percent, applied to the sum of Line_Subtotal, Delivery_Fee, and Service_Fee before Discount_Amount is deducted.
11. WHEN a Customer requests a price breakdown for a Cart of up to 50 Cart_Lines, THE Pricing_Engine SHALL return the breakdown within 200 milliseconds at the 95th percentile under 100 concurrent requests.
12. WHERE no Delivery_Address is selected for the Cart, THE Pricing_Engine SHALL compute Delivery_Fee as the Restaurant's configured minimum Delivery_Fee, SHALL compute Service_Fee, Tax_Amount, Discount_Amount, and Order_Total from that Delivery_Fee, and SHALL mark the returned breakdown as provisional.

### Requirement 8: Checkout and Order Placement

**User Story:** As a Customer, I want to place an Order from my Cart, so that the Restaurant begins preparing my food.

#### Acceptance Criteria

1. WHEN a Customer submits a checkout request containing a Cart identifier, a Delivery_Address identifier, and an Idempotency_Key, THE Order_Module SHALL create an Order in Order_State `pending_payment` holding a snapshot of every Cart_Line, every Price, and the full price breakdown.
2. THE Order_Module SHALL assign every Order a unique Order_Number of 10 characters drawn from the uppercase alphanumeric alphabet.
3. WHEN a checkout request repeats an Idempotency_Key already recorded for the same Customer, THE Order_Module SHALL return the previously created Order and its original HTTP status.
4. IF every Restaurant_Branch of the Cart's Restaurant with an active flag of true is closed at the time of checkout according to Requirement 32 criterion 7, THEN THE Order_Module SHALL respond with HTTP status 422 and the API_Error code `restaurant_closed` and SHALL create no Order, and IF at least one such Restaurant_Branch is open and none of the open Restaurant_Branches holds a Serviceable_Area containing the Delivery_Address coordinate pair, THEN THE Order_Module SHALL respond as stated in Requirement 32 criterion 10.
5. IF any Menu_Item in the Cart has an availability flag of false at the time of checkout, THEN THE Order_Module SHALL respond with HTTP status 422, the API_Error code `item_unavailable`, and the identifiers of the affected Menu_Items.
6. WHEN an Order reaches Order_State `confirmed`, THE Cart_Module SHALL delete the Cart that produced the Order.
7. THE Order_Module SHALL preserve the snapshot prices of an Order when the Restaurant later changes the corresponding Menu_Item Prices.
8. IF a checkout request fails at a step that executes after the Order creation transaction of Requirement 40 criterion 10 has committed, THEN THE Order_Module SHALL leave the Order in Order_State `pending_payment` or transition it to Order_State `cancelled` through the Order_State_Machine, and SHALL leave the Order in no other Order_State.
9. IF a generated Order_Number equals the Order_Number of an existing Order, THEN THE Order_Module SHALL generate a further Order_Number and SHALL repeat that generation at most 5 times, and IF all 5 generated Order_Numbers collide, THEN THE Order_Module SHALL respond with HTTP status 500 and the API_Error code `internal_error` and SHALL create no Order.

### Requirement 9: Payment Authorization and Refunds

**User Story:** As a Customer, I want my payment collected reliably, so that my Order is confirmed and I am charged once.

#### Acceptance Criteria

1. WHEN an Order enters Order_State `pending_payment`, THE Payment_Module SHALL create, within 5 seconds, exactly one Payment_Intent at the Payment_Provider for an amount equal to the Order_Total expressed in integer minor currency units and for the Order's ISO 4217 currency code, and SHALL record the returned Payment_Intent identifier on the Order.
2. WHILE the associated Order is in Order_State `pending_payment`, WHEN the Payment_Provider reports a successful authorization for that Order's Payment_Intent, THE Payment_Module SHALL transition the Order to Order_State `confirmed` within 5 seconds of receiving the report.
3. IF the Payment_Provider reports a declined authorization, THEN THE Payment_Module SHALL transition the associated Order to Order_State `cancelled`, record the Payment_Provider-supplied decline reason on the Order_Timeline, and create no further Payment_Intent for that Order.
4. THE Payment_Module SHALL send one Idempotency_Key derived from the Order identifier, unchanged across the initial Payment_Provider authorization request for that Order and across every retry of that request.
5. WHEN the Payment_Module receives a Payment_Provider webhook, THE Payment_Module SHALL verify the webhook signature and SHALL apply no Order_State change, no capture, and no Refund unless the verification succeeds.
6. IF a received webhook carries an invalid signature, THEN THE Payment_Module SHALL respond with HTTP status 400, leave the Order_State and the Order_Timeline unchanged, and record the rejection in the Observability_Stack.
7. WHEN the Payment_Module receives a Payment_Provider webhook whose event identifier matches an event identifier processed within the preceding 7 days, THE Payment_Module SHALL acknowledge the webhook without applying a second Order_State change, a second capture, a second Refund, or an additional Order_Timeline entry.
8. WHEN an Order that holds a captured Payment_Intent transitions to Order_State `cancelled` or `rejected`, THE Payment_Module SHALL create, within 60 seconds, exactly one Refund for an amount equal to the captured amount in integer minor currency units and SHALL transition the Order to Order_State `refunded`.
9. IF a Payment_Provider request receives no response within 10 seconds or fails with a connection error, THEN THE Payment_Module SHALL retry the request at most 3 times with waits of 1, 2, and 4 seconds between attempts and SHALL leave the Order in Order_State `pending_payment` for the duration of the retry sequence.
10. WHEN the Payment_Module transitions an Order to Order_State `confirmed`, THE Payment_Module SHALL capture the authorized Payment_Intent for the full authorized amount within 60 seconds and record the capture on the Order_Timeline.
11. IF all 3 retries of a Payment_Provider authorization request fail, THEN THE Payment_Module SHALL transition the associated Order to Order_State `cancelled`, record a payment-unreachable reason on the Order_Timeline, and record the failure in the Observability_Stack.
12. IF the Payment_Provider rejects a Refund request, THEN THE Payment_Module SHALL leave the Order in Order_State `cancelled` or `rejected`, record the Payment_Provider-supplied failure reason on the Order_Timeline, and record the failure in the Observability_Stack for Administrator follow-up.
13. THE Payment_Module SHALL reach every Payment_Provider exclusively through the Payment_Gateway interface, SHALL treat every reference to the Payment_Provider in criteria 1 through 12 and criterion 14 as a reference to the bound Payment_Gateway adapter, which in the initial implementation phase is the Simulated_Payment_Gateway, and SHALL satisfy every criterion of this requirement for whichever Payment_Gateway adapter is bound, as stated in Requirement 36.
14. WHEN an Order that holds an authorized but uncaptured Payment_Intent transitions to Order_State `cancelled` or `rejected`, THE Payment_Module SHALL void that authorization within 60 seconds, SHALL create no Refund, SHALL record the void on the Order_Timeline, and SHALL transition the Order to Order_State `refunded`.

### Requirement 10: Order Lifecycle

**User Story:** As a Platform user, I want Order progress recorded as an auditable sequence of states, so that every party sees consistent status.

#### Acceptance Criteria

1. THE Order_State_Machine SHALL permit only the following 18 Order_State transitions: `pending_payment` to `confirmed`; `pending_payment` to `cancelled`; `confirmed` to `accepted`; `confirmed` to `rejected`; `confirmed` to `cancelled`; `accepted` to `preparing`; `accepted` to `cancelled`; `preparing` to `ready_for_pickup`; `preparing` to `cancelled`; `ready_for_pickup` to `assigned_to_rider`; `ready_for_pickup` to `cancelled`; `assigned_to_rider` to `picked_up`; `assigned_to_rider` to `ready_for_pickup`; `assigned_to_rider` to `cancelled`; `picked_up` to `out_for_delivery`; `out_for_delivery` to `delivered`; `cancelled` to `refunded`; `rejected` to `refunded`.
2. THE Order_Module SHALL change an Order_State only through the Order_State_Machine, and SHALL expose no Delivery_API endpoint that writes a client-supplied Order_State value without evaluating both the permitted transition set of criterion 1 and the actor permissions of criterion 3.
3. THE Order_State_Machine SHALL permit each transition only to the following actors: `pending_payment` to `confirmed`, `pending_payment` to `cancelled`, `cancelled` to `refunded`, and `rejected` to `refunded` only to the Payment_Module; `confirmed` to `accepted`, `confirmed` to `rejected`, `accepted` to `preparing`, and `preparing` to `ready_for_pickup` only to a Restaurant_Operator of the fulfilling Restaurant, a Restaurant_Staff of the fulfilling Restaurant_Branch, or an Administrator; `ready_for_pickup` to `assigned_to_rider` and `assigned_to_rider` to `ready_for_pickup` only to the Dispatch_Module; `assigned_to_rider` to `picked_up`, `picked_up` to `out_for_delivery`, and `out_for_delivery` to `delivered` only to the Courier holding the Order's active Delivery_Job or an Administrator; `confirmed` to `cancelled` only to the Customer who placed the Order, a Restaurant_Operator of the fulfilling Restaurant, a Restaurant_Staff of the fulfilling Restaurant_Branch, or an Administrator; `accepted` to `cancelled`, `preparing` to `cancelled`, `ready_for_pickup` to `cancelled`, and `assigned_to_rider` to `cancelled` only to a Restaurant_Operator of the fulfilling Restaurant or an Administrator.
4. IF a requested Order_State transition is absent from the permitted transition set of criterion 1, THEN THE Order_Module SHALL respond with HTTP status 409 and the API_Error code `invalid_state_transition`, SHALL leave the Order_State and the Order_Timeline unchanged, and SHALL publish no Realtime_Event and no Notification.
5. IF a requested Order_State transition is present in the permitted transition set of criterion 1 but the requesting actor is not permitted that transition by criterion 3, THEN THE Order_Module SHALL respond with HTTP status 403 and the API_Error code `forbidden`, SHALL leave the Order_State and the Order_Timeline unchanged, and SHALL publish no Realtime_Event and no Notification.
6. WHEN an Order_State transition succeeds, THE Order_Module SHALL append an Order_Timeline entry recording the previous Order_State, the new Order_State, the acting Platform user identifier or the acting module name, and the UTC timestamp.
7. THE Order_Module SHALL treat Order_States `delivered` and `refunded` as terminal and SHALL reject every transition request for Orders in those states with HTTP status 409 and the API_Error code `invalid_state_transition`.
8. WHILE an Order is in Order_State `cancelled` or `rejected`, THE Order_Module SHALL permit only the transition to Order_State `refunded`.
9. WHEN a Customer requests cancellation of an Order in Order_State `confirmed`, THE Order_Module SHALL transition the Order to Order_State `cancelled`.
10. IF a Customer requests cancellation of an Order in Order_State `accepted`, `preparing`, `ready_for_pickup`, `assigned_to_rider`, `picked_up`, or `out_for_delivery`, THEN THE Order_Module SHALL respond with HTTP status 409 and the API_Error code `cancellation_window_closed`.
11. WHEN a Restaurant_Operator or an Administrator cancels an Order in Order_State `accepted`, `preparing`, `ready_for_pickup`, or `assigned_to_rider`, THE Order_Module SHALL transition the Order to Order_State `cancelled` and record the supplied cancellation reason on the Order_Timeline.
12. WHEN a Delivery_Job is assigned to a Courier and the associated Order is in Order_State `ready_for_pickup`, THE Order_Module SHALL transition the Order to Order_State `assigned_to_rider` within 2 seconds of the assignment being committed.
13. WHERE a Delivery_Job was assigned to a Courier while the associated Order was in Order_State `accepted` or `preparing`, WHEN that Order reaches Order_State `ready_for_pickup`, THE Order_Module SHALL transition the Order to Order_State `assigned_to_rider` within 2 seconds of reaching Order_State `ready_for_pickup`.
14. WHEN an assignment of a Delivery_Job is withdrawn while the associated Order is in Order_State `assigned_to_rider`, THE Order_Module SHALL transition the Order to Order_State `ready_for_pickup` within 2 seconds of the withdrawal being committed.
15. WHEN an Order_State transition succeeds, THE Order_Module SHALL publish exactly one domain event named `order.state_changed` carrying the Order identifier, the previous Order_State, the new Order_State, the acting identity, and the UTC timestamp, and THE Realtime_Gateway SHALL broadcast that event as a Realtime_Event named `order.state_changed` on the Order's Private_Channel, the fulfilling Restaurant_Branch's Private_Channel, and, where a Delivery_Job is assigned, the assigned Courier's Private_Channel.
16. WHEN an Order_State transition succeeds, THE Notification_Module SHALL send the Notifications that Requirement 16 assigns to the new Order_State, and SHALL send no Notification for an Order_State transition that Requirement 16 does not list.

### Requirement 11: Restaurant Menu Management

**User Story:** As a Restaurant_Operator, I want to manage my Menu and availability, so that Customers see accurate offerings.

#### Acceptance Criteria

1. WHEN a Restaurant_Operator creates or updates a Menu_Category, THE Catalog_Module SHALL persist the category name and display order for the Restaurant associated with that Restaurant_Operator.
2. WHEN a Restaurant_Operator creates or updates a Menu_Item, THE Catalog_Module SHALL persist the name, description, Price, availability flag, Menu_Category assignment, and Modifier_Groups.
3. IF a submitted Menu_Item Price or Product_Variant Price is less than 1 minor currency unit or greater than 99,999,999 minor currency units, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_price`, and SHALL leave the stored Menu_Item and the stored Product_Variant unchanged.
4. IF a submitted Modifier_Group has a minimum selection count greater than its maximum selection count, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_modifier_group`.
5. WHEN a Restaurant_Operator sets a Menu_Item availability flag to false, THE Catalog_Module SHALL exclude that Menu_Item from new Cart_Lines while retaining it in existing Orders.
6. WHEN a Restaurant_Operator deletes a Menu_Item, THE Catalog_Module SHALL mark the Menu_Item as archived and retain the record for Order history integrity.
7. WHEN a Restaurant_Operator updates Opening_Hours, THE Catalog_Module SHALL store the schedule as at most 3 non-overlapping intervals per day of week under the same interval rules that Requirement 32 criterion 5 states for Branch_Hours, expressed in the Restaurant's time zone, and SHALL replace the complete stored weekly schedule with the submitted intervals.
8. IF submitted Opening_Hours violate an interval rule of criterion 7, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `invalid_hours`, and one per-field validation detail for each violating interval, and SHALL leave the stored Opening_Hours unchanged, applying no interval of the submitted schedule.
9. IF a submitted Menu_Category name is shorter than 1 or longer than 100 characters, a submitted Menu_Item name is shorter than 1 or longer than 100 characters, a submitted Menu_Item description is longer than 2,000 characters, or a submitted display-order value is outside the closed range 0 to 9,999, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL persist no change.
10. THE Catalog_Module SHALL permit at most 50 Menu_Categories per Menu, at most 200 Menu_Items per Menu_Category, at most 10 Modifier_Groups per Menu_Item, and at most 50 Modifier_Options per Modifier_Group, and IF a create request would exceed one of those limits, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `catalog_limit_reached`, and SHALL create no record.

### Requirement 12: Restaurant Order Handling

**User Story:** As a Restaurant_Operator or Restaurant_Staff, I want to accept, prepare, and hand off incoming Orders, so that Customers receive their food.

#### Acceptance Criteria

1. WHEN an Order reaches Order_State `confirmed`, THE Realtime_Gateway SHALL deliver an `order.created` Realtime_Event on the Private_Channel of the fulfilling Restaurant_Branch within 2 seconds, with the subscriber restrictions stated in Requirement 33 criterion 7.
2. WHEN a Restaurant_Operator of the fulfilling Restaurant or a Restaurant_Staff of the fulfilling Restaurant_Branch accepts an Order, THE Order_Module SHALL transition the Order to Order_State `accepted` and SHALL record the supplied preparation time in whole minutes in the closed range 1 to 240.
3. WHEN a Restaurant_Operator of the fulfilling Restaurant or a Restaurant_Staff of the fulfilling Restaurant_Branch rejects an Order, THE Order_Module SHALL transition the Order to Order_State `rejected` and SHALL record a rejection reason selected from the Order rejection reason list held in Platform_Configuration.
4. WHEN a Restaurant_Operator of the fulfilling Restaurant or a Restaurant_Staff of the fulfilling Restaurant_Branch marks an accepted Order as in preparation, THE Order_Module SHALL transition the Order to Order_State `preparing`.
5. WHEN a Restaurant_Operator of the fulfilling Restaurant or a Restaurant_Staff of the fulfilling Restaurant_Branch marks an Order as ready, THE Order_Module SHALL transition the Order to Order_State `ready_for_pickup`.
6. WHILE an Order remains in Order_State `confirmed`, WHEN 10 minutes have elapsed since the transition into that Order_State, THE Notification_Module SHALL send a Notification of Notification_Category `order_status` to the Restaurant_Operator of the fulfilling Restaurant and to every Restaurant_Staff of the fulfilling Restaurant_Branch, and SHALL repeat that Notification at 5-minute intervals until the Order leaves Order_State `confirmed` or 6 such Notifications have been sent for that Order, whichever occurs first.
7. WHEN a Restaurant_Operator requests the active Order list, THE Order_Module SHALL return the Orders of that Restaurant in Order_States `confirmed`, `accepted`, `preparing`, `ready_for_pickup`, and `assigned_to_rider`, ordered by creation time ascending.
8. WHEN a Restaurant_Staff requests the active Order list, THE Order_Module SHALL return only the Orders assigned to the one Restaurant_Branch associated with that Restaurant_Staff in Order_States `confirmed`, `accepted`, `preparing`, `ready_for_pickup`, and `assigned_to_rider`, ordered by creation time ascending, and SHALL exclude the Orders of every other Restaurant_Branch from the results and from the returned total count.

### Requirement 13: Courier Dispatch

**User Story:** As a Courier, I want to receive nearby Delivery_Jobs, so that I can earn by delivering Orders.

#### Acceptance Criteria

1. WHEN an Order reaches Order_State `accepted` and no Delivery_Job exists for that Order identifier, THE Dispatch_Module SHALL create within 5 seconds exactly one Delivery_Job holding the Order identifier, the resolved coordinate pair of the fulfilling Restaurant_Branch as Pickup_Location, the Delivery_Address coordinate pair as Drop_Off_Location, and the UTC creation timestamp.
2. WHEN a Delivery_Job is created, THE Dispatch_Module SHALL offer the Delivery_Job to at most the 10 nearest Available_Couriers whose most recent Courier_Location lies within the initial Offer_Radius held in Platform_Configuration, defaulting to 10 kilometres straight-line distance, of the Pickup_Location, ordered by ascending straight-line distance.
3. WHEN the Dispatch_Module records the first accept for an offered Delivery_Job from an Available_Courier, THE Dispatch_Module SHALL assign the Delivery_Job to that Courier and withdraw the offer from every other Courier within 2 seconds of the assignment.
4. IF a Courier accepts a Delivery_Job that is already assigned to another Courier or whose offer to that Courier has been withdrawn, THEN THE Dispatch_Module SHALL respond with HTTP status 409 and the API_Error code `job_already_assigned` and SHALL leave the existing assignment unchanged.
5. IF no Courier accepts a Delivery_Job within 120 seconds of the most recent offer, THEN THE Dispatch_Module SHALL widen the Offer_Radius to the maximum Offer_Radius held in Platform_Configuration, defaulting to 20 kilometres straight-line distance, SHALL widen the Offer_Radius no further, and SHALL repeat the offer at 120-second intervals to the Available_Couriers within the current Offer_Radius until the Delivery_Job is assigned or escalated.
6. IF no Courier accepts a Delivery_Job within 600 seconds of its creation timestamp, THEN THE Dispatch_Module SHALL withdraw every outstanding offer, SHALL issue no further offer, SHALL mark the Delivery_Job as escalated in the Administrator_Queue, SHALL retain the associated Order in its current Order_State, and SHALL send the Administrator a Notification within 60 seconds of the escalation.
7. THE Dispatch_Module SHALL assign at most one active Delivery_Job to a Courier at any time.
8. THE Dispatch_Module SHALL classify a Courier as an Available_Courier only where that Courier's Rider_Status is `online`, that Courier's Account_Status is `active`, that Courier's most recent Courier_Location carries a UTC timestamp not older than 5 minutes, and that Courier holds no active Delivery_Job, and SHALL classify every other Courier as unavailable.
9. WHILE a Delivery_Job is assigned to a Courier, holds no recorded Delivery_Job_Outcome, and the associated Order is in an Order_State other than `delivered`, `cancelled`, `rejected`, or `refunded`, THE Dispatch_Module SHALL treat that Delivery_Job as active.
10. IF no Available_Courier lies within the current Offer_Radius of the Pickup_Location at the moment an offer is issued, THEN THE Dispatch_Module SHALL retain the Delivery_Job unassigned and SHALL re-evaluate Courier availability at 30-second intervals until the Delivery_Job is assigned or escalated.
11. IF a Courier that holds an active Delivery_Job accepts an offered Delivery_Job, THEN THE Dispatch_Module SHALL respond with HTTP status 409 and the API_Error code `courier_has_active_job` and SHALL leave that Courier's active Delivery_Job unchanged.

### Requirement 14: Courier Delivery Execution

**User Story:** As a Courier, I want to report pickup, position, and delivery, so that the Customer can follow the Order and I can close the job.

#### Acceptance Criteria

1. WHEN the Courier holding an active Delivery_Job marks that Delivery_Job as picked up while the associated Order is in Order_State `assigned_to_rider`, THE Order_Module SHALL transition the Order to Order_State `picked_up`.
2. WHEN the Courier holding an active Delivery_Job marks that Delivery_Job as started while the associated Order is in Order_State `picked_up`, THE Order_Module SHALL transition the Order to Order_State `out_for_delivery`.
3. WHILE a Delivery_Job is assigned and the associated Order is in Order_State `assigned_to_rider`, `picked_up`, or `out_for_delivery`, THE Tracking_Module SHALL accept a Location_Report from the assigned Courier where at least 10 seconds have elapsed since that Courier's most recently accepted Location_Report, and IF a Location_Report is submitted less than 10 seconds after that Courier's most recently accepted Location_Report, THEN THE Tracking_Module SHALL respond with HTTP status 429 and the API_Error code `location_report_rate_exceeded` and SHALL store no Courier_Location.
4. IF a submitted Location_Report violates a field rule of Requirement 43 criterion 4, including a latitude outside the closed range -90 to 90 or a longitude outside the closed range -180 to 180, THEN THE Tracking_Module SHALL respond with HTTP status 422, the API_Error code `invalid_location_report`, and one per-field validation detail for each violating field as stated in Requirement 43 criterion 5, and SHALL store no Courier_Location.
5. WHEN the Tracking_Module records a Courier_Location for a Courier holding an active Delivery_Job, THE Tracking_Module SHALL publish a Realtime_Event named `courier.location_updated` on the Order's Private_Channel.
6. WHEN the Courier holding an active Delivery_Job marks that Delivery_Job as delivered while the associated Order is in Order_State `out_for_delivery`, THE Order_Module SHALL transition the Order to Order_State `delivered` and THE Dispatch_Module SHALL close the Delivery_Job with the Delivery_Job_Outcome `delivered`.
7. THE Tracking_Module SHALL retain Courier_Location records for 30 days after Delivery_Job closure and SHALL delete every Courier_Location record older than 30 days.
8. IF a Courier reports a delivery failure with a reason selected from the Delivery_Job failure reason list held in Platform_Configuration, THEN THE Dispatch_Module SHALL close the Delivery_Job with the Delivery_Job_Outcome `failed`, SHALL mark the Delivery_Job as escalated in the Administrator_Queue, SHALL retain the Order in its current Order_State, and SHALL send the Administrator a Notification within 60 seconds of the escalation.

### Requirement 15: Realtime Order Tracking

**User Story:** As a Customer, I want live Order status and Courier position, so that I know when my food arrives.

#### Acceptance Criteria

1. THE Realtime_Gateway SHALL expose one Private_Channel per Order, one per Restaurant, one per Restaurant_Branch, and one per Courier.
2. WHEN a client requests a Private_Channel subscription, THE Auth_Module SHALL authorize the subscription using the same Role and ownership checks that govern the corresponding Delivery_API resource.
3. IF a Private_Channel subscription request fails authorization, THEN THE Realtime_Gateway SHALL refuse the subscription and return the API_Error code `channel_forbidden`.
4. WHEN the Delivery_API publishes a Realtime_Event, THE Realtime_Gateway SHALL deliver the event to every authorized subscriber within 2 seconds at the 95th percentile.
5. WHEN a Web_Client WebSocket connection drops, THE Web_Client SHALL reconnect with exponential backoff starting at 1 second and capped at 30 seconds.
6. WHEN a Web_Client reconnects, THE Web_Client SHALL fetch the current Order state from the Delivery_API so that the displayed state matches the stored state.
7. THE Realtime_Gateway SHALL treat every Realtime_Event as advisory and THE Delivery_API SHALL remain the authoritative source of Order state.

### Requirement 16: Notifications

**User Story:** As a Platform user, I want to be notified of the events that concern me, so that I can act without watching the screen.

#### Acceptance Criteria

1. WHEN an Order transitions to Order_State `confirmed`, `accepted`, `rejected`, `ready_for_pickup`, `assigned_to_rider`, `picked_up`, `out_for_delivery`, `delivered`, `cancelled`, or `refunded`, THE Notification_Module SHALL send the Customer who placed the Order a Notification naming the new Order_State.
2. THE Notification_Module SHALL dispatch every Notification through a queued Job so that the originating Delivery_API request completes independently of Notification delivery.
3. IF a Notification delivery attempt fails, THEN THE Notification_Module SHALL retry the delivery at most 3 times with waits of 1, 2, and 4 seconds between attempts and SHALL record the final outcome as delivered or failed.
4. WHERE a Platform user has disabled a Notification_Category for a channel, THE Notification_Module SHALL omit every Notification of that Notification_Category on that channel for that Platform user.
5. THE Notification_Module SHALL record for every Notification the recipient identifier, the Notification_Category, the channel, and the UTC dispatch timestamp.
6. THE Notification_Module SHALL support exactly the two channels `email` and `in_app`, SHALL deliver an `email` Notification through the configured mail transport, and SHALL persist an `in_app` Notification as a record retrievable by its recipient through the Delivery_API.
7. WHEN an Order transitions to Order_State `confirmed` or `cancelled`, THE Notification_Module SHALL send a Notification to the Restaurant_Operator of the fulfilling Restaurant and to every Restaurant_Staff of the fulfilling Restaurant_Branch.
8. WHEN a Delivery_Job is offered to a Courier, assigned to a Courier, or withdrawn from a Courier, THE Notification_Module SHALL send that Courier a Notification naming the Delivery_Job identifier and the event.
9. WHEN a Platform user requests the in-application Notification list, THE Notification_Module SHALL return that Platform user's `in_app` Notifications in pages of 20 ordered by UTC dispatch timestamp descending, each carrying a read or unread flag, and SHALL exclude every Notification addressed to another Platform user.
10. WHEN a Platform user marks an `in_app` Notification as read, THE Notification_Module SHALL set that Notification's read flag to true and SHALL leave the Notification content unchanged.
11. THE Notification_Module SHALL assign every Notification exactly one Notification_Category from the set {`order_status`, `delivery_status`, `payment`, `promotion`, `account`}, and SHALL send a Notification only on the channels that the recipient's Notification preferences leave enabled for that Notification_Category.
12. WHEN a Platform user is created, THE Account_Module SHALL set that Platform user's Notification preferences for each Notification_Category and each of the channels `email` and `in_app` to the Notification_Category defaults held in Platform_Configuration.

### Requirement 17: Ratings and Reviews

**User Story:** As a Customer, I want to rate a completed Order, so that other Customers can judge Restaurant quality.

#### Acceptance Criteria

1. WHEN a Customer submits a Review for an Order in Order_State `delivered` that the Customer placed, THE Review_Module SHALL store a rating between 1 and 5 with optional text of at most 1,000 characters.
2. IF a Customer submits a Review for an Order that is not in Order_State `delivered`, THEN THE Review_Module SHALL respond with HTTP status 422 and the API_Error code `order_not_reviewable`.
3. IF a Customer submits a second Review for the same Order, THEN THE Review_Module SHALL respond with HTTP status 409 and the API_Error code `review_already_exists`.
4. WHEN a Review is stored or hidden, THE Review_Module SHALL recompute the Restaurant's Aggregate_Rating as the arithmetic mean of its Customer-visible Review ratings rounded half up to 1 decimal place.
5. WHEN a Customer requests a Restaurant's Reviews, THE Review_Module SHALL return them in pages of 20, ordered by creation time descending.
6. WHEN an Administrator hides a Review, THE Review_Module SHALL exclude that Review from Customer-facing responses and from the Aggregate_Rating.
7. IF a submitted Review carries a rating that is not an integer in the closed range 1 to 5, or text longer than 1,000 characters, THEN THE Review_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL store no Review.
8. WHERE a Restaurant holds no Customer-visible Review, THE Review_Module SHALL report that Restaurant's Aggregate_Rating as absent, and THE Catalog_Module SHALL exclude that Restaurant from Restaurant list results where a minimum rating filter is supplied as stated in Requirement 3 criterion 3.

### Requirement 18: Administration

**User Story:** As an Administrator, I want to oversee accounts, Restaurants, and Orders, so that I can keep the marketplace operating correctly.

#### Acceptance Criteria

1. WHEN an Administrator approves a Restaurant application, THE Catalog_Module SHALL set that Restaurant's Restaurant_Status to `published` under the conditions stated in Requirement 59, and SHALL make the Restaurant visible in Customer-facing Restaurant list results as stated in Requirement 3 criterion 1.
2. WHEN an Administrator suspends a Restaurant, THE Catalog_Module SHALL set that Restaurant's Restaurant_Status to `suspended`, SHALL exclude that Restaurant from Customer-facing Restaurant list results, and SHALL reject every checkout request naming that Restaurant with HTTP status 409 and the API_Error code `restaurant_suspended`.
3. WHEN an Administrator suspends a Platform user account, THE Auth_Module SHALL set that Platform user's Account_Status to `suspended`, SHALL invalidate that Platform user's Access_Tokens and Refresh_Tokens within 5 seconds of the change being committed, and SHALL reject that Platform user's subsequent sign-in attempts with HTTP status 403 and the API_Error code `account_suspended`.
4. WHEN an Administrator creates a Promotion whose activation UTC timestamp is earlier than its expiry UTC timestamp and whose discount value lies in the closed range 1 to 99,999,999 minor currency units or in the closed range 1 to 100 percent, THE Pricing_Engine SHALL apply that Promotion from its activation timestamp until its expiry timestamp, and IF a submitted activation timestamp is later than or equal to its expiry timestamp or a submitted discount value lies outside its stated range, THEN THE Admin_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL create no Promotion.
5. WHEN an Administrator requests the Order list, THE Order_Module SHALL return Orders filtered by any combination of Order_State, Restaurant, Courier, and creation-date range.
6. WHEN an Administrator performs a write action, THE Delivery_API SHALL record an Audit_Entry holding the Administrator identifier, the action name, the affected record identifier, and the UTC timestamp.
7. WHEN an Administrator requests platform metrics for a date range, THE Delivery_API SHALL return Order counts by Order_State, gross Order_Total sum, and mean delivery duration for that range.
8. THE Admin_Module SHALL provide the management surfaces stated in Requirement 37 in addition to the actions of this requirement.

### Requirement 19: Order History

**User Story:** As a Customer, I want to review my past Orders, so that I can reorder and check what I was charged.

#### Acceptance Criteria

1. WHEN a Customer requests the Order history, THE Order_Module SHALL return that Customer's Orders in pages of 20, ordered by creation time descending.
2. WHEN a Customer requests a single past Order, THE Order_Module SHALL return the snapshot lines, the full price breakdown, the Delivery_Address, and the Order_Timeline.
3. WHEN a Customer requests a reorder from a past Order, THE Cart_Module SHALL create a Cart containing one Cart_Line per Order_Line of that Order whose referenced Menu_Item has an availability flag of true and, where that Order_Line named a Product_Variant, whose referenced Product_Variant has an availability flag of true, and SHALL list every omitted Order_Line in the response.
4. THE Order_Module SHALL retain every Order record for at least 7 years after creation.

### Requirement 20: REST API Contract

**User Story:** As a client developer, I want a stable, documented, versioned REST contract, so that I can build against the Platform without reading its source code.

#### Acceptance Criteria

1. THE Delivery_API SHALL expose every endpoint under a version prefix of the form `/api/v{major}` and SHALL keep the response schema of a published major version backward compatible.
2. THE Delivery_API SHALL accept and return `application/json` request and response bodies for every endpoint except file uploads.
3. THE Delivery_API SHALL return HTTP status 200 for successful reads, 201 for successful creations, 204 for successful deletions, 401 for unauthenticated requests, 403 for unauthorized requests, 404 for missing records, 409 for state conflicts, 422 for validation failures, and 429 for rate-limit rejections.
4. WHEN a request fails validation, THE Delivery_API SHALL return an API_Error containing a stable error code, a human-readable message, and one entry per rejected field naming the field and the rule violated.
5. THE Delivery_API SHALL publish an OpenAPI_Document that describes every endpoint, request schema, response schema, and error code.
6. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL verify that the OpenAPI_Document matches the implemented routes and SHALL fail on any mismatch.
7. FOR ALL Delivery_API resource objects, deserializing the serialized representation SHALL produce a resource object equal to the original resource object (round-trip property).
8. THE Delivery_API SHALL return every timestamp as an ISO 8601 string in UTC with second precision or finer.
9. THE Delivery_API SHALL return every monetary value as an integer number of minor currency units accompanied by an ISO 4217 currency code.
10. THE Delivery_API SHALL return cursor-paginated collections containing a data array, a total count, and a next-page cursor that is null on the final page.
11. THE Delivery_API SHALL expose every endpoint of the initial implementation phase under the version prefix `/api/v1`.
12. THE Delivery_API SHALL expose at least the routes `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/restaurants`, `GET /api/v1/restaurants/{restaurant}`, `GET /api/v1/restaurants/{restaurant}/menu`, `GET /api/v1/cart`, `POST /api/v1/cart/items`, `PATCH /api/v1/cart/items/{item}`, `DELETE /api/v1/cart/items/{item}`, `POST /api/v1/orders`, `GET /api/v1/orders`, `GET /api/v1/orders/{order}`, `GET /api/v1/deliveries/{delivery}`, and `POST /api/v1/rider/location`.
13. THE Delivery_API SHALL serve every documented endpoint identically to the Web_Client, to a native mobile client, and to a third-party client presenting a valid Access_Token, and SHALL hold no behavior conditioned on the calling client type.
14. THE OpenAPI_Document SHALL describe for every endpoint the path, the HTTP method, the authentication requirement, the request schema, the validation rules, the response schema, the error codes, the HTTP status codes, the authorization rule, and at least one request example and one response example.
15. THE Delivery_API SHALL return every paginated collection stated in this document in the envelope of criterion 10, applying a default page size of 20 items where this document states pages of 20 and accepting a supplied page size in the closed range 1 to 100.

### Requirement 21: Caching and Asynchronous Processing

**User Story:** As a developer, I want expensive reads cached and slow work queued, so that the Platform stays responsive under load.

#### Acceptance Criteria

1. THE Delivery_API SHALL use the Cache_Store for Restaurant search read models, Menu read models, Cart state, and rate-limit counters.
2. THE Delivery_API SHALL apply a time-to-live of 300 seconds to every cached Restaurant search read model and every cached Menu read model.
3. WHEN a write changes a record that a cached read model derives from, THE Delivery_API SHALL invalidate the affected cache entries within 5 seconds of commit.
4. IF the Cache_Store is unreachable, THEN THE Delivery_API SHALL serve the request from PostgreSQL and record the cache failure in the Observability_Stack.
5. THE Delivery_API SHALL dispatch Notification delivery, Payment_Provider webhook processing, report generation, and Courier offer fan-out as queued Jobs.
6. THE Queue_Worker SHALL retry a failed Job at most 3 times with waits of 1, 2, and 4 seconds between attempts and SHALL move the Job to a dead-letter queue after the final failure.
7. THE Queue_Worker SHALL process every queued Job idempotently so that a repeated execution of the same Job produces the same stored state as a single execution.
8. WHEN a Job enters the dead-letter queue, THE Observability_Stack SHALL record an error-level entry containing the Job name and the failure reason.

### Requirement 22: Modular Monolith Architecture

**User Story:** As a developer, I want enforced module boundaries, so that the codebase can later split into services without a rewrite.

#### Acceptance Criteria

1. THE Delivery_API SHALL organize domain code into exactly the 14 modules Auth, Account, Catalog, Zone, Cart, Pricing, Order, Payment, Dispatch, Rider, Tracking, Notification, Review, and Admin, and SHALL place no domain code outside those modules.
2. THE Delivery_API SHALL restrict cross-module interaction to a Module_Boundary interface or a domain event published by the owning module.
3. THE Delivery_API SHALL give each module exclusive write access to the database tables that the module owns.
4. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL execute a static architecture check that fails on any import crossing a Module_Boundary outside the permitted interfaces.
5. THE Delivery_API SHALL keep framework-specific code out of domain classes so that domain classes depend only on language primitives and other domain classes.
6. THE Delivery_API SHALL define each external dependency behind an interface owned by the consuming module, with the concrete adapter bound through the service container.
7. THE Delivery_API SHALL structure the code inside every module according to the Backend_Layer_Chain stated in Requirement 38.
8. IF a module requires data owned by another module, THEN THE Delivery_API SHALL obtain that data through the owning module's Module_Boundary interface or through a domain event published by the owning module, and SHALL issue no query against a table owned by another module.

### Requirement 23: Test-Driven Development and Automated Tests

**User Story:** As a developer, I want every behavior covered by tests written before the implementation, so that I can change the Platform with confidence.

#### Acceptance Criteria

1. THE Platform SHALL include, for every acceptance criterion in this document, at least one automated test that names that criterion identifier, fails while the corresponding implementation is absent, and passes once the implementation is present.
2. THE Platform SHALL include unit tests covering, for Pricing_Engine, each of Line_Subtotal, Delivery_Fee, Service_Fee, Tax_Amount, Discount_Amount, and Order_Total; for the Order_State_Machine, each of the 18 permitted transitions of Requirement 10 criterion 1, at least one rejected transition per Order_State, and at least one rejected actor per transition listed in Requirement 10 criterion 3; and for Zone_Module, a point inside a Delivery_Zone, a point outside a Delivery_Zone, and a point on a Delivery_Zone boundary.
3. THE Platform SHALL include, for each Delivery_API endpoint, feature tests that exercise the endpoint through the HTTP layer against a PostgreSQL database reset to a known fixture state before each test, with at least one test asserting the documented success response and at least one test per API_Error code documented for that endpoint.
4. THE Platform SHALL include property-based tests for the GeoJSON round-trip properties, the Delivery_API serialization round-trip property, Pricing_Engine monetary invariants, Order_State transition validity, and Queue_Worker Job idempotence, executing each property against at least 100 generated inputs per run and failing where any generated input violates the property.
5. THE Platform SHALL include, for each of Payment_Provider interaction, Realtime_Gateway broadcasting, and AWS-hosted infrastructure behavior, 1 to 3 integration tests that each assert one observable outcome at the Platform boundary and that run in a suite selectable separately from the unit, feature, property-based, and frontend suites.
6. IF measured line coverage of the Delivery_API application code, or measured line coverage of the Web_Client application code, is below 100 percent of the lines that are not covered by a documented exclusion, THEN THE Build_Pipeline SHALL fail the run and report the measured coverage and every uncovered line by file and line number. (Supersedes the earlier 80 percent aggregate and 70 percent per-module thresholds; see Requirement 48.)
7. THE Platform SHALL include frontend tests that assert rendered Web_Client output for adding, changing the quantity of, and removing a Cart_Line; for completing the checkout flow through payment confirmation; for updating the displayed Order_State on receipt of a Realtime_Event; and for rendering an API_Error with its per-field validation details.
8. THE Platform SHALL isolate every unit test, feature test, property-based test, and frontend test from external network calls by using test doubles for the Payment_Provider and the geocoding service, and SHALL fail any such test that attempts an outbound call to either dependency with an error indicating a forbidden external call.
9. THE Platform SHALL run the unit, feature, property-based, integration, and frontend suites to completion within 10 minutes of wall-clock time on the Build_Pipeline runner.
10. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL perform the acceptance-criterion-to-test mapping verification stated in Requirement 48 criterion 10.
11. IF the automated test suite has not completed within 10 minutes on the Build_Pipeline runner, THEN THE Build_Pipeline SHALL terminate the suite and report a failed status indicating the test-suite timeout.
12. IF a property-based test fails, THEN THE Platform SHALL report the generator seed and the shrunk failing input for that property.

### Requirement 24: Containerized Local Development Environment

**User Story:** As a developer, I want the whole Platform to start with one command, so that I can develop without installing services by hand.

#### Acceptance Criteria

1. THE Local_Dev_Environment SHALL define exactly the six Docker Compose services `frontend` running the Vite development server, `backend` running the Delivery_API, `postgres` running PostgreSQL, `redis` running Redis, `queue-worker` running the Queue_Worker, and `reverb` running the Realtime_Gateway.
2. WHEN a developer runs the documented single start command in a clean checkout, THE Local_Dev_Environment SHALL reach a state where the Delivery_API health endpoint returns HTTP status 200 within 5 minutes.
3. THE Local_Dev_Environment SHALL mount the application source directory into the application container so that source changes take effect without an image rebuild.
4. THE Local_Dev_Environment SHALL persist PostgreSQL data and Redis data in named Docker volumes, and SHALL store no data that this document requires to survive in a container filesystem layer alone.
5. WHEN the `postgres` container is removed and recreated from its named volume, THE Local_Dev_Environment SHALL present every previously committed database row unchanged.
6. THE Local_Dev_Environment SHALL read every environment-specific value from an environment file and SHALL provide a committed example environment file listing every required variable with a non-secret placeholder value, as stated in Requirement 44.
7. THE Local_Dev_Environment SHALL provide a documented command that runs database migrations and seeds demonstration data covering at least 3 Restaurants, 5 Restaurant_Branches, 20 Menu_Items, 5 Product_Variants, and 1 Platform user per Role.
8. THE Local_Dev_Environment SHALL provide a documented command that runs the complete test suite inside the application container.
9. IF a required environment variable is absent at application boot, THEN THE Delivery_API SHALL terminate startup and write a message naming the absent variable.
10. THE Local_Dev_Environment SHALL provide a documented command that emits simulated Courier Location_Reports as stated in Requirement 43 criterion 7.

### Requirement 25: Continuous Integration and Delivery

**User Story:** As a developer, I want every change validated and releases automated, so that broken code stays out of production.

#### Acceptance Criteria

1. WHEN a commit is pushed to any branch or a pull request is opened, THE Build_Pipeline SHALL run static analysis, code style checks, the backend test suite, the frontend test suite, and the frontend production build.
2. IF any Build_Pipeline step fails, THEN THE Build_Pipeline SHALL report a failed status on the commit and block the pull request from merging.
3. THE Build_Pipeline SHALL complete a full run within 15 minutes, under the timeout behavior stated in Requirement 47 criterion 10.
4. THE Build_Pipeline SHALL run the backend test suite against PostgreSQL and Redis service containers at the same major versions used in production, as stated in Requirement 47 criterion 9.
5. WHEN the Build_Pipeline succeeds on the `main` branch, THE Build_Pipeline SHALL build a container image and tag it with the commit SHA.
6. WHEN a commit is merged to the `main` branch and the Build_Pipeline succeeds on that commit, THE Deploy_Pipeline SHALL deploy the image built from that commit to the production environment through the stage sequence stated in Requirement 47 criterion 4. (Supersedes the earlier release-tag trigger; `main` is the single deployment trigger.)
7. THE Deploy_Pipeline SHALL run database migrations before routing traffic to a newly deployed release.
8. IF a post-deployment health check fails, THEN THE Deploy_Pipeline SHALL restore the previously deployed release and report a failed deployment status.
9. THE Build_Pipeline and the Deploy_Pipeline SHALL read every credential from the repository secret store, SHALL obtain AWS credentials through OIDC_Trust as stated in Requirement 47, and SHALL write no credential value into a log.
10. THE Build_Pipeline SHALL run the job set stated in Requirement 47 criterion 2, and THE Deploy_Pipeline SHALL run the stage sequence stated in Requirement 47 criterion 4.

### Requirement 26: Production Deployment on AWS

**User Story:** As an operator, I want the Platform to run on managed AWS infrastructure, so that it stays available and scalable.

#### Acceptance Criteria

1. THE Platform SHALL run the Delivery_API, the Queue_Worker, and the Realtime_Gateway as separately scalable container workloads on AWS.
2. THE Platform SHALL use a managed PostgreSQL instance with automated daily backups retained for at least 7 days and point-in-time recovery enabled.
3. THE Platform SHALL use a managed Redis instance for the Cache_Store and the queue backend.
4. THE Platform SHALL serve the Web_Client as static assets through a content delivery network.
5. THE Platform SHALL terminate every external connection with TLS 1.2 or higher and SHALL redirect plain HTTP requests to HTTPS.
6. THE Platform SHALL store every production secret in a managed secret store and SHALL inject secrets into containers at runtime.
7. WHEN sustained Delivery_API CPU utilization exceeds 70 percent for 5 minutes, THE Platform SHALL add Delivery_API container instances up to the configured maximum instance count, and WHEN sustained Delivery_API CPU utilization falls below 30 percent for 10 minutes, THE Platform SHALL remove Delivery_API container instances down to the configured minimum instance count.
8. THE Platform SHALL provision every AWS resource of the initial implementation phase through the AWS Console, the AWS CLI, or the Deploy_Pipeline, and SHALL record each provisioned resource in the Documentation_Set as stated in Requirement 45, rather than defining it in a declarative infrastructure-as-code tool. (Supersedes the earlier infrastructure-as-code mandate for the initial implementation phase; see Requirement 45.)
9. THE Platform SHALL place PostgreSQL and Redis in private subnets reachable only from the application workloads.

### Requirement 27: Observability

**User Story:** As an operator, I want logs, metrics, and health signals, so that I can detect and diagnose failures.

#### Acceptance Criteria

1. THE Delivery_API SHALL expose a health endpoint that returns HTTP status 200 with the status of the PostgreSQL connection, the Cache_Store connection, and the queue connection within 1 second.
2. IF any checked dependency is unreachable, THEN THE health endpoint SHALL return HTTP status 503 and name the unreachable dependency.
3. THE Delivery_API SHALL emit every log entry as a single-line JSON object containing a UTC timestamp, a severity level, a message, the request Correlation_Identifier, and the acting Platform user identifier where a user is authenticated.
4. WHEN the Delivery_API receives a request without a Correlation_Identifier header, THE Delivery_API SHALL generate a Correlation_Identifier and SHALL return it in the response headers.
5. THE Delivery_API SHALL propagate the request Correlation_Identifier into every queued Job and every Realtime_Event published by that request.
6. THE Observability_Stack SHALL record request rate, error rate, and response duration percentiles per endpoint, and queue depth and Job failure count per queue.
7. THE Delivery_API SHALL exclude passwords, Access_Tokens, Refresh_Tokens, and payment instrument data from every log entry.
8. WHEN the 5-minute error rate of the Delivery_API exceeds 2 percent of requests, THE Observability_Stack SHALL raise an alert to the configured operator channel.
9. WHEN an unhandled exception occurs, THE Delivery_API SHALL return an API_Error with the code `internal_error` and record the stack trace in the Observability_Stack without including the stack trace in the response body.

### Requirement 28: Security and Data Protection

**User Story:** As a Platform user, I want my data and payments protected, so that I can use the Platform safely.

#### Acceptance Criteria

1. THE Delivery_API SHALL validate every request body, query parameter, and path parameter against a declared schema before executing business logic.
2. THE Delivery_API SHALL access PostgreSQL exclusively through parameterized statements.
3. THE Delivery_API SHALL limit each authenticated Platform user to 60 requests per minute per endpoint group and each unauthenticated client to 20 requests per minute on authentication endpoints, SHALL respond with HTTP status 429 and a `Retry-After` header when a limit is exceeded, and SHALL apply the failed sign-in attempt limit of Requirement 1 criterion 7 independently of those per-minute limits.
4. THE Delivery_API SHALL restrict cross-origin requests to the configured Web_Client origins.
5. WHERE a live Payment_Provider is bound to the Payment_Gateway, THE Platform SHALL collect card data through Payment_Provider-hosted input elements so that the Delivery_API receives only Payment_Provider tokens, and THE Delivery_API SHALL store no primary account number, card verification value, or card expiry value in any circumstance.
6. THE Delivery_API SHALL encrypt stored Delivery_Address values and Platform user contact details at rest.
7. WHEN a Platform user requests account deletion, THE Delivery_API SHALL anonymize that user's personal data within 30 days while retaining Order records in anonymized form.
8. THE Build_Pipeline SHALL scan application dependencies for known vulnerabilities and SHALL fail on any finding of high or critical severity.
9. THE Delivery_API SHALL set the response headers `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, and `Content-Security-Policy` on every Web_Client-facing response.

### Requirement 29: Web Client Application

**User Story:** As a Customer, I want a responsive and accessible web application, so that I can order on any device.

#### Acceptance Criteria

1. THE Web_Client SHALL be built with React, TypeScript in strict mode, and Vite, and SHALL produce a production bundle through a single documented build command.
2. THE Web_Client SHALL obtain every piece of domain data from the Delivery_API and SHALL hold no business rule that the Delivery_API does not also enforce.
3. THE Web_Client SHALL generate its Delivery_API request and response types from the OpenAPI_Document so that a contract change produces a TypeScript compilation error.
4. WHEN a Delivery_API request returns an API_Error, THE Web_Client SHALL display the error message and, for validation failures, mark each rejected field with the corresponding rule message.
5. WHILE a Delivery_API request is in flight, THE Web_Client SHALL display a loading indicator for the affected region and SHALL keep the submit control disabled until the request settles.
6. WHEN an Order is active, THE Web_Client SHALL display the current Order_State and, while the Order is in Order_State `assigned_to_rider`, `picked_up`, or `out_for_delivery`, the most recent Courier_Location on a map.
7. THE Web_Client SHALL render usable layouts at viewport widths of 360 pixels and above.
8. THE Web_Client SHALL conform to WCAG 2.1 Level AA for keyboard operability, focus visibility, text alternatives, and colour contrast on the registration, browse, Cart, checkout, and tracking screens.
9. WHEN an Access_Token expires during a session, THE Web_Client SHALL obtain a new Access_Token with the stored Refresh_Token and retry the failed request once.
10. IF the Refresh_Token exchange fails, THEN THE Web_Client SHALL clear the stored credentials and present the sign-in screen.

### Requirement 30: Explanatory Documentation Deliverable

**User Story:** As the developer learning from this project, I want the reasoning behind every part of the Platform written down, so that I understand the whole application rather than only its code.

#### Acceptance Criteria

1. THE Documentation_Set SHALL live in the repository as versioned Markdown, SHALL follow the Docs_Tree defined in Requirement 55, and SHALL be updated in the same change that alters the behavior it describes.
2. THE Documentation_Set SHALL contain an architecture overview describing the modular monolith structure, every Module_Boundary, and the request path from Web_Client to PostgreSQL.
3. THE Documentation_Set SHALL record for every mandated technology, namely Laravel, PostgreSQL, Redis, Laravel Reverb, Docker Compose, AWS, and GitHub Actions, the role it plays, the alternatives considered, and the reason for the choice.
4. THE Documentation_Set SHALL record every design pattern applied in the codebase with the module that applies it and the problem it solves.
5. THE Documentation_Set SHALL list every developer command for environment startup, migration, seeding, testing, static analysis, and build, each with its expected output.
6. THE Documentation_Set SHALL document every environment variable with its purpose, value format, and whether the variable holds a secret.
7. THE Documentation_Set SHALL contain a decision record per significant architectural decision, holding the context, the options, the decision, and the consequences.
8. THE Documentation_Set SHALL contain a walkthrough of one complete Order from Cart creation to delivery, naming each module, database table, queued Job, and Realtime_Event involved.
9. THE Documentation_Set SHALL explain the Test-Driven Development cycle used, with one worked example that shows the failing test, the implementation, and the refactor step.
10. THE Documentation_Set SHALL contain a deployment guide describing the AWS topology, the Deploy_Pipeline stages, and the rollback procedure.
11. THE Platform SHALL treat source code alone as an incomplete deliverable, and SHALL accompany every delivered increment with an explanation of the architecture involved, the technologies used, the commands run, the configuration applied, the decisions taken, and the implementation produced.
12. WHEN a technology, command, configuration value, or architectural concept is introduced for the first time, THE Platform SHALL explain in the Documentation_Set what it does, why it is used here, and what the alternative would have been.

### Requirement 31: Customer Profile and Saved Delivery Addresses

**User Story:** As a Customer, I want to maintain my profile and several saved addresses, so that I can check out to the right place without retyping details.

#### Acceptance Criteria

1. WHEN a Customer requests the own profile, THE Account_Module SHALL return the display name, the current email address, the optional phone number, the Notification preferences as one enabled-or-disabled flag per Notification category for each of the channels `email` and `in_app`, the identifier of the default Delivery_Address where one exists, and the account creation UTC timestamp, and SHALL exclude the stored password hash and every email address change confirmation token from the response.
2. WHEN a Customer submits a profile update containing a display name of 1 to 100 characters, an optional phone number of 5 to 20 characters drawn from digits, spaces, hyphens, and a leading plus sign, or a Notification preference change enabling or disabling one Notification category for the `email` channel or the `in_app` channel, THE Account_Module SHALL persist the submitted values, SHALL remove the stored phone number where the phone number is submitted as an empty value, SHALL leave every unsubmitted field unchanged, and SHALL leave the stored email address and the stored password hash unchanged.
3. IF a profile update submits a display name shorter than 1 or longer than 100 characters, a phone number outside 5 to 20 characters or containing a character outside the permitted set, a Notification category or channel outside the defined sets, or an email address longer than 254 characters or not in `local@domain` form, or IF a Delivery_Address create or update submits a label shorter than 1 or longer than 50 characters, an address line shorter than 1 or longer than 200 characters, a second address line longer than 200 characters, a locality shorter than 1 or longer than 100 characters, a postal code shorter than 1 or longer than 20 characters, or a country code that is not a valid ISO 3166-1 alpha-2 code, THEN THE Account_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL leave the stored profile and every stored Delivery_Address unchanged.
4. WHEN a Customer submits an email address change containing an email address of at most 254 characters in `local@domain` form that belongs to no other Platform user compared case-insensitively, THE Account_Module SHALL issue exactly one confirmation token for that Customer that expires 24 hours after issuance, SHALL invalidate every confirmation token previously issued to that Customer for an email address change, SHALL leave the stored email address set to the previous email address until that confirmation token is accepted, and SHALL continue to accept sign-in with the previous email address until that confirmation token is accepted.
5. WHEN a Customer submits a password change containing the current password and a new password of 12 to 128 characters, THE Auth_Module SHALL store the new password as a one-way hash, SHALL invalidate every Refresh_Token of that Customer except the Refresh_Token presented with the request, and SHALL leave the stored email address, display name, phone number, and Notification preferences unchanged.
6. IF a password change submits a current password that does not match the stored password hash, or a new password shorter than 12 or longer than 128 characters, THEN THE Auth_Module SHALL respond with HTTP status 422, with the API_Error code `current_password_mismatch` where the submitted current password does not match the stored password hash and with the API_Error code `validation_failed` and one per-field validation detail for the new password where the new password is outside 12 to 128 characters, and SHALL leave the stored password hash unchanged and SHALL invalidate no Refresh_Token.
7. WHEN a Customer creates a Delivery_Address containing a label of 1 to 50 characters, an address line of 1 to 200 characters, an optional second address line of at most 200 characters, a locality of 1 to 100 characters, a postal code of 1 to 20 characters, and a country code that is a valid ISO 3166-1 alpha-2 code, THE Account_Module SHALL store it against that Customer with the coordinate pair resolved by the Zone_Module as stated in Requirement 5, SHALL set the default flag of the created Delivery_Address to true where that Customer holds no other non-archived Delivery_Address and to false otherwise, and SHALL permit a Customer to hold at most 20 non-archived Delivery_Addresses.
8. IF a Customer creates a Delivery_Address while already holding 20 non-archived Delivery_Addresses, THEN THE Account_Module SHALL respond with HTTP status 422 and the API_Error code `address_limit_reached`, and SHALL create no Delivery_Address, counting archived Delivery_Addresses toward neither the limit of 20 nor the returned count.
9. WHEN a Customer marks a non-archived Delivery_Address of that Customer as the default, THE Account_Module SHALL set that Delivery_Address as the single default of that Customer within one committed operation, SHALL clear the default flag of every other Delivery_Address of that Customer, and SHALL leave every Delivery_Address unchanged where the targeted Delivery_Address already holds the default flag.
10. WHEN a Customer deletes a non-archived Delivery_Address of that Customer, THE Account_Module SHALL mark the Delivery_Address as archived whether or not it is referenced by an Order, SHALL exclude it from the Customer's Delivery_Address list, SHALL retain the address snapshot held on every referencing Order unchanged, SHALL clear the default flag where the archived Delivery_Address held it and leave that Customer with no default Delivery_Address until the Customer marks another Delivery_Address as the default, and SHALL make no further change where the targeted Delivery_Address is already archived.
11. WHEN a Customer requests the own Delivery_Address list, THE Account_Module SHALL return only that Customer's non-archived Delivery_Addresses, ordered with the default Delivery_Address first and the remaining Delivery_Addresses by ascending label and then by ascending Delivery_Address identifier, and IF a request to retrieve, update, mark as default, or delete a Delivery_Address names a Delivery_Address identifier that matches no non-archived Delivery_Address owned by the requesting Platform user, THEN THE Account_Module SHALL respond with HTTP status 404 and the API_Error code `address_not_found`, SHALL include no Delivery_Address field values in the response, SHALL leave all Platform data unchanged, and SHALL return the same HTTP status and API_Error code whether or not the Delivery_Address exists so that the response does not disclose the existence of another Platform user's Delivery_Address.
12. WHEN a Customer submits an email address change confirmation token that is recorded for that Customer, has not been used, and has not reached its 24-hour expiry, THE Account_Module SHALL replace the stored email address with the email address recorded against that confirmation token, SHALL invalidate that confirmation token, and SHALL leave the stored password hash, display name, phone number, and Notification preferences unchanged.
13. IF a submitted email address change confirmation token is not recorded for the requesting Customer, has already been used, or has reached its 24-hour expiry, THEN THE Account_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_confirmation_token`, and SHALL leave the stored email address set to the previous email address.
14. IF an email address change submits an email address that already belongs to another Platform user, compared case-insensitively, THEN THE Account_Module SHALL respond with HTTP status 422 and the API_Error code `email_already_registered`, SHALL issue no confirmation token, and SHALL leave the stored email address and the other Platform user's record unchanged.

### Requirement 32: Restaurant Profile, Branches, and Hours

**User Story:** As a Restaurant_Operator, I want to manage my restaurant profile and each of my branches with their own hours, so that Customers order from the outlet that can actually serve them.

#### Acceptance Criteria

1. WHEN a Restaurant_Operator submits a Restaurant profile update containing a name of 1 to 150 characters, a description of 0 to 2,000 characters, at most 10 cuisine tags each of 1 to 50 characters, and a time zone that is a valid IANA time zone identifier, THE Catalog_Module SHALL persist the submitted values for the one Restaurant associated with that Restaurant_Operator, SHALL leave every unsubmitted field unchanged, and SHALL evaluate that Restaurant's Opening_Hours against the stored time zone for every subsequent open-or-closed computation.
2. WHEN a Restaurant_Operator creates a Restaurant_Branch containing a name of 1 to 150 characters, a postal address, and an optional time zone that is a valid IANA time zone identifier, THE Catalog_Module SHALL store the Restaurant_Branch against that Restaurant_Operator's Restaurant with the coordinate pair resolved by the Zone_Module as stated in Requirement 5, SHALL set the Restaurant_Branch active flag to true, SHALL store no Branch_Hours interval for that Restaurant_Branch, and WHERE the optional time zone is absent SHALL apply the Restaurant's time zone to that Restaurant_Branch.
3. THE Catalog_Module SHALL require every Restaurant whose Restaurant_Status is `published` to hold at least 1 Restaurant_Branch with an active flag of true, and SHALL permit at most 50 Restaurant_Branches per Restaurant, counting Restaurant_Branches with an active flag of false toward that limit.
4. IF a Restaurant_Operator sets the active flag of the only Restaurant_Branch with an active flag of true of a Restaurant whose Restaurant_Status is `published` to false, THEN THE Catalog_Module SHALL respond with HTTP status 409 and the API_Error code `last_active_branch` and SHALL leave the Restaurant_Branch unchanged.
5. WHEN a Restaurant_Operator submits Branch_Hours for a Restaurant_Branch as at most 3 non-overlapping intervals per day of week, each day of week being one of the 7 values `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`, and each interval carrying a start time and an end time expressed as minutes from local midnight in the closed range 0 to 1,440 where the start time is less than the end time, THE Catalog_Module SHALL replace the complete stored weekly schedule of that Restaurant_Branch with the submitted intervals, and SHALL treat a day of week carrying no submitted interval as closed for the whole of that local day.
6. IF submitted Branch_Hours contain two overlapping intervals for the same day of week, more than 3 intervals for one day of week, a day of week outside the 7 permitted values, a start time greater than or equal to its end time, or a time outside the closed range 0 to 1,440, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `invalid_hours`, and one per-field validation detail for each violating interval, and SHALL leave the stored Branch_Hours unchanged, applying no interval of the submitted schedule.
7. WHERE a Restaurant_Branch holds at least 1 Branch_Hours interval, THE Catalog_Module SHALL compute that Restaurant_Branch's open-or-closed status from its Branch_Hours, and WHERE a Restaurant_Branch holds no Branch_Hours interval, THE Catalog_Module SHALL compute that status from the Restaurant's Opening_Hours, in both cases evaluated at the current UTC instant converted into the applicable time zone, inclusive of the interval start instant and exclusive of the interval end instant, and reporting closed where no applicable interval contains that local instant, including where no interval is defined for that local day.
8. WHEN a Customer requests the Restaurant list, THE Catalog_Module SHALL treat a Restaurant as serviceable for a supplied coordinate pair where at least 1 Restaurant_Branch of that Restaurant has an active flag of true and holds a Serviceable_Area containing that coordinate pair, and SHALL report that Restaurant's open-or-closed status as stated in Requirement 3 criterion 5 rather than excluding a closed Restaurant from the results.
9. WHEN an Order is created, THE Order_Module SHALL assign to that Order exactly one fulfilling Restaurant_Branch, selected among the Restaurant_Branches of the Cart's Restaurant that at the instant of Order creation have an active flag of true, are open according to criterion 7, and hold a Serviceable_Area containing the Delivery_Address coordinate pair, as the candidate at the shortest straight-line distance between its resolved coordinate pair and the Delivery_Address coordinate pair, resolving an exact distance tie by ascending Restaurant_Branch identifier.
10. IF no Restaurant_Branch of the Cart's Restaurant is active, open, and servicing the Delivery_Address at the time of checkout, THEN THE Order_Module SHALL respond with HTTP status 422 and the API_Error code `no_branch_available`, and SHALL create no Order.
11. WHEN a Restaurant_Operator assigns at most 20 Delivery_Zones of that Restaurant_Operator's Restaurant to a Restaurant_Branch, THE Zone_Module SHALL compute that Restaurant_Branch's Serviceable_Area as the union of the assigned Delivery_Zones, SHALL treat that Restaurant_Branch's Serviceable_Area as empty where 0 Delivery_Zones are assigned, and SHALL compute the Restaurant's Serviceable_Area as the union of the Serviceable_Areas of its Restaurant_Branches with an active flag of true.
12. IF a Restaurant profile update submits a name shorter than 1 or longer than 150 characters, a description longer than 2,000 characters, more than 10 cuisine tags, a cuisine tag shorter than 1 or longer than 50 characters, or a time zone that is not a valid IANA time zone identifier, or IF a Restaurant_Branch creation or update submits a name shorter than 1 or longer than 150 characters or a time zone that is not a valid IANA time zone identifier, or IF a Delivery_Zone assignment submits more than 20 Delivery_Zones or a Delivery_Zone identifier that matches no Delivery_Zone of that Restaurant_Operator's Restaurant, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL leave the stored Restaurant profile, Restaurant_Branch, and Serviceable_Area unchanged.
13. IF a Restaurant_Operator creates a Restaurant_Branch while that Restaurant_Operator's Restaurant already holds 50 Restaurant_Branches, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `branch_limit_reached`, and SHALL create no Restaurant_Branch.
14. IF the Zone_Module returns no match or no coordinate pair within 3,000 milliseconds for a postal address submitted with a Restaurant_Branch creation or update, THEN THE Catalog_Module SHALL respond with HTTP status 422 and the API_Error code `address_not_resolvable`, and SHALL create no Restaurant_Branch and SHALL leave an existing Restaurant_Branch unchanged.

### Requirement 33: Two-Level Restaurant Roles

**User Story:** As a Restaurant_Operator, I want branch staff able to work incoming Orders without being able to change my restaurant, prices, or accounts, so that day-to-day operation does not risk my business configuration.

#### Acceptance Criteria

1. WHEN a Restaurant_Operator submits a Restaurant_Staff account creation request naming a Restaurant_Branch of that Restaurant_Operator's Restaurant and containing an email address of at most 254 characters in `local@domain` form, a password of at least 12 and at most 128 characters, and a display name of at least 1 and at most 100 characters, THE Auth_Module SHALL create exactly one Platform user with the Role `restaurant_staff` associated with exactly that one Restaurant_Branch, SHALL set that Platform user's Account_Status to `active`, SHALL return the created user identifier, SHALL exclude the submitted password from the response, and SHALL permit at most 20 Restaurant_Staff accounts whose Account_Status is `active` per Restaurant_Branch.
2. IF a Restaurant_Operator creates, reassigns, or deactivates a Restaurant_Staff account for a Restaurant_Branch that belongs to another Restaurant or that matches no Restaurant_Branch, THEN THE Auth_Module SHALL respond with HTTP status 403 and the API_Error code `forbidden`, SHALL create or change no Platform user, SHALL include no Restaurant_Branch field values in the response, and SHALL return the same HTTP status and API_Error code in both cases so that the response does not disclose whether the Restaurant_Branch exists.
3. THE Auth_Module SHALL permit a Restaurant_Operator to accept, reject, and progress Orders of that Restaurant_Operator's Restaurant, to set Menu_Item and Product_Variant availability, to manage the Restaurant profile, Restaurant_Branches, Opening_Hours, Branch_Hours, Menu_Categories, Menu_Items, Product_Variants, Modifier_Groups, Modifier_Options, Prices, and Restaurant_Staff accounts of that Restaurant.
4. THE Auth_Module SHALL permit a Restaurant_Staff to perform, only for the Orders assigned to the one Restaurant_Branch associated with that Restaurant_Staff, the Order_State transitions that Requirement 10 criterion 3 permits to a Restaurant_Staff of the fulfilling Restaurant_Branch, and SHALL permit a Restaurant_Staff to set the availability flag of the Menu_Items and Product_Variants of that Restaurant_Branch's Restaurant.
5. IF a Restaurant_Staff requests creation, modification, or deletion of a Restaurant profile field, a Restaurant_Branch, Opening_Hours, Branch_Hours, a Menu_Category, a Menu_Item other than its availability flag, a Product_Variant other than its availability flag, a Modifier_Group, a Modifier_Option, a Price, a Promotion, or a Platform user account, THEN THE Delivery_API SHALL respond with HTTP status 403 and the API_Error code `forbidden`, SHALL include no field values of the target resource in the response, and SHALL leave all Platform data unchanged.
6. WHEN a Restaurant_Operator sets the Account_Status of a Restaurant_Staff account of that Restaurant_Operator's Restaurant to `suspended`, THE Auth_Module SHALL invalidate that Platform user's Access_Tokens and Refresh_Tokens within 5 seconds of the change being committed, SHALL reject that Platform user's subsequent sign-in attempts with HTTP status 403 and the API_Error code `account_suspended`, SHALL leave every Order assigned to that Platform user's Restaurant_Branch unchanged, and SHALL leave every Order_Timeline entry recorded by that Platform user unchanged.
7. WHEN an Order reaches Order_State `confirmed`, THE Realtime_Gateway SHALL deliver the `order.created` Realtime_Event on the Private_Channel of the fulfilling Restaurant_Branch within 2 seconds of the transition being committed, SHALL deliver that event to no Private_Channel of another Restaurant_Branch, and SHALL deliver that event to no subscriber other than the Restaurant_Operator of the fulfilling Restaurant, a Restaurant_Staff associated with the fulfilling Restaurant_Branch, and an Administrator.
8. IF a Restaurant_Staff account creation request omits the email address, the password, the display name, or the Restaurant_Branch identifier, or contains an email address longer than 254 characters or not in `local@domain` form, a password shorter than 12 or longer than 128 characters, or a display name shorter than 1 or longer than 100 characters, THEN THE Auth_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL create no Platform user.
9. IF a Restaurant_Staff account creation request contains an email address that already belongs to a Platform user, compared case-insensitively, THEN THE Auth_Module SHALL respond with HTTP status 422 and the API_Error code `email_already_registered`, and SHALL create no Platform user and SHALL leave the existing Platform user unchanged.
10. IF a Restaurant_Staff requests an Order_State transition for an Order that is not assigned to the one Restaurant_Branch associated with that Restaurant_Staff, THEN THE Delivery_API SHALL respond with HTTP status 404 and the API_Error code `order_not_found`, SHALL leave the Order_State and the Order_Timeline unchanged, and SHALL publish no Realtime_Event and no Notification.
11. IF a Restaurant_Staff account creation request names a Restaurant_Branch that already holds 20 Restaurant_Staff accounts whose Account_Status is `active`, THEN THE Auth_Module SHALL respond with HTTP status 409 and the API_Error code `staff_limit_reached`, and SHALL create no Platform user.

### Requirement 34: Product Variants, Modifiers, and Availability Control

**User Story:** As a Restaurant_Operator, I want to offer sized variants and optional add-ons and to switch items off the moment they run out, so that Customers only order what the kitchen can produce.

#### Acceptance Criteria

1. WHEN a Restaurant_Operator creates or updates a Product_Variant for a Menu_Item, THE Catalog_Module SHALL persist a name of 1 to 100 characters, a Price of at least 1 minor currency unit, an availability flag, and a display-order value, and SHALL permit at most 20 Product_Variants per Menu_Item.
2. WHERE a Menu_Item holds at least 1 Product_Variant, THE Catalog_Module SHALL treat the selected Product_Variant Price as that Cart_Line's base Price in place of the Menu_Item Price, and WHERE a Menu_Item holds no Product_Variant, THE Catalog_Module SHALL treat the Menu_Item Price as that Cart_Line's base Price.
3. IF a Customer adds a Menu_Item that holds at least 1 Product_Variant without selecting exactly one Product_Variant of that Menu_Item, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `variant_selection_required`, and SHALL create no Cart_Line.
4. IF a Customer selects a Product_Variant that does not belong to the submitted Menu_Item, or selects more than 1 Product_Variant, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `invalid_variant_selection`, and SHALL create no Cart_Line.
5. IF a Customer adds a Menu_Item whose selected Product_Variant has an availability flag of false, THEN THE Cart_Module SHALL respond with HTTP status 422 and the API_Error code `item_unavailable`, and SHALL create no Cart_Line.
6. THE Catalog_Module SHALL treat Modifier_Groups and Modifier_Options as the single mechanism for optional extras and add-ons, and SHALL expose no separate option mechanism in the Delivery_API.
7. WHEN a Restaurant_Operator or a Restaurant_Staff sets the availability flag of a Menu_Item or a Product_Variant, THE Catalog_Module SHALL persist the submitted value, SHALL invalidate the cached Menu read model for that Restaurant within 5 seconds of the commit, and SHALL leave every existing Order unchanged.
8. WHEN a Restaurant_Operator or a Restaurant_Staff sets the availability flag of a Menu_Item or a Product_Variant to false, THE Catalog_Module SHALL publish a domain event named `menu_item.availability_changed` carrying the Menu_Item identifier, the optional Product_Variant identifier, the new availability flag, and the UTC timestamp.
9. WHEN a Customer requests a Cart that holds a Cart_Line referencing a Menu_Item or a Product_Variant whose availability flag is false, THE Cart_Module SHALL return that Cart_Line marked as unavailable together with the Order_Total computed from the available Cart_Lines only.
10. THE Order_Module SHALL store on every Order_Line a snapshot of the Menu_Item name, the selected Product_Variant name where one was selected, the base Price, and the selected Modifier_Option names and Price_Deltas, and SHALL leave that snapshot unchanged when the Catalog_Module later changes those records.

### Requirement 35: Courier Availability and Delivery Workflow

**User Story:** As a Courier, I want to go online, see my assigned deliveries, and move each one through pickup to completion, so that I can work and see what I have delivered.

#### Acceptance Criteria

1. THE Rider_Module SHALL hold for every Courier exactly one Rider_Status from the set {`offline`, `online`, `on_delivery`}, and SHALL set Rider_Status to `offline` when the Courier account is created.
2. WHEN a Courier sets the own Rider_Status to `online` while holding no active Delivery_Job, THE Rider_Module SHALL set that Courier's Rider_Status to `online` and THE Tracking_Module SHALL open a Tracking_Session for that Courier.
3. WHEN a Delivery_Job is assigned to a Courier, THE Rider_Module SHALL set that Courier's Rider_Status to `on_delivery`, and WHEN that Delivery_Job is closed or its assignment is withdrawn, THE Rider_Module SHALL set that Courier's Rider_Status to `online`.
4. WHEN a Courier sets the own Rider_Status to `offline` while holding no active Delivery_Job, THE Rider_Module SHALL set that Courier's Rider_Status to `offline`, THE Tracking_Module SHALL close that Courier's Tracking_Session, and THE Dispatch_Module SHALL issue no further Delivery_Job offer to that Courier.
5. IF a Courier holding an active Delivery_Job sets the own Rider_Status to `offline`, THEN THE Rider_Module SHALL respond with HTTP status 409 and the API_Error code `active_delivery_in_progress`, and SHALL leave that Courier's Rider_Status unchanged.
6. WHEN a Courier requests the own assigned Delivery_Job list, THE Rider_Module SHALL return every Delivery_Job assigned to that Courier whose associated Order is in Order_State `assigned_to_rider`, `picked_up`, or `out_for_delivery`, each carrying the Order_Number, the Pickup_Location, the Drop_Off_Location, the Order_Line snapshot, and the current Order_State, and SHALL exclude every Delivery_Job assigned to another Courier.
7. WHEN a Courier requests the own Delivery_Job history, THE Rider_Module SHALL return that Courier's closed Delivery_Jobs in pages of 20 ordered by closure UTC timestamp descending, each carrying the Order_Number, the Delivery_Job_Outcome, and the elapsed duration from assignment to closure in whole seconds.
8. WHEN a Courier accepts an offered Delivery_Job, THE Dispatch_Module SHALL apply the assignment rules of Requirement 13 and THE Order_Module SHALL apply the Order_State transitions of Requirement 10 criteria 12 and 13.
9. IF a Courier requests a pickup, start, delivered, or failure action for a Delivery_Job that is not assigned to that Courier, THEN THE Dispatch_Module SHALL respond with HTTP status 404 and the API_Error code `delivery_not_found`, and SHALL leave the Delivery_Job and the associated Order unchanged.
10. IF a Courier requests a pickup, start, or delivered action while the associated Order is in an Order_State from which Requirement 10 criterion 1 does not permit the corresponding transition, THEN THE Order_Module SHALL respond with HTTP status 409 and the API_Error code `invalid_state_transition`, and SHALL leave the Order_State unchanged.
11. WHEN a Courier requests the own delivery summary for a date range of at most 90 days, THE Rider_Module SHALL return the count of that Courier's Delivery_Jobs closed with the Delivery_Job_Outcome `delivered`, the count closed with the Delivery_Job_Outcome `failed`, and the sum of the elapsed durations in whole seconds for that range.

### Requirement 36: Payment Provider Abstraction

**User Story:** As a developer, I want payment behavior behind one interface with a local adapter, so that I can build and test the whole order flow without integrating a live payment provider.

#### Acceptance Criteria

1. THE Payment_Module SHALL expose the Payment_Gateway interface with the operations authorize, capture, refund, and verify webhook signature, each accepting and returning Payment_Module-owned types only.
2. THE Delivery_API SHALL bind exactly one Payment_Gateway adapter through the service container at boot, selected from the value of a single environment variable, and SHALL terminate startup with a message naming that variable where the value matches no registered adapter.
3. THE Delivery_API SHALL bind the Simulated_Payment_Gateway as the Payment_Gateway adapter in the initial implementation phase, and SHALL integrate no live Payment_Provider in that phase.
4. THE Simulated_Payment_Gateway SHALL resolve every authorize, capture, refund, and webhook verification outcome from deterministic local rules, SHALL make no outbound network call, and SHALL return the same outcome for the same inputs on every invocation.
5. THE Simulated_Payment_Gateway SHALL provide a documented input that produces each of a successful authorization, a declined authorization, a request timeout, a successful refund, and a rejected refund, so that every Requirement 9 criterion is exercisable without a live Payment_Provider.
6. THE Delivery_API SHALL reference no Payment_Provider type, field name, error code, or software development kit outside the Payment_Module.
7. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL execute a static check that fails on any reference to a Payment_Provider software development kit from a module other than Payment.
8. WHEN a new Payment_Gateway adapter is added, THE Platform SHALL satisfy every Requirement 9 criterion against that adapter without a change to Order_Module, Cart_Module, or Web_Client code.

### Requirement 37: Administrator Management Surfaces

**User Story:** As an Administrator, I want one place to manage every account, restaurant, courier, order, delivery, catalog record, report, and platform setting, so that I can run the marketplace.

#### Acceptance Criteria

1. WHEN an Administrator requests the Platform user list, THE Admin_Module SHALL return Platform users in pages of 20, filterable by Role, by Account_Status, and by email-address substring, each carrying the user identifier, display name, email address, Role, Account_Status, and account creation UTC timestamp, and excluding the stored password hash.
2. WHEN an Administrator creates a Platform user with a Role from the set {`customer`, `restaurant_operator`, `restaurant_staff`, `courier`, `administrator`}, THE Admin_Module SHALL create exactly one Platform user with that Role through the Auth_Module Module_Boundary, WHERE the submitted Role is `restaurant_operator` SHALL require a Restaurant identifier and associate the created Platform user with exactly that one Restaurant, and WHERE the submitted Role is `restaurant_staff` SHALL require a Restaurant_Branch identifier and associate the created Platform user with exactly that one Restaurant_Branch.
3. WHEN an Administrator requests the Restaurant list, THE Admin_Module SHALL return Restaurants in pages of 20 in every Restaurant_Status, filterable by Restaurant_Status and by name substring, each carrying its Restaurant_Branch count and Aggregate_Rating.
4. WHEN an Administrator requests the Courier list, THE Admin_Module SHALL return Couriers in pages of 20, filterable by Rider_Status and by Account_Status, each carrying the Rider_Status, the Account_Status, the active Delivery_Job identifier where one exists, and the count of Delivery_Jobs closed with the Delivery_Job_Outcome `delivered`.
5. WHEN an Administrator requests the Delivery_Job list, THE Admin_Module SHALL return Delivery_Jobs in pages of 20, filterable by assignment state, by presence in the Administrator_Queue, by Delivery_Job_Outcome, by Courier, and by creation-date range.
6. WHEN an Administrator creates, updates, or archives a Menu_Category, a Menu_Item, a Product_Variant, a Modifier_Group, or a Modifier_Option of any Restaurant, THE Admin_Module SHALL apply the change through the Catalog_Module Module_Boundary and SHALL enforce every Catalog_Module validation rule of Requirements 11 and 34.
7. WHEN an Administrator requests a Report for a date range of at most 366 days, THE Admin_Module SHALL return Order counts by Order_State, the gross Order_Total sum, the mean delivery duration in whole seconds, the Delivery_Job count closed with the Delivery_Job_Outcome `failed`, and the Order count by Restaurant, each computed over that range.
8. IF a Report request supplies a date range longer than 366 days, a start date later than its end date, or a start date earlier than the earliest Order creation timestamp minus 1 day, THEN THE Admin_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating parameter, and SHALL return no Report.
9. WHEN an Administrator updates Platform_Configuration, THE Admin_Module SHALL persist the submitted values, SHALL apply them to every Delivery_API request received after the commit, and SHALL record an Audit_Entry as stated in Requirement 18 criterion 6.
10. IF an Administrator submits a Platform_Configuration service-fee percentage outside the closed range 0 to 25 percent, a minimum Service_Fee greater than the maximum Service_Fee, an initial Offer_Radius or a maximum Offer_Radius outside the closed range 1 to 20 kilometres, or an initial Offer_Radius greater than the maximum Offer_Radius, THEN THE Admin_Module SHALL respond with HTTP status 422 and the API_Error code `validation_failed`, and SHALL leave the stored Platform_Configuration unchanged.
11. THE Admin_Module SHALL read and write every record through the Module_Boundary interface of the owning module, and SHALL hold no direct database write path to a table owned by another module.

### Requirement 38: Backend Layering and SOLID Design

**User Story:** As a developer, I want one predictable path from HTTP request to database, so that I can locate any behavior and change it without reading the whole codebase.

#### Acceptance Criteria

1. THE Delivery_API SHALL route every write request through the Backend_Layer_Chain in the order Controller, Form_Request, Service_Interface, Service_Implementation, Repository_Interface, Repository_Implementation, Eloquent_Model, PostgreSQL.
2. THE Delivery_API SHALL restrict every Controller method to resolving the authenticated identity, invoking exactly one Service_Interface method, and mapping the returned value to an HTTP response, and SHALL place no conditional business rule, no monetary calculation, no Order_State decision, and no Eloquent_Model query in a Controller.
3. THE Delivery_API SHALL declare the validation schema of every endpoint in a Form_Request and SHALL reject a non-conforming request before invoking a Service_Implementation.
4. THE Delivery_API SHALL resolve every Service_Implementation and every Repository_Implementation through constructor injection from the service container, and SHALL construct no Service_Implementation and no Repository_Implementation with a direct instantiation inside another class.
5. THE Delivery_API SHALL place every business rule of a module in a Service_Implementation of that module, and SHALL place no business rule in a Controller, an Eloquent_Model, a Form_Request, a database migration, or a Web_Client component.
6. WHERE a module's persistence requires a query composed of more than one clause, a query used by more than one Service_Implementation, or a query whose result must be replaceable in a test by a double, THE Delivery_API SHALL place that query behind a Repository_Interface owned by that module.
7. THE Delivery_API SHALL introduce an interface, an abstract class, or an additional layer only where at least one of a second implementation, a test double requirement, or a Module_Boundary requirement applies, and THE Documentation_Set SHALL record which of those reasons applies for every declared interface.
8. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL execute a static check that fails on a Controller referencing an Eloquent_Model, a Controller referencing a Repository_Interface, a Service_Implementation referencing a Controller, and an Eloquent_Model referencing a Service_Interface.
9. THE Delivery_API SHALL give every class exactly one stated responsibility recorded as a one-sentence class-level comment, and SHALL keep every Service_Implementation method under 50 executable lines.
10. WHEN a new behavior is added to a Service_Implementation that already satisfies a Service_Interface used by more than one caller, THE Platform SHALL extend the behavior through a new implementation, a strategy, or a new interface method rather than by adding a conditional branch on a caller identity.

### Requirement 39: Design Pattern Selection and Documentation

**User Story:** As the developer learning from this project, I want each applied pattern justified in writing, so that I learn when a pattern is warranted instead of applying patterns by reflex.

#### Acceptance Criteria

1. THE Platform SHALL apply a design pattern only where the pattern solves a stated problem in the Platform, and SHALL introduce no design pattern whose sole purpose is demonstration.
2. WHEN a design pattern is applied in the codebase, THE Documentation_Set SHALL hold a Design_Pattern_Record for that application containing the problem, why the pattern helps, the implementation location, the alternative considered, and the reason for choosing the pattern over that alternative.
3. THE Documentation_Set SHALL hold a Design_Pattern_Record for every applied instance of the Repository, Service Layer, Strategy, Factory, Adapter, Observer, Dependency Injection, State Machine, Data Transfer Object, and Specification patterns.
4. THE Platform SHALL apply the State Machine pattern to Order_State through the Order_State_Machine, the Adapter pattern to the Payment_Gateway, and the Observer pattern to the domain events published on Order_State transition and on Menu_Item availability change.
5. IF a code change adds, removes, or replaces an applied design pattern without a corresponding Design_Pattern_Record change in the same change set, THEN THE Build_Pipeline SHALL fail the run and name the pattern whose record is absent or stale.
6. WHEN an Agent_Role proposes a design pattern that introduces a new interface, a new abstract class, or a new indirection layer, THE Platform SHALL require an Approval_Gate as stated in Requirement 49 before that pattern is implemented.

### Requirement 40: Database Integrity and Transactional Consistency

**User Story:** As an operator, I want the database to reject impossible data and never keep half of a multi-step change, so that the Platform's records stay trustworthy.

#### Acceptance Criteria

1. THE Platform SHALL define a single-column primary key on every table.
2. THE Platform SHALL define a foreign key constraint with an explicit delete behavior for every column that references another table's primary key.
3. THE Platform SHALL define a unique constraint on every field combination that this document states must be unique, including the Platform user email address compared case-insensitively, the Order_Number, the Idempotency_Key per Customer, the Review per Order, and the default Delivery_Address per Customer.
4. THE Platform SHALL define a check constraint or an equivalent database-level constraint for every stored monetary amount restricting it to the closed range 0 to 99,999,999 minor currency units, for every stored latitude restricting it to the closed range -90 to 90, for every stored longitude restricting it to the closed range -180 to 180, for every stored rating restricting it to the closed range 1 to 5, and for every stored Cart_Line and Order_Line quantity restricting it to the closed range 1 to 20.
5. THE Platform SHALL store every Order_State, Role, Account_Status, Restaurant_Status, Rider_Status, Delivery_Job_Outcome, Notification_Category, Notification channel, and Task_Status value in a column constrained to the exact value set stated in the Glossary.
6. THE Platform SHALL define an index on every foreign key column and on every column named in a filter, sort, or lookup stated in Requirements 3, 12, 19, 35, and 37.
7. THE Platform SHALL store every timestamp as a timezone-aware type in UTC, every monetary amount as an integer type, every coordinate as a fixed-precision numeric type carrying at least 7 fractional digits, and every identifier as a type that admits no value collision across rows.
8. WHEN the Delivery_API executes a multi-step write that changes more than one table, THE Delivery_API SHALL execute every step of that write inside one database transaction and SHALL commit only after every step succeeds.
9. IF any step of a multi-step write fails, THEN THE Delivery_API SHALL roll back the whole transaction, SHALL leave every affected table in the state it held before the transaction began, and SHALL return an API_Error naming the failed step.
10. WHEN a Customer places an Order, THE Order_Module SHALL execute cart validation, total calculation, Order creation, Order_Line creation, fulfilling Restaurant_Branch assignment, and Idempotency_Key recording inside one database transaction, SHALL delete the Cart only on the transition to Order_State `confirmed` as stated in Requirement 8 criterion 6, and IF any of those steps fails, THEN THE Order_Module SHALL roll back the transaction, leave no Order and no Order_Line persisted, and leave the Cart contents unchanged.
11. THE Platform SHALL express every schema change as a reversible migration that the Build_Pipeline applies and reverts against an empty PostgreSQL database without an error.
12. THE Platform SHALL enforce every constraint stated in criteria 1 through 7 at the database level in addition to any application-level validation, so that a direct database write that violates a constraint fails.

### Requirement 41: Web Client Architecture and State Ownership

**User Story:** As a frontend developer, I want server data and client data kept in separate, predictable places, so that cache behavior and UI state stay easy to reason about.

#### Acceptance Criteria

1. THE Web_Client SHALL organize source code into the directories `src/features/auth`, `src/features/restaurants`, `src/features/menu`, `src/features/cart`, `src/features/orders`, `src/features/delivery`, `src/features/tracking`, `src/components`, `src/layouts`, `src/hooks`, `src/services`, `src/lib`, `src/types`, and `src/routes`, and SHALL place every feature-specific component, Feature_Hook, and type inside that feature's directory.
2. THE Web_Client SHALL hold every piece of Server_State in the Query_Client and SHALL store no Server_State in the Redux_Store.
3. THE Web_Client SHALL hold every piece of Client_State in the Redux_Store or in React component state, and SHALL hold no Client_State in the Query_Client.
4. THE Web_Client SHALL obtain Server_State in a component only through a Feature_Hook, and SHALL issue no direct HTTP call and no Query_Client call from a component.
5. THE Web_Client SHALL route every Delivery_API request through the API_Client, following the call chain component, Feature_Hook, Query_Client, API_Client, Delivery_API.
6. THE Web_Client SHALL derive every loading indicator, error message, and empty-state display for Server_State from the Query_Client status for that Server_State, and SHALL maintain no duplicate loading or error flag in the Redux_Store for that Server_State.
7. WHEN a mutation that changes Server_State succeeds, THE Web_Client SHALL invalidate every Query_Client cache key whose data the mutation changed, and SHALL leave every unaffected cache key valid.
8. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL execute a static check that fails on an HTTP call issued outside the API_Client, on a Query_Client call issued outside a Feature_Hook, and on a Redux_Store slice whose state shape holds a Delivery_API response type.
9. THE Web_Client SHALL compile with TypeScript strict mode enabled and SHALL declare no `any` type outside a documented and justified exclusion.
10. THE Web_Client SHALL place every cross-feature reusable presentational component in `src/components` and SHALL import no component from one feature directory into another feature directory.

### Requirement 42: Realtime Broadcasting Scope

**User Story:** As a Customer, I want the screen to update itself as my order progresses, so that I do not have to refresh to learn what is happening.

#### Acceptance Criteria

1. THE Platform SHALL use Laravel Reverb as the sole Realtime_Gateway implementation in the initial implementation phase, and SHALL integrate no AWS IoT service and no third-party hosted WebSocket service in that phase.
2. THE Realtime_Gateway SHALL broadcast exactly the five Realtime_Events `order.created`, `order.state_changed`, `courier.location_updated`, `delivery.state_changed`, and `notification.created`, and SHALL broadcast no further Realtime_Event.
3. WHEN a Courier submits a Location_Report, THE Delivery_API SHALL validate the Location_Report, persist the Courier's most recent Courier_Location, publish a domain event, and broadcast that event through the Realtime_Gateway, in that order.
4. IF Location_Report validation fails, THEN THE Delivery_API SHALL persist no Courier_Location, publish no domain event, and broadcast no Realtime_Event.
5. THE Realtime_Gateway SHALL run as a process separate from the Delivery_API process and the Queue_Worker process, in the Local_Dev_Environment and in the production environment.
6. IF the Realtime_Gateway is unreachable when the Delivery_API publishes a domain event, THEN THE Delivery_API SHALL complete the originating request successfully, record the broadcast failure in the Observability_Stack, and leave the persisted state unchanged.
7. THE Web_Client SHALL treat every received Realtime_Event as advisory and SHALL read the authoritative value from the Delivery_API through the Query_Client before displaying a state that gates a user action.

### Requirement 43: Tracking Module Decoupling and Simulated Movement

**User Story:** As a developer, I want location handling isolated from order rules, so that tracking can change or scale without touching order logic, and so that I can develop it without paying for a location service.

#### Acceptance Criteria

1. THE Tracking_Module SHALL own exactly the responsibilities of receiving a Location_Report, validating a Location_Report, storing a Courier's most recent Courier_Location, storing Courier_Location history, broadcasting a Courier_Location, maintaining a Tracking_Session, and recording Rider_Status transitions reported by the Rider_Module.
2. THE Tracking_Module SHALL hold no Order_State rule, no Delivery_Job assignment rule, no pricing rule, and no Notification composition rule.
3. THE Tracking_Module SHALL read Order and Delivery_Job data only through the Module_Boundary interfaces of the Order_Module and the Dispatch_Module, and SHALL write to no table owned by another module.
4. THE Tracking_Module SHALL accept a Location_Report carrying a Courier identifier, a latitude in the closed range -90 to 90, a longitude in the closed range -180 to 180, an accuracy in metres in the closed range 0 to 10,000, and a UTC timestamp not more than 60 seconds later and not more than 300 seconds earlier than the Delivery_API receipt time.
5. IF a Location_Report omits a required field, carries a latitude, longitude, or accuracy outside its stated range, or carries a timestamp outside the stated window, THEN THE Tracking_Module SHALL respond with HTTP status 422, the API_Error code `invalid_location_report`, and one per-field validation detail for each violating field, and SHALL store no Courier_Location.
6. THE Tracking_Module SHALL store exactly one most-recent Courier_Location per Courier and SHALL append every accepted Courier_Location to the Courier_Location history.
7. THE Platform SHALL produce Courier movement in the initial implementation phase from a documented local simulation that emits Location_Reports along a configured coordinate sequence, and SHALL call no paid external map, geocoding, routing, or location service for Courier movement.
8. THE Platform SHALL integrate no AWS-native location service in the initial implementation phase.
9. THE Tracking_Module SHALL accept a Location_Report from a Courier whose Rider_Status is `online` or `on_delivery`, and IF a Location_Report is submitted for a Courier whose Rider_Status is `offline`, THEN THE Tracking_Module SHALL respond with HTTP status 409 and the API_Error code `rider_offline` and SHALL store no Courier_Location.

### Requirement 44: Environment Configuration and Secret Hygiene

**User Story:** As a developer, I want every configurable value documented in one place and no secret in the repository, so that I can run the Platform anywhere without leaking credentials.

#### Acceptance Criteria

1. THE Platform SHALL read every environment-specific value from an environment file at boot and SHALL hold no environment-specific value as a literal in application code.
2. THE Platform SHALL provide a committed example environment file that lists every required environment variable with a non-secret placeholder value.
3. THE Platform SHALL group every environment variable in the example environment file and in the Documentation_Set under exactly one of the headings APP, DATABASE, REDIS, CACHE, QUEUE, REVERB, FILESYSTEM, MAIL, and API.
4. THE Documentation_Set SHALL record for every environment variable the purpose, the value format, the default value where one exists, the grouping heading, and whether the variable holds a secret.
5. THE Platform SHALL exclude every populated environment file, every private key, and every credential file from version control through a committed ignore rule.
6. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL scan the change set for a committed secret value and SHALL fail the run on any finding.
7. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL verify that every environment variable read by application code appears in the example environment file and in the Documentation_Set, and SHALL fail the run on any variable absent from either.
8. IF a required environment variable is absent or holds an empty value at application boot, THEN THE Delivery_API SHALL terminate startup and write a message naming the absent variable and its grouping heading.
9. THE Platform SHALL read every production secret from a managed secret store at runtime and SHALL write no secret value into an image layer, a build log, a deployment log, or an application log.

### Requirement 45: AWS Production Topology and Manual Provisioning

**User Story:** As an operator learning AWS, I want the production topology fixed and every manual step written down, so that I understand what I built and can later reproduce it in code.

#### Acceptance Criteria

1. THE Platform SHALL serve production traffic through the path client, CloudFront, Application Load Balancer, ECS on Fargate, where the ECS deployment runs the Delivery_API, the Queue_Worker, and the Realtime_Gateway as separate services.
2. THE Platform SHALL use RDS for PostgreSQL as the production database, ElastiCache for Redis as the production Cache_Store and queue backend, and S3 for stored files.
3. THE Platform SHALL use VPC, IAM, ECR, Secrets Manager, CloudWatch, Route 53, and ACM as the supporting production services, and SHALL introduce no further AWS service without an ADR and an Approval_Gate.
4. THE Platform SHALL place the RDS instance and the ElastiCache instance in private subnets reachable only from the ECS services.
5. THE Platform SHALL provision every AWS resource of the initial implementation phase through the AWS Console, the AWS CLI, or the Deploy_Pipeline, and SHALL use no declarative infrastructure-as-code tool in that phase.
6. WHEN an AWS resource is provisioned, THE Documentation_Set SHALL record within the same change set the service, the resource name, every non-default configuration value, every attached IAM policy, every security group rule, every network placement, and the exact Console steps or CLI commands used.
7. THE Documentation_Set SHALL record the manual AWS configuration at a level of detail sufficient for a declarative infrastructure-as-code definition to reproduce every resource without inspecting the live AWS account.
8. WHEN a provisioned AWS resource's configuration changes, THE Documentation_Set SHALL record the change in the same change set, and IF the recorded configuration and the live configuration differ, THEN THE Platform SHALL treat that difference as a defect.
9. THE Platform SHALL grant every ECS task, every Deploy_Pipeline role, and every Build_Pipeline role only the IAM permissions that the recorded workload requires, and SHALL attach no policy granting an action on a resource that the Documentation_Set does not state a need for.

### Requirement 46: AWS Cost Control

**User Story:** As the owner of a learning account, I want to approve every chargeable resource before it exists, so that this project does not produce a surprise bill.

#### Acceptance Criteria

1. WHEN an AWS resource creation is proposed, THE Platform SHALL present a Cost_Disclosure stating the service, the purpose, why the resource is needed, the expected configuration, the free-tier eligibility, the potential charges, how to monitor the cost, and how to delete the resource, and SHALL stop until a human records explicit approval of that Cost_Disclosure.
2. THE Platform SHALL create no AWS resource before the Cost_Disclosure for that resource is approved.
3. THE Platform SHALL state free-tier eligibility as eligible, partially eligible, or not eligible for every proposed AWS resource, and SHALL state no AWS service as free without naming the free-tier limit that applies and the charge that begins when that limit is exceeded.
4. THE Platform SHALL propose no AWS resource that no requirement in this document needs.
5. WHEN a Development_Phase that creates AWS resources completes, THE Documentation_Set SHALL record every created resource with its deletion procedure and the expected monthly charge at the recorded configuration.
6. THE Platform SHALL configure an AWS budget with an alert threshold before the first chargeable AWS resource is created, and THE Documentation_Set SHALL record the configured threshold and the alert destination.
7. IF a proposed AWS resource would exceed the approved budget threshold at its expected configuration, THEN THE Platform SHALL report that the threshold would be exceeded, propose a lower-cost configuration, and stop until a human records explicit approval.

### Requirement 47: Continuous Integration and Delivery Stages

**User Story:** As a developer, I want the pipeline to run every check on a pull request and deploy only from main, and I want the workflow files explained, so that I can maintain the pipeline myself.

#### Acceptance Criteria

1. THE Build_Pipeline and the Deploy_Pipeline SHALL be defined as GitHub Actions workflows stored in version control.
2. WHEN a pull request is opened or updated, THE Build_Pipeline SHALL run, in a single workflow, the backend test suite, the backend coverage measurement, the frontend test suite, the frontend coverage measurement, the TypeScript type check, the ESLint check, the Web_Client production build, and the container image build.
3. IF any Build_Pipeline job fails on a pull request, THEN THE Build_Pipeline SHALL report a failed status on the pull request and SHALL block the merge.
4. WHEN a commit is merged to the `main` branch and the Build_Pipeline succeeds, THE Deploy_Pipeline SHALL build the container image, push it to ECR tagged with the commit SHA, run the database migrations, update the ECS services to that image, wait for the ECS deployment to report a healthy steady state, and run the Smoke_Test_Suite against the deployed environment, in that order.
5. IF the ECS deployment does not reach a healthy steady state, or the Smoke_Test_Suite fails, THEN THE Deploy_Pipeline SHALL restore the previously deployed image, report a failed deployment status, and record the failure in the Observability_Stack.
6. THE Deploy_Pipeline SHALL obtain AWS credentials through OIDC_Trust, and SHALL store no long-lived AWS access key in the repository, in a workflow file, or in the repository secret store.
7. THE Documentation_Set SHALL explain every workflow file section by section, covering each of `name`, `on`, `permissions`, `jobs`, `runs-on`, `steps`, `uses`, `with`, and `env`, and SHALL state for each the meaning, the reason it is present, what breaks if it is removed, and its security implication.
8. THE Documentation_Set SHALL explain the OIDC_Trust security model as the chain GitHub Actions, OpenID Connect provider, AWS IAM role, temporary credentials, AWS resources, and SHALL state the trust-policy conditions that restrict the role to this repository.
9. THE Build_Pipeline SHALL run the backend test suite against PostgreSQL and Redis service containers at the same major versions used in production.
10. THE Build_Pipeline SHALL complete a full run within 15 minutes, and IF a run exceeds 15 minutes, THEN THE Build_Pipeline SHALL terminate the run and report a failed status indicating the pipeline timeout.

### Requirement 48: Test-Driven Development Order and Coverage Enforcement

**User Story:** As a developer, I want tests written before code and complete coverage enforced, so that every behavior in this document is verified rather than assumed.

#### Acceptance Criteria

1. THE Platform SHALL produce every unit of application code through the ordered cycle requirement, acceptance criterion, write test, run test, observe failing test, implement the minimum code, run test, observe passing test, refactor, measure coverage, update documentation.
2. THE Platform SHALL record in the Handoff_Record of every Task_Record the commit or change-set reference in which the failing test was written and the reference in which the implementation made it pass, and THE Platform SHALL place the failing-test reference no later than the implementation reference.
3. IF application code for a Task_Record is implemented before a test for that Task_Record's acceptance criteria exists, THEN THE Platform SHALL treat that Task_Record as not satisfying its Definition_of_Done and SHALL return the Task_Record to Task_Status `IN_PROGRESS`.
4. THE Platform SHALL include backend tests of the kinds unit, feature, API, database, authorization, business rule, validation, event, queued Job, failure case, and edge case, and SHALL include at least one test of each kind for every module that this document assigns behavior of that kind.
5. THE Platform SHALL include frontend tests covering component rendering, Feature_Hook behavior, form submission, client-side validation display, Redux_Store state, Delivery_API integration through the API_Client, loading states, error states, authentication flows, Order workflows, and Realtime_Event handling.
6. THE Build_Pipeline SHALL measure line coverage of the Delivery_API application code and of the Web_Client application code on every run and SHALL report the measured values.
7. IF measured line coverage of the Delivery_API application code or of the Web_Client application code is below 100 percent of the lines not covered by a documented exclusion, THEN THE Build_Pipeline SHALL fail the run.
8. THE Documentation_Set SHALL record every coverage exclusion with the excluded file or line range, the reason, and the Task_ID that introduced it, and THE Build_Pipeline SHALL fail the run on any exclusion absent from that record.
9. THE Platform SHALL include no test that passes without asserting an observable outcome of the code under test, and THE Platform SHALL include no test whose assertions hold when the implementation under test is removed.
10. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL verify that every acceptance criterion identifier in this document maps to at least one automated test and SHALL fail the run on any criterion with no mapped test.

### Requirement 49: Incremental Delivery and Human Approval Gates

**User Story:** As the developer directing this project, I want to be told what is about to happen and to approve it before it happens, so that I learn the reasoning and keep control of my machine, my database, and my AWS account.

#### Acceptance Criteria

1. WHEN a Development_Phase, a module, or a Task_Record is about to begin, THE Platform SHALL present what is being built, why it is being built, the architecture involved, the dependencies, the files to be created or modified, the packages and tools to be installed, the important commands to be run, the risks and the alternatives considered, the tests required, and the documentation to be updated, and SHALL then stop until a human records explicit approval.
2. THE Platform SHALL reach an Approval_Gate and stop before installing software on the developer machine, installing a package into the Delivery_API or the Web_Client, modifying infrastructure, modifying database structure, creating an AWS resource, changing an architectural decision, implementing a Task_Record, resuming a Task_Record with Task_Status `BLOCKED`, and introducing a new dependency.
3. THE Platform SHALL perform no action listed in criterion 2 before a human records explicit approval of that action.
4. IF a human records a rejection at an Approval_Gate, THEN THE Platform SHALL leave every file, package, database object, and AWS resource unchanged, SHALL record the rejection and its stated reason against the Task_Record, and SHALL propose a revised approach.
5. THE Platform SHALL deliver work in increments of one Task_Record at a time, and SHALL begin no second Task_Record while a Task_Record it depends on holds a Task_Status other than `COMPLETED`.
6. WHEN an increment completes, THE Platform SHALL report the implemented behavior, the files changed, the tests added, the measured coverage, and the documentation updated, and SHALL stop until a human records explicit approval to continue.
7. THE Platform SHALL name the exact command, package name, and version for every proposed installation at the Approval_Gate, and SHALL install no package version other than the approved version.

### Requirement 50: Task Records and Task Status

**User Story:** As the developer directing this project, I want every unit of work identified and tracked, so that I always know what is done, what is next, and what is stuck.

#### Acceptance Criteria

1. THE Platform SHALL assign every unit of work exactly one Task_ID of the form stated in the Glossary, and SHALL assign no Task_ID to more than one unit of work.
2. THE Platform SHALL hold for every Task_ID exactly one Task_Record on the Task_Board carrying the fields Task_ID, Title, Description, Module, Owner Agent, Task_Status, Priority, Dependencies, Blocks, Requirements, Acceptance Criteria, Files, Tests, Documentation, Approval Status, Blockers, and Notes.
3. THE Platform SHALL set every Task_Record's Task_Status to exactly one value from the set {`BACKLOG`, `READY`, `IN_PROGRESS`, `BLOCKED`, `READY_TO_TEST`, `TESTING`, `COMPLETED`, `DEFERRED`, `CANCELLED`}.
4. IF a Task_Status value outside that set is required, THEN THE Platform SHALL stop, propose the new value with its definition and its permitted transitions, and wait for a human to record explicit approval and for the Documentation_Set to record the new value before using it.
5. THE Platform SHALL populate a Task_Record's Requirements field with the identifiers of the requirements in this document that the Task_Record implements, and SHALL populate its Acceptance Criteria field with the specific acceptance criteria that the Task_Record satisfies.
6. WHEN a Task_Record's Task_Status changes, THE Platform SHALL update the Task_Board in the same change set and SHALL record the previous Task_Status, the new Task_Status, and the UTC timestamp.
7. THE Platform SHALL set a Task_Record's Task_Status to `READY` only where every Task_ID in its Dependencies field holds Task_Status `COMPLETED`.
8. THE Platform SHALL set a Task_Record's Task_Status to `COMPLETED` only where the Task_Record satisfies the Definition_of_Done, holds a recorded approval, and holds a Handoff_Record.
9. WHEN a Task_Record's Task_Status is set to `DEFERRED` or `CANCELLED`, THE Platform SHALL record the reason and the Task_IDs that the deferral or cancellation blocks.

### Requirement 51: Dependency and Blocker Protocol

**User Story:** As the developer directing this project, I want blocked work stopped and reported instead of guessed at, so that I never receive work built on an assumption.

#### Acceptance Criteria

1. WHEN a Task_Record is about to begin, THE Platform SHALL evaluate every Task_ID in that Task_Record's Dependencies field before performing any implementation step.
2. IF any Task_ID in a Task_Record's Dependencies field holds a Task_Status other than `COMPLETED`, THEN THE Platform SHALL set that Task_Record's Task_Status to `BLOCKED`, SHALL record the dependency Task_ID, the reason the dependency is required, the blocking Task_ID, and the expected resolution, and SHALL perform no implementation step for that Task_Record.
3. THE Platform SHALL bypass an incomplete dependency only where a human records explicit approval of that bypass, and SHALL record the approved bypass in the Dependency_Record.
4. WHEN a Task_Record reaches Task_Status `COMPLETED`, THE Platform SHALL update the Dependency_Record of every Task_Record that depends on it, SHALL set the Task_Status of every such Task_Record whose dependencies are now all `COMPLETED` to `READY`, SHALL notify the human of each Task_Record that became `READY`, and SHALL wait for approval before beginning any of them.
5. WHEN a Task_Record cannot proceed, THE Platform SHALL set its Task_Status to `BLOCKED`, SHALL write a Blocker_Record, and SHALL update the Task_Board and the Dependency_Record in the same change set.
6. THE Platform SHALL state in every Blocker_Record what blocks the Task_Record, why the blocking item is required, the Task_ID, decision, or resource needed to unblock it, the possible solutions, and the recommended solution.
7. THE Platform SHALL implement no assumed value, no placeholder credential, and no substitute behavior in place of a blocking item, and SHALL set no Task_Record with an unresolved Blocker_Record to Task_Status `READY_TO_TEST`, `TESTING`, or `COMPLETED`.
8. WHEN a Blocker_Record is resolved, THE Platform SHALL record the resolution and the resolving Task_ID or decision in the Blocker_Record and SHALL move the affected Task_Record to Task_Status `READY`.

### Requirement 52: Module Completion Criteria

**User Story:** As the developer directing this project, I want one definition of done for a module, so that no module is called finished while a part of it is missing.

#### Acceptance Criteria

1. THE Platform SHALL mark a module as `DEVELOPED` only where its requirements are recorded, its Delivery_API endpoints are implemented and documented, its database objects are migrated, its backend code is implemented, its Web_Client surfaces are implemented, its tests are written and passing, its integration with dependent modules is verified, the coverage threshold of Requirement 48 is met, its documentation is complete, its code review is recorded, its manual verification is recorded, it holds no unresolved Blocker_Record, and a human has recorded explicit approval.
2. THE Documentation_Set SHALL hold the Definition_of_Done as a checklist that names every condition of criterion 1.
3. IF any condition of criterion 1 is unmet, THEN THE Platform SHALL leave the module's status as not `DEVELOPED` and SHALL name every unmet condition.
4. WHEN a module is marked `DEVELOPED`, THE Platform SHALL record the marking in `docs/project/PROJECT-STATUS.md` and in `docs/project/CHANGELOG.md` in the same change set.
5. THE Platform SHALL mark no module as `DEVELOPED` where a Task_Record of that module holds a Task_Status other than `COMPLETED`, `DEFERRED`, or `CANCELLED`.

### Requirement 53: Agent Roles, Ownership, and Handoff

**User Story:** As the developer directing this project, I want each task owned by one named role with a written handoff, so that responsibilities and context never get lost between steps.

#### Acceptance Criteria

1. THE Platform SHALL define exactly the six Agent_Roles `architect`, `backend`, `frontend`, `devops`, `qa`, and `security`.
2. THE Platform SHALL assign the `architect` Agent_Role the product requirements document, the architecture, the domain modeling, the API contracts, the database design, the ADRs, and the dependency analysis; the `backend` Agent_Role the Delivery_API implementation, the database objects, the business logic, the repositories, the services, the events, the queued Jobs, and the backend tests; the `frontend` Agent_Role the Web_Client implementation, the TypeScript types, the Query_Client usage, the Redux_Store usage, the components, the Feature_Hooks, the forms, the frontend tests, and the realtime user interface; the `devops` Agent_Role the Docker configuration, the Docker Compose configuration, the AWS resources, the GitHub Actions workflows, the logging, and the monitoring; the `qa` Agent_Role the Test-Driven Development order, the test strategy, the test review, the coverage measurement, the integration tests, the edge cases, and the regression suite; and the `security` Agent_Role the authentication, the authorization, the OWASP controls, the API security, the secret handling, the IAM policies, the Docker security, and the dependency security.
3. THE Platform SHALL assign every Task_Record exactly one Owner Agent from the six Agent_Roles.
4. THE Platform SHALL permit an Agent_Role to change only the files that its own Task_Records name, and IF a change to a file named by another Agent_Role's Task_Record is required, THEN THE Platform SHALL record a coordination note in `docs/project/AGENT-COORDINATION.md` and wait for a human to record explicit approval before making that change.
5. THE Platform SHALL follow the ownership chain `architect`, `backend`, `frontend`, `qa`, `security` for a module unless an ADR records a different chain for that module.
6. WHEN an Agent_Role sets a Task_Record's Task_Status to `COMPLETED`, THE Platform SHALL write a Handoff_Record in `docs/project/HANDOFFS.md` holding the Task_ID, the Task_Status, what was implemented, the files changed, the database changes, the API changes, the tests, the measured coverage, the known limitations, the documentation updated, the dependencies unlocked, and the next recommended Task_ID.
7. IF a Handoff_Record is absent or omits any field of criterion 6, THEN THE Platform SHALL leave the Task_Record's Task_Status unchanged from `TESTING` and SHALL name the absent fields.

### Requirement 54: Architecture Decision Records

**User Story:** As the developer learning from this project, I want every significant decision written down with its alternatives, so that I understand why the system looks the way it does.

#### Acceptance Criteria

1. WHEN an architectural decision is made that affects a Module_Boundary, a persisted data shape, a Delivery_API contract, a technology selection, an infrastructure topology, or a cross-cutting convention, THE Platform SHALL record an ADR under `docs/architecture/decisions/` in the same change set.
2. THE Platform SHALL hold in every ADR the sections Context, Problem, Options, Decision, Reason, Consequences, and Status.
3. THE Platform SHALL state at least two options in every ADR Options section and SHALL state why each rejected option was rejected.
4. THE Platform SHALL set every ADR Status to exactly one of `proposed`, `accepted`, `superseded`, or `rejected`, and WHEN an ADR is superseded, THE Platform SHALL set its Status to `superseded` and name the superseding ADR.
5. WHEN an existing architectural decision is changed, THE Platform SHALL reach an Approval_Gate as stated in Requirement 49 before the change is implemented, and SHALL record a new ADR rather than editing the accepted decision of the superseded ADR.
6. THE Platform SHALL record an ADR for each of the modular monolith choice, the PostgreSQL choice, the Redis choice, the Laravel Reverb choice, the Docker Compose local environment, the manual AWS provisioning choice, the GitHub Actions choice, the TanStack Query and Redux Toolkit state split, the Payment_Gateway abstraction, and the Order_State_Machine design.

### Requirement 55: Documentation Structure and Diagrams

**User Story:** As the developer learning from this project, I want the documentation laid out predictably and the architecture drawn as version-controlled text, so that I can read and diff the explanation of the system.

#### Acceptance Criteria

1. THE Platform SHALL treat a feature as incomplete until its documentation is present, and SHALL update the Documentation_Set in the same change set as the behavior it describes.
2. THE Documentation_Set SHALL hold the file `docs/product/PRD.md`; the files `docs/architecture/overview.md`, `docs/architecture/backend.md`, `docs/architecture/frontend.md`, `docs/architecture/realtime.md`, `docs/architecture/local.md`, and `docs/architecture/production.md`; the directory `docs/architecture/decisions/`; the directory `docs/api/`; the directory `docs/database/`; the files `docs/devops/docker.md`, `docs/devops/aws.md`, and `docs/devops/github-actions.md`; the directory `docs/testing/`; and the files `docs/project/PROJECT-STATUS.md`, `docs/project/TASK-BOARD.md`, `docs/project/TASK-DEPENDENCIES.md`, `docs/project/AGENT-COORDINATION.md`, `docs/project/BLOCKERS.md`, `docs/project/HANDOFFS.md`, `docs/project/DEFINITION-OF-DONE.md`, and `docs/project/CHANGELOG.md`.
3. WHEN the Build_Pipeline runs, THE Build_Pipeline SHALL verify that every path named in criterion 2 exists and SHALL fail the run on any absent path.
4. THE Documentation_Set SHALL express every architecture diagram as Mermaid text stored in a versioned Markdown file, and SHALL store no architecture diagram as a binary image alone.
5. THE Diagram_Set SHALL comprise a system context diagram, a container architecture diagram, a component architecture diagram, a database entity-relationship diagram, an authentication flow diagram, an Order flow diagram, a payment flow diagram, a Delivery_Job flow diagram, a Courier tracking diagram, a realtime communication diagram, a local Docker architecture diagram, an AWS production architecture diagram, and a CI/CD pipeline diagram.
6. WHEN a change alters a structure that a Diagram_Set diagram depicts, THE Platform SHALL update that diagram in the same change set.
7. THE Documentation_Set SHALL document every Delivery_API endpoint under `docs/api/` with the endpoint path, the HTTP method, the authentication requirement, the request schema, the validation rules, the response schema, the error codes, the HTTP status codes, the authorization rule, and at least one request example and one response example, and SHALL keep that content consistent with the OpenAPI_Document.
8. THE Documentation_Set SHALL document under `docs/database/` every table, every column with its type and constraints, every index, every foreign key with its delete behavior, and every transactional write sequence stated in Requirement 40.

### Requirement 56: Version Control and Pull Request Conventions

**User Story:** As the developer directing this project, I want branches, commits, and pull requests tied to task identifiers, so that I can trace any line of code back to the requirement that asked for it.

#### Acceptance Criteria

1. THE Platform SHALL treat the `main` branch as the production branch and SHALL deploy to production only from `main`.
2. THE Platform SHALL create every change on a branch named `feature/{TASK-ID}-{slug}`, where `{TASK-ID}` is an existing Task_ID and `{slug}` is a lowercase hyphen-separated summary of 1 to 40 characters.
3. THE Platform SHALL prefix every commit message subject with the Task_ID of the Task_Record the commit serves, followed by a colon and a space.
4. IF a commit message subject carries no Task_ID prefix, or carries a Task_ID that holds no Task_Record, THEN THE Build_Pipeline SHALL fail the run and name the offending commit.
5. THE Platform SHALL open a pull request for every change to `main` holding the Task_ID, the description, the requirements implemented, the acceptance criteria satisfied, the tests added, the measured coverage, the documentation updated, the database changes, the API changes, the breaking changes, and, WHERE the change alters a Web_Client surface, a screenshot of that surface.
6. IF a pull request omits any field of criterion 5, THEN THE Build_Pipeline SHALL report a failed status and name the absent fields.
7. THE Platform SHALL merge a pull request into `main` only where every Build_Pipeline job reports success.
8. THE Platform SHALL push to no branch other than the branch of the Task_Record in progress, and SHALL push to `main` only through a merged pull request.

### Requirement 57: Development Phases and Phase 0 Deliverables

**User Story:** As the developer directing this project, I want the build sequenced into approved phases that start with planning, so that the architecture is settled before any code is written.

#### Acceptance Criteria

1. THE Platform SHALL execute the work as the 25 ordered Development_Phases 0 project planning and documentation, 1 development environment, 2 Docker foundation, 3 Laravel foundation, 4 React and TypeScript foundation, 5 database and API architecture, 6 authentication, 7 restaurant, 8 menu, 9 cart, 10 orders, 11 payment abstraction, 12 delivery, 13 rider, 14 realtime tracking with Reverb, 15 notifications, 16 admin, 17 testing hardening, 18 production Docker, 19 AWS infrastructure, 20 GitHub Actions continuous integration, 21 GitHub Actions continuous delivery, 22 production deployment, 23 production verification, and 24 security and performance hardening.
2. THE Platform SHALL begin a Development_Phase only where every preceding Development_Phase holds a recorded human approval of its completion.
3. WHEN a change to the Development_Phase sequence, scope, or numbering is required, THE Platform SHALL present the proposed change with its reason and its effect on the Task_Board, and SHALL stop until a human records explicit approval.
4. THE Platform SHALL produce in Development_Phase 0 the product requirements document, the architecture proposal, the Diagram_Set, the domain and module breakdown, the database proposal, the Delivery_API proposal, the Web_Client architecture, the realtime architecture, the Docker architecture, the AWS architecture, the CI/CD architecture, the Test-Driven Development strategy, the testing strategy, the Agent_Role definitions, the task breakdown as Task_Records, the task dependency graph, the development milestones, the Definition_of_Done, the Docs_Tree, the initial ADRs of Requirement 54 criterion 6, and a Project Initialization Report summarizing those artifacts.
5. WHEN every Development_Phase 0 deliverable of criterion 4 exists, THE Platform SHALL present the Project Initialization Report and SHALL stop until a human records explicit approval to begin Development_Phase 1.
6. WHILE Development_Phase 0 is in progress, THE Platform SHALL run no installation command, SHALL create no AWS resource, SHALL create no database object, and SHALL implement no Delivery_API endpoint and no Web_Client feature.
7. THE Platform SHALL record every Development_Phase's status, its approval, and its completion UTC timestamp in `docs/project/PROJECT-STATUS.md`.

### Requirement 58: Courier Account Provisioning and Profile

**User Story:** As a Courier, I want an account with my vehicle details on it before I go online, so that dispatch offers me work I can actually take.

#### Acceptance Criteria

1. WHEN a Platform user with the Role `courier` is created, THE Rider_Module SHALL create exactly one Courier profile for that Platform user holding Rider_Status `offline`, Account_Status `active`, no vehicle type, and no closed Delivery_Job.
2. WHEN a Courier submits a profile update containing a vehicle type from the set {`bicycle`, `motorcycle`, `car`, `on_foot`}, a phone number of 5 to 20 characters drawn from digits, spaces, hyphens, and a leading plus sign, or a vehicle registration identifier of 0 to 20 characters, THE Rider_Module SHALL persist the submitted values and SHALL leave every unsubmitted field unchanged.
3. IF a Courier profile update submits a vehicle type outside the set stated in criterion 2, a phone number outside 5 to 20 characters or containing a character outside the permitted set, or a vehicle registration identifier longer than 20 characters, THEN THE Rider_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL leave the stored Courier profile unchanged.
4. IF a Courier whose Courier profile holds no vehicle type or no phone number sets the own Rider_Status to `online`, THEN THE Rider_Module SHALL respond with HTTP status 409 and the API_Error code `courier_profile_incomplete`, and SHALL leave that Courier's Rider_Status unchanged.
5. WHEN a Courier requests the own Courier profile, THE Rider_Module SHALL return the display name, the phone number, the vehicle type, the vehicle registration identifier, the Rider_Status, the Account_Status, and the count of Delivery_Jobs closed with the Delivery_Job_Outcome `delivered`, and SHALL exclude the stored password hash.
6. WHEN an Administrator sets a Courier's Account_Status to `suspended`, THE Dispatch_Module SHALL issue no further Delivery_Job offer to that Courier, THE Rider_Module SHALL set that Courier's Rider_Status to `offline` where that Courier holds no active Delivery_Job, and THE Rider_Module SHALL leave that Courier's Rider_Status unchanged where that Courier holds an active Delivery_Job.
7. WHEN a Courier requests a Platform user record other than the own record, THE Rider_Module SHALL respond with HTTP status 403 and the API_Error code `forbidden`, and SHALL include no field values of the target record in the response.

### Requirement 59: Restaurant Onboarding and Status Lifecycle

**User Story:** As an Administrator, I want a restaurant to move from draft to published only once it can actually take orders, so that Customers never see a Restaurant that cannot serve them.

#### Acceptance Criteria

1. WHEN an Administrator creates a Restaurant containing a name of 1 to 150 characters, a time zone that is a valid IANA time zone identifier, an ISO 4217 currency code, a tax rate in the closed range 0 to 30 percent, a base Delivery_Fee, a per-kilometre rate, a minimum Delivery_Fee, and a maximum Delivery_Fee, each monetary value in the closed range 0 to 99,999,999 minor currency units and the minimum Delivery_Fee less than or equal to the maximum Delivery_Fee, THE Catalog_Module SHALL create exactly one Restaurant with Restaurant_Status `draft`.
2. IF a Restaurant creation or update violates a rule of criterion 1, THEN THE Catalog_Module SHALL respond with HTTP status 422, the API_Error code `validation_failed`, and one per-field validation detail for each violating field, and SHALL create no Restaurant and SHALL leave an existing Restaurant unchanged.
3. THE Catalog_Module SHALL permit only the Restaurant_Status transitions `draft` to `pending_review`, `pending_review` to `published`, `pending_review` to `draft`, `published` to `suspended`, and `suspended` to `published`, and SHALL permit no other Restaurant_Status transition.
4. WHEN a Restaurant_Operator of a Restaurant whose Restaurant_Status is `draft` submits that Restaurant for review, THE Catalog_Module SHALL set that Restaurant's Restaurant_Status to `pending_review`.
5. IF an Administrator sets a Restaurant's Restaurant_Status to `published` while that Restaurant holds no Restaurant_Branch with an active flag of true, holds no Menu_Item with an availability flag of true, or holds no Delivery_Zone assigned to a Restaurant_Branch with an active flag of true, THEN THE Catalog_Module SHALL respond with HTTP status 409 and the API_Error code `restaurant_not_publishable`, SHALL name every unmet condition in the response, and SHALL leave that Restaurant's Restaurant_Status unchanged.
6. IF a requested Restaurant_Status transition is absent from the permitted set of criterion 3, THEN THE Catalog_Module SHALL respond with HTTP status 409 and the API_Error code `invalid_status_transition`, and SHALL leave that Restaurant's Restaurant_Status unchanged.
7. WHEN a Restaurant's Restaurant_Status changes, THE Catalog_Module SHALL invalidate that Restaurant's cached Menu read model and every cached Restaurant search read model holding that Restaurant within 5 seconds of the commit.
8. WHEN a Restaurant's Restaurant_Status becomes `suspended`, THE Order_Module SHALL retain every existing Order of that Restaurant in its current Order_State and SHALL permit every Order_State transition of Requirement 10 for those Orders.
9. WHEN a Restaurant's Restaurant_Status changes, THE Catalog_Module SHALL record an Audit_Entry as stated in Requirement 18 criterion 6 holding the previous Restaurant_Status and the new Restaurant_Status.
