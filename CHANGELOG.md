# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.4 - 2026-11-25

### Fixed

- Fresh installs could never save Settings > Access — the first save
  always failed with "this record has been changed in the meantime"
  because the version-0 CAS token was rejected at the API boundary
- Group, user and document pickers showed blank rows and stored
  `undefined` instead of the real id — picks now display the proper
  name and save the correct gid/uid/file id
- Keyboard selection (Enter/Space) inside pickers works again —
  pointer-only selection was a WCAG 2.1.1 violation
- Viewers no longer see a document picker that can only error; viewer
  deep-links into the edit form redirect cleanly
- ICS feed: calendar name, events and descriptions are translated
- Turkish plural-form header corrected; zero-form plural arity fixed
  for tr/zh_*; missing plural msgids registered in l10n catalogs
- pt locale: doubled article ("o o seu perfil") healed
- Check-shell tokens: color-scheme pins restored

### Added

- Russian (ru) catalog

### Changed

- Store description rewritten and screenshots refreshed
- phpstan level-8 clean

## 1.0.3 - 2026-11-13

### Added
- Config & data transfer — encrypted test→prod pack
- Turkish locale; zh_CN and zh-Hant/zh_TW catalogs — complete translations
  across the app language set
- App Store screenshot set in info.xml

### Fixed
- Unique-index races translate to 409 CONFLICT, not 500
- App URLs routed through OC.generateUrl with index.php fallback
- Access door: deny-page recovery CTA no longer self-loops to the 403;
  honest allowlist copy + nav-track wallpaper guard
- Dialogs: margin:auto on pack dialog — the NC core reset no longer
  uncenters top-layer dialogs; ≤480 full-bleed selector; toast close armor
- Forms: select-matching control rule paints background-color (chevrons
  preserved), form field rhythm
- Transfer pack re-sync: schema cache, file-input specificity, sheet clip,
  positional t() interpolation

### Tests
- e2e: locale-agnostic login submit + canonical localhost baseURL
- e2e: occ-independent user provisioning fallback; serialized access-door spec
- e2e: rendered select-chevron contract (light + dark)
