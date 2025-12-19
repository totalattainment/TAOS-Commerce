# TA-OS Commerce Changelog

## [1.2.3] - 2025-05-15

### Changed
- Fix checkout user context binding

## [1.2.2] - 2025-05-15

### Changed
- Commerce now sells TAOS Courses (no standalone courses)

## [1.0.1] - 2024-12-12

### Changed
- Removed strict plugin dependency header for more flexible installation
- Added soft admin notice when TA-OS main plugin is not detected

### Fixed
- Plugin now activates independently and shows warning instead of blocking

## [1.0.0] - 2024-12-12

### Added
- Initial release
- PayPal gateway with sandbox and live support
- Admin UI: Commerce → Payments (gateway configuration)
- Admin UI: Commerce → Courses (pricing and entitlements)
- Admin UI: Commerce → Orders (payment history)
- Gateway-agnostic architecture via GatewayInterface
- Bundle support (one course grants multiple entitlements)
- REST API endpoints for checkout flow
- TA-OS entitlements bridge (taos_grant_entitlement function)
- Idempotent order processing (prevents duplicate charges)
- Database tables: courses, course_entitlements, orders
