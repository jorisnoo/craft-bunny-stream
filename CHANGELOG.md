# Changelog

## [Unreleased]

## [1.0.0](https://github.com/jorisnoo/craft-bunny-stream/releases/tag/v1.0.0) (2026-05-12)

### Features

- add thumbnail blurhash accessor ([b4b9e32](https://github.com/jorisnoo/craft-bunny-stream/commit/b4b9e32fab2fe7ee65a6fc1f09482e3989a228f1))
- add bootstrap option and automated metadata refresh with exponential backoff ([a674cb3](https://github.com/jorisnoo/craft-bunny-stream/commit/a674cb36880be5c8ce8bfc92e7152248b53f1456))
- add binary video upload support to Bunny Stream API ([e0a58f7](https://github.com/jorisnoo/craft-bunny-stream/commit/e0a58f7e49cd629fe9f05c21bef0ba1f74e43d19))
- add preload metadata option to Bunny Stream embeds ([ff4f205](https://github.com/jorisnoo/craft-bunny-stream/commit/ff4f20541073aa9f4dfaf90eb83757450c2bbcdc))
- add Bunny Stream embed URL and iframe generation ([656f027](https://github.com/jorisnoo/craft-bunny-stream/commit/656f0278acec870290b077207b7f160d79cf6a06))
- load bunny stream settings from environment variables in init method ([52288e3](https://github.com/jorisnoo/craft-bunny-stream/commit/52288e3b38d881bb360ea37ee05a1eb0a5db391b))
- add asset preview handler and sidebar player for Bunny Stream videos ([f3f218f](https://github.com/jorisnoo/craft-bunny-stream/commit/f3f218fcac00dfd73fd4d8a4c80aa4b51d4c60e0))
- add console command to sync asset metadata ([07e7ca2](https://github.com/jorisnoo/craft-bunny-stream/commit/07e7ca28a38b2d47567d28d68f5ed1ca75dcb79e))
- add install migration for namespace refactor ([ec8a21e](https://github.com/jorisnoo/craft-bunny-stream/commit/ec8a21ea088bac1a008910f2adc7953a04890073))
- upgrade to Craft 5 ([8f5b3ad](https://github.com/jorisnoo/craft-bunny-stream/commit/8f5b3ade92febe75cc9ba4dae97161ea227ed3c6))
- add relative thumbnail url ([9b2dec3](https://github.com/jorisnoo/craft-bunny-stream/commit/9b2dec331552896c3258beb6d933629cb84239b2))
- add thumb ([199ad1c](https://github.com/jorisnoo/craft-bunny-stream/commit/199ad1c4f209300b28b9119238975db9e71ffbd7))
- throw errors ([0bba1ff](https://github.com/jorisnoo/craft-bunny-stream/commit/0bba1ff8eff5101ec276b9999d84fe496756b433))
- update bunny data when resaving ([afbb339](https://github.com/jorisnoo/craft-bunny-stream/commit/afbb3396c9f2217d19a06bf7018244b90a1f595f))
- get thumb url ([70403ee](https://github.com/jorisnoo/craft-bunny-stream/commit/70403eebb9a9f65c08410076c51b8859acd2b2b9))
- set thumbnail to first frame ([c4bf607](https://github.com/jorisnoo/craft-bunny-stream/commit/c4bf6071c02c6eb45c1920d0d7a83f1c7c8a958f))
- add aspect ratio and hls url to asset behaviour ([ffa37e9](https://github.com/jorisnoo/craft-bunny-stream/commit/ffa37e9e0ae184b230f13d350ec1f904b3b55f4f))

### Bug Fixes

- relative path ([b4b5ac8](https://github.com/jorisnoo/craft-bunny-stream/commit/b4b5ac883faabb50df24e356970dfde53743070d))
- urls ([cb16614](https://github.com/jorisnoo/craft-bunny-stream/commit/cb166143807c8ee33366886899c3af604b1ed0a9))
- update after webhook ([675c53a](https://github.com/jorisnoo/craft-bunny-stream/commit/675c53a07d7010a090e91d4afe235618234719ce))
- resave bug ([a43e6f1](https://github.com/jorisnoo/craft-bunny-stream/commit/a43e6f1a09b2051b8eba9b4162cdc9e5a6cb3c1b))
- one more check ([0829c32](https://github.com/jorisnoo/craft-bunny-stream/commit/0829c32bb90e053fe12869479dd926d28ef5aaf0))
- do not send from dev env ([7114937](https://github.com/jorisnoo/craft-bunny-stream/commit/711493750b6c774e10f81d9d98726f98380a2a9d))
- try preventing sending temp urls ([19d3397](https://github.com/jorisnoo/craft-bunny-stream/commit/19d33978c5e238fa5c6d762f6d44c6ea103b1ad3))
- typo ([98e2b67](https://github.com/jorisnoo/craft-bunny-stream/commit/98e2b677d9581c4a201eaf04d80d084f348a7f92))
- cdn hostname setting ([faf8ec7](https://github.com/jorisnoo/craft-bunny-stream/commit/faf8ec74257717bbd140efec62e53b910d0a0cc3))
- status check ([abca5c4](https://github.com/jorisnoo/craft-bunny-stream/commit/abca5c4ba3d6239f6573b942722f698dfc997b34))
- field html ([c53844d](https://github.com/jorisnoo/craft-bunny-stream/commit/c53844dd2a72857f695ae5e2ac6b22603ab8b8e6))
- try to fix db parse error ([09fbde8](https://github.com/jorisnoo/craft-bunny-stream/commit/09fbde882346c45c2039ce7da3a7b531f976793a))
- update data after webhook received ([71ce0b4](https://github.com/jorisnoo/craft-bunny-stream/commit/71ce0b4362c3b286a6deb74333de0d87b362c499))
- api response is object not array ([8a5e14d](https://github.com/jorisnoo/craft-bunny-stream/commit/8a5e14d4b7a25894a9e83c656f414daca3d30cbc))

### Code Refactoring

- use VideoStatus enum for type-safe status handling ([6064f6f](https://github.com/jorisnoo/craft-bunny-stream/commit/6064f6f56265da4e701ac25185b751ad227dc8c0))
- simplify dependabot auto-merge workflow and add composer.lock to gitignore ([3db2e70](https://github.com/jorisnoo/craft-bunny-stream/commit/3db2e706d3a6e015a7b71035231067199aacc6be))
- simplify code and remove debug statements ([7ee92ae](https://github.com/jorisnoo/craft-bunny-stream/commit/7ee92aef522c13f48d221ea3fb5a7cc590d5b214))
- simplify BunnyStream field attribute names and add column migration ([c0e174b](https://github.com/jorisnoo/craft-bunny-stream/commit/c0e174b0187d3a74b319021f93cbbbfeb96af93f))
- remove underscore prefix from plugin handle ([88a598a](https://github.com/jorisnoo/craft-bunny-stream/commit/88a598aeef91f929c4a78c9626353d5c22d0246c))
- modernise codebase and upgrade bunnynet-php to v9 ([1efce0b](https://github.com/jorisnoo/craft-bunny-stream/commit/1efce0b60607653c68921ebdc8042f56510bc30a))
- refactoring blindly ([a65aa11](https://github.com/jorisnoo/craft-bunny-stream/commit/a65aa1193dd9f1b21d0d768a6dad4bbc3e483be0))
- bunny client ([036ed79](https://github.com/jorisnoo/craft-bunny-stream/commit/036ed796dc5d9528223346eda2365bac9ff92bd1))

### Documentation

- expand README with installation, configuration, setup, and API documentation ([cea1ea7](https://github.com/jorisnoo/craft-bunny-stream/commit/cea1ea76da767ce0e97ff3d78f6f39d6f8f82ffc))

### Build System

- composer upgrade ([16b07d5](https://github.com/jorisnoo/craft-bunny-stream/commit/16b07d5c5df6ed3b7004b7c27cb7316ad29882b7))

### Continuous Integration

- replace ci workflow with dependabot auto-merge ([12ee4d2](https://github.com/jorisnoo/craft-bunny-stream/commit/12ee4d22e349dc7ed8491e482bdbefc393cb9310))

### Chores

- add migration for field type namespace update ([14b88ae](https://github.com/jorisnoo/craft-bunny-stream/commit/14b88ae1f9fab86a466b11ed65703d5a7f4bd245))
### Breaking Changes

- Requires Craft CMS 5.0+
- Requires PHP 8.2+
