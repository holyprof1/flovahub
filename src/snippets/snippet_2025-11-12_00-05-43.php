<?php
function str_slug(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = preg_replace('/[^a-zA-Z0-9]+/', '-', $s);
    return strtolower(trim($s, '-'));
}
