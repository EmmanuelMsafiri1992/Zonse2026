# Zonseob Mobile App — Setup Guide

## Prerequisites

- Flutter 3.19+ (`flutter --version`)
- Dart 3.3+
- For Android: Android Studio + Android SDK 21+
- For iOS: Xcode 15+ (macOS only)

## 1. Install dependencies

```bash
cd mobile
flutter pub get
```

## 2. Configure the backend URL

Open `lib/core/api/api_client.dart` and update `_defaultBaseUrl` to point at your server:

```dart
const _defaultBaseUrl = 'https://app.zonseob.com';  // ← change this
```

For local development use your machine's LAN IP (not `localhost` — the device cannot reach it):
```dart
const _defaultBaseUrl = 'http://192.168.1.x:8000';
```

## 3. Backend changes (already in the repo)

Two new public endpoints were added to `routes/api.php`:

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/mobile/workspaces` | Verify credentials → list of workspaces |
| POST | `/api/mobile/token` | Pick workspace → returns Bearer token |
| DELETE | `/api/mobile/token` | Logout (revokes token) |

The token is a standard Sanctum API key with `read` + `write` abilities, identical to those created in Settings → API & Webhooks. All existing `/api/v1/*` routes work with it.

## 4. Run the app

```bash
# Android
flutter run

# iOS (macOS only)
flutter run -d ios

# Release build
flutter build apk --release         # Android APK
flutter build appbundle --release   # Android AAB (Play Store)
flutter build ios --release         # iOS (needs Xcode)
```

## App modules

| Module | Features |
|--------|----------|
| Dashboard | Summary stats for all modules |
| Contacts | List, search, view, create, edit |
| Tasks | List by status, create, edit, quick status toggle |
| Appointments | List, filter by status/date |
| Invoices | List, filter, full detail with line items |
| Helpdesk | List tickets, view, create, update status |
| Profile | User info, workspace info, sign out |

## Authentication flow

1. User enters email + password → app calls `/api/mobile/workspaces`
2. If the user has one workspace, the app calls `/api/mobile/token` automatically
3. If the user has multiple workspaces, they pick one on a selection screen
4. Token is stored securely (Android Keystore / iOS Keychain) via `flutter_secure_storage`
5. All API calls send `Authorization: Bearer <token>`
