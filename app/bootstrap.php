<?php
set_include_path(
    implode(PATH_SEPARATOR, [
        APP_DIR . "thirdparty/Curl/include",
        APP_DIR . "include"
    ])
);

require_once APP_DIR . "composer/vendor/autoload.php";
require_once APP_DIR . "WebProxy.php";

$proxy = new WebProxy();
$proxy->handleRequest();
