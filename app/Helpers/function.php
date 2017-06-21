<?php
function p($var, $die = true)
{
    echo '<pre>' . print_r($var, true) . '</pre>';
    $die && die;
}

function isWin()
{
    return strtoupper(substr(PHP_OS,0,3))==="WIN";
}