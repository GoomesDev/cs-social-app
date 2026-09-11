# Killfeed — Steam Authentication

Killfeed uses Steam to identify players without handling their Steam passwords. The Laravel backend validates the sign-in response and provides authentication tokens for the mobile app.

## How it works

The player signs in through Steam in the browser and returns to the app with a temporary code. The app exchanges that code for a Laravel Sanctum token.

## Technical overview

- **Steam OpenID 2.0** for identity verification.
- **PKCE S256** to bind the code exchange to the initiating app.
- **Single-use codes** with expiration and protection against reuse.
- **Laravel Sanctum** for API tokens with explicit expiration.
- **Restricted return destinations** and request rate limits.
- **Transactional code exchange** to prevent duplicate token issuance.

## Testing

Automated tests cover successful authentication, rejected and expired attempts, invalid verifiers, Steam communication failures, and concurrent code exchanges. Tests use isolated databases and simulated Steam responses.

## Status

The backend authentication flow is implemented. Integration and validation on native mobile builds are part of the ongoing development of Killfeed.
