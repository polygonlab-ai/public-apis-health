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
