# Changelog

All notable changes to this repository are documented here: after the first publication, each change of the list
(entry added, removed or edited) or of a test result gives a patch version, published automatically.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-14 - First release

### Added

- `data/apis.json` and `data/apis.csv`: every entry of the [public-apis](https://github.com/public-apis/public-apis) list, with its name, link, host, description, category, the `Auth`, `HTTPS` and `CORS` values declared by the list, and the result of the test of its link.
- Weekly test of each link: one `GET` request without credentials, seven possible results (`responds`, `access_denied`, `not_found`, `client_error`, `server_error`, `unreachable`, `not_tested`), HTTP code and date of the test.
- Observed availability: share of the last runs (8 at most, from 3) in which the link answered; `data/runs.csv` keeps the detail.
- `data/hosts.json` and `data/hosts.csv`: the list read by host (number of entries, categories, test result, entries that respond).
- `data/history.csv`: entries added and removed, changes of test result, entries discarded.
- `data/source.json`: address read, date of the reading, lines read and set aside, addresses tested, duration of the test, SHA-256 fingerprints of the file read and of the normalised data.
- `schema/apis.schema.json` and `schema/hosts.schema.json`: JSON schemas of the two main files.
- `LIST.md`: the entries by category with their test result, a page entirely generated; figures of the list generated in the README.
- `tools/actualiser.php`: a tool without any external library that reads the list, tests the links and rewrites the data; a collection can be recorded and replayed without network.
- Politeness of the test: one request per second at most per host, hosts taking turns, a second attempt only after a network error, a host that is down left alone.
- Safety of the test: `http` and `https` only, no IP address and no local or private address called, check made again at each redirect.
- No e-mail address published; entries discarded automatically when a value contains an IPv4 address, an API key pattern or a forbidden word.
- Safeguards: nothing is written when the list is empty or has changed shape, when fewer than 30 categories are read, when more than 20 % of the entries disappear, when more than 30 % of the addresses are unreachable or when more than 25 % of the test results change.
- `THIRD-PARTY-NOTICES.md`: copyright notice and MIT licence of the public-apis list.

[0.1.0]: https://github.com/polygonlab-ai/public-apis-health/releases/tag/v0.1.0

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
