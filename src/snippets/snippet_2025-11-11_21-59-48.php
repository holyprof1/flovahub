<?php
/**
 * Utility: safe_json()
 */
function safe_json(array $data): string {
    return json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
