```
       ▄▄▀▀▄▄        ____       _                         _          _
    ▄▀▀  ▄▄  ▀▀▄    |  _ \ ___ | |_   _  __ _  ___  _ __ | |    __ _| |__
    █  ▄▀  ▀▄  █    | |_) / _ \| | | | |/ _` |/ _ \| '_ \| |   / _` | '_ \
    █  ▀▄  ▄▀  █    |  __/ (_) | | |_| | (_| | (_) | | | | |__| (_| | |_) |
    ▀▄▄  ▀▀  ▄▄▀    |_|   \___/|_|\__, |\__, |\___/|_| |_|_____\__,_|_.__/
       ▀▀▄▄▀▀                     |___/ |___/
                                   polygonlab.ai
```

# public-apis-health

> Weekly health check of every link in the public-apis list of free APIs, in JSON and CSV, with history.

[![CI](https://github.com/polygonlab-ai/public-apis-health/actions/workflows/ci.yml/badge.svg)](https://github.com/polygonlab-ai/public-apis-health/actions/workflows/ci.yml)
[![Version](https://img.shields.io/github/v/release/polygonlab-ai/public-apis-health)](https://github.com/polygonlab-ai/public-apis-health/releases)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

## Source and credit

The list of APIs published here comes from [public-apis/public-apis](https://github.com/public-apis/public-apis),
a collective list of free APIs maintained by its community and distributed under the MIT licence
(Copyright (c) 2022 public-apis). The names, links, descriptions, categories and the `Auth`, `HTTPS` and `CORS`
values are theirs; the copyright notice and the licence text are reproduced in
[`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

This repository is **not affiliated with, nor endorsed by,** the authors of public-apis. It adds one thing only:
**the result of a weekly test of each link**. To add an API or to correct an entry, open a pull request on the
original repository, not here: the change will arrive in this repository at the next weekly run.

**PolygonLab does not recommend any API and does not guarantee that they work.**

## Why

The public-apis list names more than two thousand free APIs, but nothing in it says which ones are still alive.
Links die quietly: a domain expires, a project is abandoned, a documentation page moves.

This repository brings three things:

1. **the list as data**, in JSON (with its schema) and in CSV, one entry per link;
2. **the result of a weekly test of each link**: does it respond, does it refuse access, is it not found or
   unreachable?;
3. **a history**: entries added and removed, and every change of test result.

The list is read and tested **every Thursday**; a version is published as soon as an entry is added, removed,
edited, or changes test result.

**Read this before using the results:** in the list, the link of an entry is most often the **documentation page**
of the API, not the address its clients call. The test therefore says "the link given by the list responds", and
nothing more.

## Features

- Every entry of the list, without selection, with its name, link, host, description, category and the `Auth`,
  `HTTPS` and `CORS` values declared by the list.
- Test of each link, once a week: seven possible results, HTTP code, date of the test.
- Observed availability: share of the last runs in which the link answered.
- A reading by host: number of entries, categories, test result.
- Dated history, and traceability of the source (address read, date, SHA-256 fingerprints).
- JSON schemas of the two main files, checked at each publication.
- A page readable on GitHub, [`LIST.md`](LIST.md): the entries by category, with their test result.
- An update tool in PHP, without any external library, usable by anyone.
- No e-mail address published.

## The list in figures

Figures generated at each version, from `data/apis.json`. The entries by category are in [`LIST.md`](LIST.md).

<!-- list:start -->
List read and tested on 2026-11-14: 2379 APIs on 2149 hosts, in 51 categories. Full files: [`data/apis.json`](data/apis.json) and [`data/apis.csv`](data/apis.csv). List by category: [`LIST.md`](LIST.md).

| Test result | Value | APIs |
|---|---|---:|
| Responds | `responds` | 2012 |
| Access denied | `access_denied` | 138 |
| Not found | `not_found` | 59 |
| Client error | `client_error` | 17 |
| Server error | `server_error` | 18 |
| Unreachable | `unreachable` | 135 |
| Not tested | `not_tested` | 0 |

| Category | APIs | Responding |
|---|---:|---:|
| Animals | 24 | 22 |
| Anime | 20 | 17 |
| Anti-Malware | 17 | 14 |
| Art &amp; Design | 33 | 26 |
| Authentication &amp; Authorization | 6 | 6 |
| Blockchain | 19 | 17 |
| Books | 28 | 24 |
| Business | 54 | 46 |
| Calendar | 24 | 20 |
| Cloud Storage &amp; File Sharing | 20 | 18 |
| Continuous Integration | 6 | 6 |
| Cryptocurrency | 108 | 81 |
| Currency Exchange | 33 | 30 |
| Data Validation | 17 | 17 |
| Development | 241 | 214 |
| Dictionaries | 17 | 13 |
| Documents &amp; Productivity | 64 | 57 |
| Email | 47 | 43 |
| Entertainment | 19 | 16 |
| Environment | 35 | 28 |
| Events | 6 | 5 |
| Finance | 110 | 98 |
| Food &amp; Drink | 36 | 28 |
| Games &amp; Comics | 115 | 93 |
| Geocoding | 116 | 106 |
| Government | 137 | 112 |
| Health | 52 | 44 |
| Jobs | 47 | 35 |
| Machine Learning | 59 | 49 |
| Music | 37 | 30 |
| News | 26 | 22 |
| Open Data | 94 | 86 |
| Open Source Projects | 11 | 10 |
| Patent | 5 | 4 |
| Personality | 27 | 19 |
| Phone | 10 | 10 |
| Photography | 40 | 31 |
| Programming | 6 | 5 |
| Science &amp; Math | 59 | 53 |
| Security | 66 | 52 |
| Shopping | 35 | 30 |
| Social | 62 | 57 |
| Sports &amp; Fitness | 75 | 65 |
| Test Data | 37 | 25 |
| Text Analysis | 20 | 14 |
| Tracking | 11 | 10 |
| Transportation | 101 | 84 |
| URL Shorteners | 21 | 14 |
| Vehicle | 20 | 17 |
| Video | 61 | 47 |
| Weather | 45 | 42 |

<!-- list:end -->

## Installation

Nothing to install to read the data: the files can be downloaded straight from GitHub.

| File | Direct address |
|---|---|
| APIs (JSON) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/apis.json` |
| APIs (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/apis.csv` |
| Hosts (JSON) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/hosts.json` |
| Hosts (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/hosts.csv` |
| History (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/history.csv` |
| Last runs (CSV) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/runs.csv` |
| Source (JSON) | `https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/source.json` |

To pin a version, replace `main` with a tag, for example `v0.1.0`.

## Quick start

```php
<?php

declare(strict_types=1);

// List published on GitHub (or a local copy given as an argument).
$address = $argv[1] ?? 'https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/apis.json';
/** @var array{count: int, host_count: int, source_date: string, apis: list<array{name: string, url: string, category: string, auth: ?string, test: array{result: string, http_code: ?int}}>} $list */
$list = json_decode((string) file_get_contents($address), true, 16, JSON_THROW_ON_ERROR);

printf("%d APIs on %d hosts, list read and tested on %s\n", $list['count'], $list['host_count'], $list['source_date']);

// The APIs that need no authentication and whose link answered the last test.
foreach ($list['apis'] as $api) {
    if ($api['auth'] === 'No' && $api['test']['result'] === 'responds') {
        printf("%s (%s): %s\n", $api['name'], $api['category'], $api['url']);
    }
}
```

Expected output (with a sample list):

```text
3 APIs on 3 hosts, list read and tested on 2026-11-19
Sample Cat Facts (Animals): https://cats.example.com/
```

## Usage

**On the command line**, with `curl` and `jq`: the entries whose link no longer responds.

```bash
curl -s https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/apis.json \
  | jq -r '.apis[] | select(.test.result == "unreachable" or .test.result == "not_found") | "\(.category): \(.name) \(.url)"'
```

**In a spreadsheet**: open `data/apis.csv` or `data/hosts.csv` (UTF-8 without BOM, comma separator).

**Follow the changes**: `data/history.csv` only grows. Its last lines say what has changed since your last reading.

```bash
curl -s https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/history.csv | tail -n 20
```

**Be notified**: on GitHub, "Watch", then "Custom" and "Releases": each change of the list or of a test result gives
a version.

**Report a problem**: open an issue with the link of the entry (`url`) and what is wrong. A wrong name, link or
description is corrected at the source, in the public-apis repository: the correction arrives here at the next
run. A test result that looks wrong to you interests us: say from where and when you tried.

## What the test measures

Once a week, the tool sends **one single request** to each distinct address, then puts the response in a class:

| Result | What happened |
|---|---|
| `responds` | The address answered without error (HTTP codes 2xx and 3xx) |
| `access_denied` | The server asks for credentials or forbids access (401, 403) |
| `not_found` | The server does not know this address (404, 410) |
| `client_error` | The server refuses the request for another reason (other 4xx codes) |
| `server_error` | The server is in error (5xx codes) |
| `unreachable` | No response: name not found, connection refused, certificate refused, timeout |
| `not_tested` | No call made: invalid address, IP address, local or private host |

**To be read honestly:**

- `responds` means that the link answered without error, **not that the API works**. The link is most often a
  documentation page: the page can be online while the API behind it is closed, and the reverse.
- Some sites refuse automated requests: a link that opens in your browser can be `access_denied` or `client_error`
  here.
- The test is made **once a week, from one single server located in France**. An outage of a few hours, a block by
  country or a rate limit can give a result different from yours.
- The response time is not published: it changes at each run and would say nothing reliable.
- `auth`, `https` and `cors` are the values **declared by the list**. They are neither checked nor measured here.

**The request**: `GET`, without credentials or authentication header, without cookie, timeout of 10 seconds,
3 redirects at most, body received up to 64 KB then dropped. The tool introduces itself with its `User-Agent`
header: `PolygonLab-actualisation-donnees (+https://github.com/polygonlab-ai/public-apis-health)`. The anchor of a
link (`#section`) is not sent: two links that differ only by their anchor share one request.

**Politeness**: one request per second at most to a given host, never two at the same time; the hosts take turns.
One attempt per address, plus a second one after 5 seconds on a network error only. After three addresses in a row
without any response on a given host, the remaining addresses of that host are classed `unreachable` without being
called: the tool does not insist on a server that is down.

**Safety**: only `http` and `https` addresses are called, on any port. The tool never calls an IP address, a local
name, or a name that resolves to a local, private or link-local address; the check is made again at each redirect,
and the connection is made to the checked address, without any proxy. These addresses are classed `not_tested`.

**Observed availability** (`test.observed_availability`) is the share of the last recorded runs (8 at most, see
`data/runs.csv`) in which the link answered, that is `responds` or `access_denied`. It goes from 0 to 1 and is
`null` as long as the link has not been tested in 3 runs. A run is recorded only when it gives a version: a week
in which nothing changes adds no run.

## How the list is read

1. **Source**: the `README.md` file of the public-apis repository, branch `master`, downloaded once per run.
2. **What is read**: the part placed after the `## Index` heading, one category per `###` heading, one entry per
   line of its table (`API`, `Description`, `Auth`, `HTTPS`, `CORS`). The sections placed before the index
   (announcements, other tables) are not taken.
3. **Key of an entry**: its link, normalised (protocol and host in lower case, default port removed, empty path
   written `/`). Two entries may share a name; two lines with the same link are one entry: the first is kept, the
   other is listed in `data/source.json`.
4. **Declared values**: `auth`, `https` and `cors` are kept as written in the list, without the backticks. An
   unexpected value is kept as it is and counted in the summary of the version.
5. **Lines set aside**: a line that cannot be read (unexpected number of cells, no link, link that is not an `http`
   or `https` address) is set aside and listed in `data/source.json`, without stopping the reading. Empty cells in
   excess at the end of a line are ignored.
6. **E-mail addresses**: none is published. In a text, it is replaced by `[address removed]`.
7. **Discarded automatically**: an entry in which a published value contains an IPv4 address, an API key or token
   pattern, a text between double braces, or a word of the list of forbidden words kept by PolygonLab. It is noted
   `discarded` in the history; if its name or its link is the problem, it appears there as `[name withheld xxxxxxxx]`
   or `[url withheld xxxxxxxx]`.
8. **Safeguards**: the tool stops without writing anything when the list is empty, when the `## Index` heading or
   a table header is not the expected one, when a category has no readable line, when fewer than 30 categories are
   read, when more than 20 % of the entries disappear at once, when more than 30 % of the addresses are unreachable,
   or when more than 25 % of the test results change from one run to the next (probable failure on our side).

The other values are those of the list, unchanged, except for extra spaces. Dates are given to the day (Paris time).

## File formats

### `data/apis.json`

Schema: [`schema/apis.schema.json`](schema/apis.schema.json).

| Field | Type | Content |
|---|---|---|
| `source` | text | `public-apis/public-apis` |
| `source_page` | text | Page of the original repository |
| `source_date` | date or null | Date of the run (`YYYY-MM-DD`, Paris time) |
| `count` | integer | Total number of entries |
| `counts` | object | Number of entries per category (`by_category`) and per test result (`by_test_result`) |
| `host_count` | integer | Number of distinct hosts |
| `apis` | list | Entries, sorted by category, name, then link |

Each entry:

| Field | Type | Content |
|---|---|---|
| `name` | text | Name given by the list |
| `url` | text | Link given by the list, normalised; unique, it is the key of the entry |
| `host` | text | Host of the link, in lower case |
| `description` | text or null | Description given by the list, 300 characters at most |
| `category` | text | Category of the list |
| `auth` | text or null | `Auth` value declared by the list: `No`, `apiKey`, `OAuth`, `X-Mashape-Key`, `User-Agent` |
| `https` | text or null | `HTTPS` value declared by the list: `Yes` or `No` |
| `cors` | text or null | `CORS` value declared by the list: `Yes`, `No` or `Unknown` |
| `test` | object | `result` (see "What the test measures"), `http_code` (or null), `tested_on` (date of the run, null if the link was not tested), `observed_availability` (from 0 to 1, or null) |

### `data/apis.csv`

The same entries, one per line, in the same order and with the same fields; those of the test become the columns
`test_result`, `test_http_code`, `tested_on` and `observed_availability`. UTF-8 without BOM, separator `,`, values
between double quotes only when needed.

### `data/hosts.json` and `data/hosts.csv`

Schema: [`schema/hosts.schema.json`](schema/hosts.schema.json). One entry per host, from the most to the least
loaded: `host`, `api_count`, `categories` (sorted; separated by ` | ` in the CSV), `result` and `responding_count`.
The result of a host is the most favourable one among its entries (in the order `responds`, `access_denied`,
`client_error`, `not_found`, `server_error`, `unreachable`, `not_tested`): it says whether the server is alive, not
whether all its pages are. `responding_count` counts its entries whose result is `responds`.

### `data/history.csv`

One line per event: `date` (day of the run), `event` (`added`, `removed`, `test_result_changed`, `discarded`),
`url`, `name`, `detail`. The file only grows.

### `data/runs.csv`

Test result of each entry in the last recorded runs (8 at most): `date`, `url`, `result`. It is the basis of the
observed availability.

### `data/source.json`

Where the data come from: `source`, `source_page`, `source_date`, the date and time of the reading (`lu_le`) and one
entry in `fichiers` for the file read, with its address (`url`), the SHA-256 fingerprint and the size of the file
downloaded (`source_sha256`, `source_size`), the number of categories and lines read, of lines set aside, of
duplicate links, of entries read and discarded, of addresses tested and of requests, the duration of the test in
seconds, and the list of the lines set aside (`set_aside`: line number in the source, reason, name). `sha256` is the
fingerprint of the normalised data of the run (entries and test results, without dates or HTTP codes): a new
version is published only when it changes. The names `lu_le`, `fichiers` and `cle` are shared with the other
PolygonLab data repositories.

## Configuration

The update tool rebuilds the data from the public-apis list, without any library to install:

```bash
php tools/actualiser.php
```

| Option | Type | Default | Role |
|---|---|---|---|
| `--collect <directory>` | path | none | Collection only: files written to this directory (with `--manifest`) |
| `--manifest <file.json>` | path | none | With `--collect`: manifest to write; alone: collection already made, to use |
| `--data <directory>` | path | `data/` | Directory of the data to update |
| `--readme <file.md>` | path | `README.md` | README whose figures are rewritten |
| `--list <file.md>` | path | `LIST.md` | Page of the list by category |
| `--summary <file.json>` | path | none | Summary of the changes, as JSON |
| `--forbidden-words <file.json>` | path | none | Words that discard an entry: `{"mots": [{"mot": "...", "tolerees": ["..."]}]}` |
| `--sources <file.json>` | path | `tools/sources.json` | Settings file to use |
| `--replay <directory>` | path | none | Replays a recorded collection (list and tests), without network |
| `--record <directory>` | path | none | Records the list read and the tests, to replay them with `--replay` |
| `--loader <file.php>` | path | `vendor/autoload.php` | Autoloader to use instead of the one of Composer |

The options `--collecter`, `--manifeste`, `--resume`, `--mots-interdits` and `--chargeur` are accepted as other
names of `--collect`, `--manifest`, `--summary`, `--forbidden-words` and `--loader`: they are the names used by the
publishing script shared by the PolygonLab data repositories.

`tools/sources.json` sets the reading of the list and the test:

| Key | Value | Role |
|---|---|---|
| `list_url` | `https://raw.githubusercontent.com/public-apis/public-apis/master/README.md` | File read |
| `attempts` | 3 | Attempts to download the file (pauses of 5 then 15 seconds) |
| `timeout_s` | 30 | Timeout of the download (seconds) |
| `max_size` | 5000000 | Maximum size of the file (bytes) |
| `min_categories` | 30 | Minimum number of categories: below, the reading stops without writing anything |
| `max_drop_pct` | 20 | Maximum share of the entries that may disappear at once |
| `test.timeout_s` | 10 | Timeout of a test request (seconds) |
| `test.max_redirects` | 3 | Redirects followed |
| `test.max_body_size` | 65536 | Bytes received from the body of a response |
| `test.host_interval_s` | 1 | Interval between two requests to a given host (seconds) |
| `test.retry_pause_s` | 5 | Pause before the second attempt, after a network error (seconds) |
| `test.concurrency` | 8 | Requests in progress at the same time, to different hosts |
| `test.max_consecutive_failures` | 3 | Addresses in a row without response before a host is no longer called |
| `test.max_duration_s` | 3000 | Maximum duration of the test: beyond, the collection stops without writing anything |
| `test.max_unreachable_pct` | 30 | Maximum share of unreachable addresses |
| `test.max_changed_pct` | 25 | Maximum share of test results changed from one run to the next |
| `test.runs` | 8 | Runs kept for the observed availability |
| `test.min_runs` | 3 | Runs needed to give an observed availability |

The keys `mode`, `mots_interdits` and `fichiers_generes` of the same file are read by the publishing script, not by
the tool.

## Limits

- **Only the public-apis list is authoritative** for the entries. This repository is a copy of it, read every week.
- An API missing from the list is missing here: this repository does not look for APIs elsewhere.
- The test says whether a link responds, not whether the API delivers its service (see "What the test measures").
- The links are those of the list: a wrong link in the list gives a wrong test result.
- This repository says nothing about the quality, the security or the reliability of an API.

## Compatibility

Update tool: PHP 8.2, 8.3 and 8.4 (curl, json and mbstring extensions, no other library), tested by the continuous
integration. The data can be read with any language.

## Tests

```bash
composer install
composer test
```

The tests use real excerpts of the list and fake servers, without any network call, and check that `data/` follows
the schemas.

## Contributing

See [CONTRIBUTING.md](https://github.com/polygonlab-ai/.github/blob/main/CONTRIBUTING.md). Additions and corrections
of APIs go to the [public-apis repository](https://github.com/public-apis/public-apis), not here.

## Security

A vulnerability is reported in private, never in a public issue: see
[SECURITY.md](https://github.com/polygonlab-ai/.github/blob/main/SECURITY.md).

## License

The code, the schemas and the test results are under the MIT licence, Copyright (c) 2026 PolygonLab: see
[LICENSE](LICENSE). The list itself (names, links, descriptions, categories, declared values) comes from
public-apis, under the MIT licence, Copyright (c) 2022 public-apis: see
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
