# Google OAuth for Solidtime — Design Spec

**Date:** 2026-06-09
**Branch:** `feat/google-oauth`
**Status:** Approved design, pending implementation plan

## Goal

Add "Continue with Google" login/registration to Solidtime (this internal 3D Lab
fork), via Laravel Socialite, with a configurable email allowlist gating new
self-registration. Self-host, env-var driven. Not targeting upstream contribution.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Auth policy | Google can **sign up + sign in**; **auto-link** to an existing account by verified email. |
| Config source | `.env` variables. Button hidden when Google not configured. |
| Linkage storage | Dedicated `oauth_connections` table (not columns on `users`). |
| Allowlist match | Both whole domains (`@domain`) and exact emails, comma-separated, case-insensitive. |
| Allowlist scope | Gates **new self-registration only** (Google **and** password). Existing users always log in. Org invitations bypass it. |
| Empty allowlist | **Fail-open** — unset/empty var ⇒ registration stays open (stock behavior). |
| Password registration | Also gated by the same allowlist. |
| Enhancements | Notify-on-auto-link, PKCE, Google `hd` hint, rate-limited OAuth routes — all included. |

## Stack context (verified in repo)

- Laravel 12 + Jetstream 5 (Fortify) + Inertia + Vue 3. PHPUnit tests.
- `User`: UUID PK, `password` **already nullable**, fillable `name/email/password`,
  implements `MustVerifyEmail`. No provider columns yet.
- Auto-org on signup: `App\Service\UserService::createUser()` creates the user's
  personal Organization. The OAuth register path **must reuse this**.
- Existing registration gate: `CreateNewUser` already checks
  `config('app.enable_registration')`. The allowlist layers on top of this flag.
- `socialite` is **not** installed — clean slate.

## Flow

1. User clicks "Continue with Google" → `GET /auth/google/redirect` → Socialite
   redirect (stateful, PKCE enabled, optional `hd` hint).
2. Google → `GET /auth/google/callback`.
3. Resolve `email_verified` from the **raw** payload
   (`$socialiteUser->user['email_verified'] ?? false`). If false/missing → reject.
4. Look up `oauth_connections` by `(provider='google', provider_user_id=sub)`:
   - **Found** → log in that user.
   - **Not found** → look up `users` by email (excluding `is_placeholder`):
     - **User exists** → auto-link (create connection row), notify the user by
       email, log in. No allowlist check (existing user).
     - **No user** → check `app.enable_registration` AND
       `RegistrationAllowlist::allows(email)`; if blocked → reject with flash.
       Otherwise create user (no password, `email_verified_at = now()`) + personal
       Organization via `UserService::createUser()`, create connection row, log in.
5. After any successful login: **`$request->session()->regenerate()`** (session
   fixation defense — `Auth::login()` does not rotate the session on its own).

## Components

### Data model
- **Migration** `oauth_connections`:
  `id` (uuid PK), `user_id` (uuid FK→users, cascade delete), `provider` (string),
  `provider_user_id` (string), timestamps.
  Unique index `(provider, provider_user_id)`; index `user_id`.
- **Model** `app/Models/OAuthConnection.php` (`HasUuids`, `belongsTo(User)`).
- `User::oauthConnections()` hasMany relation.

### Backend
- **`config/services.php`** `google` block: `client_id`, `client_secret`,
  `redirect`, plus `hosted_domain` (for `hd`).
- **`App\Service\RegistrationAllowlist`** — `allows(string $email): bool`.
  Parses `REGISTRATION_ALLOWLIST`; matches exact emails and `@domain` suffixes;
  case-insensitive; **empty/unset ⇒ true (fail-open)**. Pure, unit-testable.
- **`App\Service\GoogleOAuthService`** — takes a Socialite user, performs the
  resolve/link/create logic above, returns an authenticated `User` or throws typed
  exceptions: `EmailNotVerifiedException`, `RegistrationNotAllowedException`.
  Reuses `UserService::createUser()` for the register path. No HTTP concerns →
  unit-testable.
- **`App\Http\Controllers\Auth\GoogleOAuthController`**:
  - `redirect()` — `Socialite::driver('google')->enablePkce()` (+ `hd` when set)
    `->redirect()`.
  - `callback()` — calls the service; catches typed exceptions **and** Socialite
    `InvalidStateException` / user-denied (`?error=access_denied`) → redirect
    `/login` with flash error. On success → `session()->regenerate()` →
    redirect home.
- **Routes** (`routes/web.php`, guest middleware + `throttle`):
  `GET /auth/google/redirect` (name `auth.google.redirect`),
  `GET /auth/google/callback` (name `auth.google.callback`).
- **`CreateNewUser`** (password path): add `RegistrationAllowlist::allows()` check
  alongside the existing `enable_registration` check. Org-invitation acceptance
  flow must **not** run through this gate.
- **Notification** `GoogleAccountLinked` (mailable) sent on auto-link.

### Config / dependencies
- `composer require laravel/socialite`.
- `.env.example` additions:
  `GOOGLE_CLIENT_ID=`, `GOOGLE_CLIENT_SECRET=`, `GOOGLE_REDIRECT_URI=`,
  `GOOGLE_HOSTED_DOMAIN=`, `REGISTRATION_ALLOWLIST=`.
- Inertia shared prop `googleOAuthEnabled` (true when `GOOGLE_CLIENT_ID` set) via
  `HandleInertiaRequests`.

### Frontend
- **`resources/js/Components/GoogleLoginButton.vue`** — "Continue with Google"
  link → `route('auth.google.redirect')`, following Google's sign-in button
  branding and the repo's existing button conventions.
- Add to **`Login.vue`** and **`Register.vue`**, wrapped in
  `v-if="$page.props.googleOAuthEnabled"`, with an "or" divider.
- Surface callback flash errors using each page's existing error/status display.

## Security & edge cases

- **Verified email only** before any auto-link or create (read from raw payload).
- **Session fixation** — regenerate session after login.
- **Auto-link safety** — only by verified email; notify user on link.
- **`is_placeholder` users** — excluded from email lookup; never linked.
- **2FA** — Google login signs in directly; the 2FA challenge is not forced on the
  OAuth path (Google is the strong factor). Accepted behavior.
- **`hd` is not a security control** — server-side allowlist remains the gate.
- **Allowlist precedence** — `enable_registration=false` blocks all new signups
  regardless of allowlist; allowlist only further restricts when registration is on.
- **Unlink** — out of scope (no unlink UI). If added later, must prevent lockout
  (require a password or another login method before unlinking).

## Testing (PHPUnit, `Socialite::fake`)

Feature:
- New Google user → user + personal Organization created, logged in.
- Existing password user, same verified email → auto-linked, notified, logged in,
  no new org.
- Existing `oauth_connections` row → logged in directly.
- Unverified Google email → rejected (raw `email_verified=false`).
- Allowlist: blocked email + non-empty list → both Google **and** password
  registration rejected; empty list → allowed (fail-open); domain match works;
  existing user bypasses; `enable_registration=false` blocks Google signup.
- Invalid state / user-denied callback → graceful redirect with flash, no 500.
- Session ID changes after OAuth login.

Unit:
- `RegistrationAllowlist`: exact match, domain match, case-insensitivity, empty
  list (true), whitespace tolerance.

## Out of scope

- Other providers (GitHub/Microsoft) — table is provider-agnostic to allow later.
- Unlink/manage-connections UI.
- Storing/refreshing Google access tokens (we don't call Google APIs post-login).
- Per-org / DB-stored OAuth credentials.
