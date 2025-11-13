<?php
function array_flatten(array $arr): array {
    $out = [];
    array_walk_recursive($arr, function($v) use (&$out) { $out[] = $v; });
    return $out;
}
