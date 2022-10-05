# Changelog

## [6.0.1] - 2022-10-05

### Fixes
- Updated third party packages to mitigate potential security vulnerabilities.

## [6.0.0] - 2022-09-30

### Breaking changes
- The issuance event entries returned by the *retrieveAssetIssuanceHistory* method for non-fungible assets are different
  from the ones returned for regular (fungible) assets as per the new behavior of version 0.12 of the Catenis API. The
  observed differences are: the `amount` key is replaced by a new `nfTokenIds` key, which lists the IDs of the
  non-fungible tokens that have been issued; and the `holdingDevice` key is replaced by a new `holdingDevices`
  key, which lists the Catenis virtual devices to which the issued non-fungible tokens have been assigned.

### New features
- Added support for changes introduced by version 0.12 of the Catenis API: new non-fungible assets feature, including
  the new API methods Issue Non-Fungible Asset, Reissue Non-Fungible Asset, Retrieve Non-Fungible Asset Issuance
  Progress, Retrieve Non-Fungible Token, Retrieve Non-Fungible Token Retrieval Progress, Transfer Non-Fungible Token,
  and Retrieve Non-Fungible Token Transfer Progress.

## [5.0.0] - 2021-09-02

### Breaking changes
- The list of current asset holders returned by the *listAssetHolders* method may now include an entry that reports the
 total asset amount that is currently migrated to foreign blockchains as per the new behavior of version 0.11 of the
 Catenis API. That entry differs from the regular ones in that the `holder` key is missing and a new boolean type key
 named `migrated`, the value of which is always `true`, is present.

### New features
- Added support for changes introduced by version 0.11 of the Catenis API: new asset export feature, including the new
 API methods Export Asset, Migrate Asset, Asset Export Outcome, Asset Migration Outcome, List Exported Assets, and
 List Asset Migrations.

## [4.1.0] - 2020-07-02

### New features
- Added support for changes introduced by version 0.10 of the Catenis API: new public API method Retrieve
 Message Origin.

## [4.0.1] - 2020-01-23

### Changes
- Add missing information to header comment of logMessage() and readMessage() methods and their asynchronous counterpart.
- Update README file to fix sample code for retrieving information about a message's container.

## [4.0.0] - 2020-01-21

### Breaking changes
- When calling the *LogMessage* and *SendMessage* methods — and their asynchronous counterpart — without specifying a
 value for the new `off-chain` key of the *options* parameter, off-chain messages are used. Thus any existing code that
 uses those methods, when changed to use this new version of the Catenis API client — provided that the client is
 instantiated with its default API version (0.9) —, will produce a different result. To get the same behavior as before,
 the `off-chain` key of the *options* parameter needs to be set to ***false***.

### Changes
- The default version of the Catenis API (when instantiating the API client) is now set to 0.9.

### New features
- As a consequence for targeting version 0.9 of the Catenis API, the new features introduced by that version
 are supported: log, send, read and retrieve container info of Catenis off-chain messages.

## [3.0.1] - 2019-08-24

### Fixes
- Query string parameters of type boolean with a false value is correctly formatted.

## [3.0.0] - 2019-08-20

### Breaking changes
- The `countExceeded` key of the associative array returned from a successful call to the *listMessages* method has been
 replaced with the new `hasMore` key.
- The `countExceeded` key of the associative array returned from a successful call to the *retrieveAssetIssuanceHistory*
 method has been replaced with the new `hasMore` key.

### Changes
- Changed interface of *listMessages* method: first parameter renamed to `selector`; new parameters `limit` and `skip` added.
- Changed interface of *retrieveAssetIssuanceHistory* method: new parameters `limit` and `skip` added.

### New features
- Added options (when instantiating API client) to send/receive compressed data, which is on by default.
- Added support for changes introduced by version 0.8 of the Catenis API: "pagination" (limit/skip) for API
 methods List Messages and Retrieve Asset Issuance History; new URI format for notification endpoints.

## [2.1.3] - 2019-06-22

### Fixes
- Avoid that CPU be over allocated (CPU time ~ 100%) when automatically running the promise task queue,
 which is the default.

### Changes
- New `pumpInterval` option used to set the interval for periodically running the promise task queue
 when instantiating the Catenis API PHP client.

## [2.1.2] - 2019-06-18

### Fixes
- Avoid that JSON payload of API method calls include escaped characters.

## [2.1.1] - 2019-06-10

### Changes
- Reference updated version of dependency package ratchet/pawl, which includes a fix to correctly use HTTPS scheme for secure connections.

## [2.1.0] - 2019-05-31

### New features
- WebSocket notification channel object emits new `open` event.

## [2.0.0] - 2019-05-02

### Breaking changes
- Changed interface of method *sendMessage*: parameters `message` and `targetDevice` have swapped positions.
- The associative array returned from a successful call to the *readMessage* method has a different structure.

### New features
- Added support for version 0.7 of the Catenis API: log, send and read message in chunks.

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
