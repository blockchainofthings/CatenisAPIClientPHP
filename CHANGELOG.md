# Changelog

## [2.1.1] - 2019-06-10

### Changes
- Reference updated version of dependency package ratchet/pawl, which includes a fix to correctly use HTTPS scheme for secure connections.

## [2.1.0] - 2019-05-31

### New features
- WebSocket notification channel object emits new `open` event.

## [2.0.0] - 2019-05-02

### Breaking changes
- Changed interface of methods *sendMessage* and *sendMessageAsync*: parameters `message` and `targetDevice` have swapped positions.

### New features
- Added support for version 0.7 of the Catenis Enterprise API.

## [1.0.3] - 2019-01-02

### Changes
- Changed the wording of the library title.
- Made adjustments and corrections to the sample code shown in the README file.

## [1.0.2] - 2018-12-31

### Fixes
- Update dependency package ratchet/pawl to fix issue with authenticating Catenis (WebSocket) notification connections
 on the sandbox environment.

## [1.0.1] - 2018-12-29

### Changes
- Added missing LICENSE file.

## [1.0.0] - 2018-12-07

### New features
- Initial version of the library.
