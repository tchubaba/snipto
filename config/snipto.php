<?php

declare(strict_types=1);

return [
    // Hard cap on raw file bytes accepted in file mode. The client enforces the
    // same number (injected into the page) BEFORE encrypting; the server enforces
    // cap + envelope slack on the PUT body, always, regardless of the client.
    'max_file_bytes'       => 50 * 1024 * 1024,
    'envelope_slack_bytes' => 64 * 1024,
];
