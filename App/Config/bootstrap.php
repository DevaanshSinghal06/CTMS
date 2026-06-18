<?php

define("ROOT_PATH", dirname(__DIR__, 2));
define("BASE_URL", "/Public");

require_once ROOT_PATH . "/App/Config/db.php";
require_once ROOT_PATH . "/App/Auth/auth.php";
require_once ROOT_PATH . "/App/Helpers/audit.php";
require_once ROOT_PATH . "/App/Helpers/study_code.php";
require_once ROOT_PATH . "/App/Helpers/csrf.php";
require_once ROOT_PATH . "/App/Helpers/subject_initials.php";