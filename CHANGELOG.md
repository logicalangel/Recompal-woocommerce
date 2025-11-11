# Changelog

All notable changes to Recompal WordPress Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-11-10

### Added
- Initial release of Recompal for WooCommerce
- AI-powered chat widget for customer engagement
- Automatic product synchronization with Recompal API
- Real-time product webhooks (create, update, delete)
- WordPress admin dashboard integration via iframe
- Appearance customization settings page
- Conversation history and analytics
- Data source management
- Billing and subscription management
- Help and support interface
- API proxy for CORS-free communication
- Multi-language support ready (i18n)
- WooCommerce dependency checking
- Automatic API key generation on activation
- Proper uninstall cleanup

### Security
- XSS protection with proper escaping
- HTTPS-only API communication
- JWT token authentication
- Sanitized database inputs
- WordPress nonce verification ready

### Fixed
- Error handling for failed API registrations
- WooCommerce dependency validation
- Token validation before storage
- Proper plugin deactivation on errors

## [Unreleased]

### Planned
- Settings page for API URL configuration
- Admin notices for setup guidance
- Rate limiting for API proxy
- Retry mechanism for failed webhooks
- Multisite compatibility
- Performance optimizations
- Additional language translations

---

[1.0.0]: https://github.com/recompal/recompal-wordpress/releases/tag/1.0.0
