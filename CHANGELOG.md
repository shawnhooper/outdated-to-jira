# Changelog

## [1.2.0](https://github.com/shawnhooper/outdated-to-jira/compare/v1.1.2...v1.2.0) (2025-04-23)


### Features

* **pip:** Add pip support ([6f1fbff](https://github.com/shawnhooper/outdated-to-jira/commit/6f1fbffeb18ef89ee1bcf8c19d31184df40b8aea))


### Miscellaneous Chores

* fixing phpstan issues ([8043808](https://github.com/shawnhooper/outdated-to-jira/commit/804380842b7516e218d45366cf7a6d0b5e70a6e6))
* linting ([08087bc](https://github.com/shawnhooper/outdated-to-jira/commit/08087bcbc6a4810cf89f8fff2c53a3d385dc8025))

## [1.1.2](https://github.com/shawnhooper/outdated-to-jira/compare/v1.1.1...v1.1.2) (2025-04-22)


### Bug Fixes

* **npm:** Silently skip entries missing "current" version in parser ([f5869d6](https://github.com/shawnhooper/outdated-to-jira/commit/f5869d6ae4d60188ad262416b59a7a867e358a35))
* **npm:** Silently skip entries missing "current" version in parser ([743e03c](https://github.com/shawnhooper/outdated-to-jira/commit/743e03c9dafe99d522cc355700aef3829576e8b7))

## [1.1.1](https://github.com/shawnhooper/outdated-to-jira/compare/v1.1.0...v1.1.1) (2025-04-22)


### Bug Fixes

* Fixed example GitHub workflow in README

## [1.1.0](https://github.com/shawnhooper/outdated-to-jira/compare/v1.0.0...v1.1.0) (2025-04-22)


### Features

* **CLI:** Added CLI Support ([8565dfd](https://github.com/shawnhooper/outdated-to-jira/commit/8565dfd5fdf6a1a4fb958ebc9f4070be6faa63a0))


### Bug Fixes

* Add missing bin file ([a914b6b](https://github.com/shawnhooper/outdated-to-jira/commit/a914b6b952f1af348745eee15d61e2718bc65807))
* build composer at minimum version ([b70d147](https://github.com/shawnhooper/outdated-to-jira/commit/b70d1476938bda889773e980523f0c563ca058c2))
* install PHP in docker container ([010c328](https://github.com/shawnhooper/outdated-to-jira/commit/010c328fec294f11dc527dbaceed46d1b0272efb))
* php 8.2 build ([f8e1a8c](https://github.com/shawnhooper/outdated-to-jira/commit/f8e1a8c08c74233e1df4aa8e02246e3163ce2d1b))

## 1.0.0 (2025-04-21)


### Features

* **action:** Convert script to reusable Docker GitHub Action ([6405ca8](https://github.com/shawnhooper/outdated-to-jira/commit/6405ca89ce88a059cc043de3c35afd039bcd4de3))
* Add .env.example template ([6e251e0](https://github.com/shawnhooper/outdated-to-jira/commit/6e251e0669857ec450fbd182dfedb7fa39a09d4a))
* Add .env.example template ([3387d14](https://github.com/shawnhooper/outdated-to-jira/commit/3387d1482b09aeff9c937fb491b85aeb808c5e08))
* **ci:** Add linting (PHPCS) and static analysis (PHPStan) ([882b73b](https://github.com/shawnhooper/outdated-to-jira/commit/882b73b3c1ce8c11c808e1090fc8e1b4762ade09))
* **jira:** Set ticket priority based on SemVer level ([441d3d0](https://github.com/shawnhooper/outdated-to-jira/commit/441d3d07f6cedb2ce1e6d6f8530c8f7ed46e17a8))


### Bug Fixes

* composer lock version ([a3b9839](https://github.com/shawnhooper/outdated-to-jira/commit/a3b9839179b0b3e3b03e39bc16965aaaa103501b))
* deperecation bugs ([350f174](https://github.com/shawnhooper/outdated-to-jira/commit/350f1749f0d5e49e12105e8867d4634aba4087d5))
* dotenv no longer used ([6081683](https://github.com/shawnhooper/outdated-to-jira/commit/6081683814aa14dc712c63463bb3061dff3a6471))
* downgrade phpunit requirement so php 8.2 still works ([ca8749f](https://github.com/shawnhooper/outdated-to-jira/commit/ca8749f7c3c4b841052e8a1336af02ff15eab38c))
* monolog version in composer ([bc2a623](https://github.com/shawnhooper/outdated-to-jira/commit/bc2a62394d5854fede35e3ad062beefc6a7f20e3))
* **parser:** Adapt Composer parser for actual output format ([67bf022](https://github.com/shawnhooper/outdated-to-jira/commit/67bf02208bc8991a3c2b5031403b013ecaacfa16))
* phpcbf automatic fixes ([1f14912](https://github.com/shawnhooper/outdated-to-jira/commit/1f14912897fed8931ccee690b2afc517b06ae12c))
* remove redundant check ([dd26587](https://github.com/shawnhooper/outdated-to-jira/commit/dd26587fd0743acb6b17f32f80ad9f28bbb764e8))


### Miscellaneous Chores

* add gitignore ([4390047](https://github.com/shawnhooper/outdated-to-jira/commit/4390047df2882c7b9165093dd7a86ecdbca923ad))
* code refactoring' ([78af4d1](https://github.com/shawnhooper/outdated-to-jira/commit/78af4d166ff5d4157695cdfad8b794bb95346099))
* fix workflow version ([24bcd2b](https://github.com/shawnhooper/outdated-to-jira/commit/24bcd2b4060734dd81781bc169746c17bab57ba9))
* reformatting long lines ([0a8c66a](https://github.com/shawnhooper/outdated-to-jira/commit/0a8c66a11d719aece4f91306cc155c687a2a6571))
